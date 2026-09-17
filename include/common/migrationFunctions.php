<?php
/**
 * migrationFunctions.php
 * Automatic database migration system.
 *
 * On every page load, compares $db_version / $shard_version against the
 * db_version table in each database and runs any pending migration files.
 */

/**
 * Get available migration files for a database type.
 *
 * @param string $db_type  'main' or 'shard'
 * @param string $base_dir Override migration directory (for child apps via APP_MIGRATION_DIR)
 * @return array  Sorted array of float version numbers
 */
function get_available_migrations($db_type, $base_dir = null) {
    if ($base_dir === null) {
        $base_dir = __DIR__ . '/../../db_migrations/';
    }

    $dir = rtrim($base_dir, '/') . '/' . $db_type . '/';
    if (!is_dir($dir)) return [];

    $versions = [];
    $files = glob($dir . '*.sql');
    foreach ($files as $file) {
        $basename = basename($file);
        if (preg_match('/^(\d+\.\d+)\.sql$/', $basename, $m)) {
            $versions[] = (float)$m[1];
        }
    }
    sort($versions);
    return $versions;
}

/**
 * Strip SQL comments (dash-dash, hash, slash-star block) outside quoted strings/identifiers.
 * Used only to DECIDE whether a fragment is skipped; the original fragment
 * (comments included) is what gets executed.
 */
function wn_migration_strip_comments($sql) {
    $out = '';
    $n = strlen($sql);
    $q = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $out .= $c;
            if ($c === '\\' && $q !== '`' && $i + 1 < $n) { $out .= $sql[++$i]; continue; }
            if ($c === $q) {
                if ($i + 1 < $n && $sql[$i + 1] === $q) { $out .= $sql[++$i]; continue; }
                $q = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $out .= $c; continue; }
        if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-'
            && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) {
            while ($i < $n && $sql[$i] !== "\n") $i++;
            $out .= "\n";
            continue;
        }
        if ($c === '#') {
            while ($i < $n && $sql[$i] !== "\n") $i++;
            $out .= "\n";
            continue;
        }
        if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = ($end === false) ? $n : $end + 1;
            $out .= ' ';
            continue;
        }
        $out .= $c;
    }
    return $out;
}

/**
 * Why run_migration() skips a split fragment, or null to execute it.
 *
 * Only a fragment that is, once comments are stripped, EXACTLY a transaction
 * control statement (START TRANSACTION / BEGIN / COMMIT / ROLLBACK, optional
 * WORK and semicolon, any whitespace/case) is skipped — the runner manages the
 * transaction itself. Comment-only fragments are skipped too (MySQL rejects an
 * empty query).
 *
 * Before core 5.0 this was a substring test on the RAW fragment, so any
 * statement whose comments or identifiers merely contained those words
 * (a column `committed_count`, a comment about implicit commits) was silently
 * dropped while db_version still advanced. core main/5.0 and the child-app
 * repair migrations re-apply what that dropped.
 *
 * @param string $stmt
 * @return string|null
 */
function wn_migration_skip_reason($stmt) {
    $plain = trim(wn_migration_strip_comments((string)$stmt));
    $plain = trim(rtrim($plain, ';'));
    if ($plain === '') return 'comment-only';
    if (preg_match('/^(START\s+TRANSACTION|BEGIN(\s+WORK)?|COMMIT(\s+WORK)?|ROLLBACK(\s+WORK)?)$/i', $plain, $m)) {
        return 'transaction control: ' . strtoupper(preg_replace('/\s+/', ' ', $m[1]));
    }
    return null;
}

/** One-line, length-capped statement text for logs. */
function wn_migration_snippet($stmt, $len = 120) {
    $s = preg_replace('/\s+/', ' ', trim(wn_migration_strip_comments((string)$stmt)));
    return strlen($s) > $len ? substr($s, 0, $len) . '...' : $s;
}

/**
 * Run a single migration file on a PDO connection.
 *
 * @param PDO    $conn
 * @param string $file     Full path to the SQL file
 * @param string $type     'main' or 'shard' (for logging)
 * @param float  $version
 * @return bool
 */
function run_migration($conn, $file, $type, $version) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        $_SESSION['error'] = "Migration $type/$version: cannot read file.";
        return false;
    }

    $statements = preg_split('/;\s*[\r\n]+/', $sql);

    // MySQL error codes we treat as "already applied" and skip, so partial
    // reruns of a migration can complete and bump db_version. Without this,
    // a migration that added some columns then failed leaves db_version
    // unbumped, and every subsequent request re-runs it forever.
    //   1050 = Table already exists
    //   1060 = Duplicate column name
    //   1061 = Duplicate key name
    //   1091 = Can't DROP (column/index/key doesn't exist)
    $idempotent_codes = ['1050', '1060', '1061', '1091'];

    try {
        $conn->beginTransaction();

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (!$stmt) continue;

            $skip = wn_migration_skip_reason($stmt);
            if ($skip !== null) {
                if ($skip !== 'comment-only') {
                    error_log("Migration $type/$version: skipping statement ($skip): " . wn_migration_snippet($stmt));
                }
                continue;
            }

            try {
                $conn->exec($stmt);
            } catch (PDOException $stmt_e) {
                $code = isset($stmt_e->errorInfo[1]) ? (string)$stmt_e->errorInfo[1] : '';
                if (in_array($code, $idempotent_codes, true)) {
                    error_log("Migration $type/$version: skipping already-applied statement (MySQL code $code)");
                    continue;
                }
                throw $stmt_e;
            }
        }

        // Update db_version table
        $conn->exec("UPDATE db_version SET version = $version WHERE version_id = 1");

        // DDL statements (CREATE TABLE, ALTER TABLE, DROP TABLE) cause an implicit
        // commit in MySQL, ending any active transaction. If that happened, commit()
        // would throw "There is no active transaction." This is expected — the DDL
        // already committed successfully, so we just move on.
        if ($conn->inTransaction()) {
            $conn->commit();
        }
        return true;

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $msg = "Migration $type/$version failed: " . $e->getMessage();
        $_SESSION['error'] = $msg;
        error_log($msg);

        // Transient connection-loss errors are not code defects:
        //   2006 = MySQL server has gone away
        //   2013 = Lost connection to MySQL server during query
        // db_version was not bumped, so the idempotent runner re-runs this
        // migration on the next request and completes. Don't escalate these to
        // the admin error_log DB, or the monitor spawns Fix: tasks for a
        // self-healing blip forever (still logged to PHP error_log above).
        $code = isset($e->errorInfo[1]) ? (string)$e->errorInfo[1] : '';
        $transient_conn_codes = ['2006', '2013'];

        // Log to admin error_log DB table as a system-level warning
        if (function_exists('log_error_to_db') && !in_array($code, $transient_conn_codes, true)) {
            log_error_to_db('WARNING', $msg, $file, 0, $e->getTraceAsString());
        }
        return false;
    }
}

/**
 * Get the current version from a database's db_version table.
 *
 * @param PDO $conn
 * @return float
 */
function get_current_db_version($conn) {
    try {
        $stmt = $conn->query("SELECT version FROM db_version WHERE version_id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float)$row['version'] : 0.0;
    } catch (PDOException $e) {
        // Table might not exist yet
        return 0.0;
    }
}

/**
 * Per-target-version filesystem flag indicating "migrations have been
 * confirmed up-to-date for this version on this host". Avoids opening
 * connections (especially shard primes) on every request just to read the
 * version row. Flag filename includes the target version, so bumping
 * $db_version / $shard_version invalidates automatically.
 *
 * Namespace by $dbInstance so admin instances on the same host (e.g. a
 * Docker dev box running multiple child apps) don't share flags.
 */
function _wn_migration_flag_path($scope, $target_version) {
    global $dbInstance;
    $instance = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($dbInstance ?? 'default'));
    $version_str = number_format((float)$target_version, 1, '.', '');
    return rtrim(sys_get_temp_dir(), '/') . "/wncore_migrated_{$instance}_{$scope}_v{$version_str}";
}

function _wn_migration_flag_exists($scope, $target_version) {
    static $cache = [];
    $key = "$scope/$target_version";
    if (isset($cache[$key])) return $cache[$key];
    return $cache[$key] = is_file(_wn_migration_flag_path($scope, $target_version));
}

function _wn_migration_flag_set($scope, $target_version) {
    @file_put_contents(_wn_migration_flag_path($scope, $target_version), (string)time());
}

/**
 * Check and migrate the main database.
 */
function check_and_migrate_main_db() {
    global $db, $db_version;

    if (_wn_migration_flag_exists('main', $db_version)) return;

    $current = get_current_db_version($db);
    if ($current >= $db_version) {
        wn_migration_clear_failure('main');
        _wn_migration_flag_set('main', $db_version);
        return;
    }

    // Determine migration directory
    $base_dir = defined('APP_MIGRATION_DIR') ? APP_MIGRATION_DIR : (__DIR__ . '/../../db_migrations/');

    // Stops at the first failure (a later file would otherwise bump db_version
    // past the failed one, and the ledger never re-runs it) and records it.
    $r = wn_migrate_pending($db, 'main', $current, $db_version, $base_dir, 'main', 'main');

    if ($r['reached']) {
        _wn_migration_flag_set('main', $db_version);
    }
}

/**
 * Flag scope for one shard. The scope is interpolated straight into a temp-file
 * path, so it must not contain a separator.
 */
function _wn_shard_flag_scope($shard_id) {
    return 'shard_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$shard_id);
}

/**
 * Check and migrate all shard databases.
 *
 * Gated per shard, and bounded per request.
 *
 * The original design used ONE flag for the whole set, so a $shard_version bump
 * meant the next request primed every configured shard just to read its version
 * row — significant connection pressure under load (max_connections incident
 * 2026-05-08). With two shards that was survivable; at the shard counts a
 * 1GB-per-database ceiling forces, a single unlucky request would open hundreds
 * of connections.
 *
 * So: each shard carries its own flag (a shard already at target costs nothing
 * on later requests — no connection, no version read), and each request touches
 * at most $budget un-flagged shards. The backlog drains across subsequent
 * requests, and cron/minutes/5/migrate_shards.php drains it promptly with an
 * unlimited budget so a newly provisioned shard never waits on user traffic.
 *
 * @param int|null $budget max shards to touch (0 = unlimited; null = default)
 */
function check_and_migrate_all_shards($budget = null) {
    global $shardConfigs, $shard_version;

    if (empty($shardConfigs)) return;
    if (_wn_migration_flag_exists('shards', $shard_version)) return;

    // Determine migration directory
    $base_dir = defined('APP_MIGRATION_DIR') ? APP_MIGRATION_DIR : (__DIR__ . '/../../db_migrations/');

    $available = get_available_migrations('shard', $base_dir);
    if (empty($available)) {
        _wn_migration_flag_set('shards', $shard_version);
        return;
    }

    $budget = ($budget === null)
        ? (defined('WN_SHARD_MIGRATE_BUDGET') ? (int)WN_SHARD_MIGRATE_BUDGET : 3)
        : (int)$budget;

    $spent         = 0;
    $all_at_target = true;

    foreach ($shardConfigs as $shard_id => $cfg) {
        $scope = _wn_shard_flag_scope($shard_id);
        if (_wn_migration_flag_exists($scope, $shard_version)) continue;

        // Budget is spent on CONNECTIONS, not just on migrations actually run —
        // reading a shard's version row is itself a connection, and that is the
        // cost this bound exists to cap.
        if ($budget > 0 && $spent >= $budget) { $all_at_target = false; break; }
        $spent++;

        $conn = prime_shard($shard_id);
        if (!$conn) { $all_at_target = false; continue; }

        $current = get_current_db_version($conn);
        if ($current >= $shard_version) {
            wn_migration_clear_failure($scope);
            _wn_migration_flag_set($scope, $shard_version);
            continue;
        }

        // Never skips past a failure; the failure is recorded under the shard's scope.
        $r = wn_migrate_pending($conn, 'shard', $current, $shard_version, $base_dir, "shard/$shard_id", $scope);

        if ($r['reached']) {
            _wn_migration_flag_set($scope, $shard_version);
        } else {
            $all_at_target = false;
        }
    }

    if ($all_at_target) _wn_migration_flag_set('shards', $shard_version);
}


// ─── SHARED LOOP + FAILURE RECORD ───────────────────────────────────────────

/**
 * Where the last failed migration for a scope is recorded. A small JSON file
 * beside the "migrated" flags (same temp dir, same $dbInstance namespace) so the
 * schema audit — which runs in the admin API on the same host — can report it
 * without a table of its own. Cleared once that scope reaches its target.
 */
function _wn_migration_fail_path($scope) {
    global $dbInstance;
    $instance = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($dbInstance ?? 'default'));
    $scope    = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$scope);
    return rtrim(sys_get_temp_dir(), '/') . "/wncore_migfail_{$instance}_{$scope}.json";
}

/** Record a failure: version, file, MySQL code, message (credentials stripped), time. */
function wn_migration_record_failure($scope, $label, $version, $file, $error, $code = '') {
    $error = preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", (string)$error);
    $rec = ['scope' => (string)$scope, 'migration' => $label . '/' . number_format((float)$version, 1, '.', ''),
            'version' => number_format((float)$version, 1, '.', ''), 'file' => basename((string)$file),
            'code' => (string)$code, 'error' => substr($error, 0, 500), 'at' => gmdate('c')];
    @file_put_contents(_wn_migration_fail_path($scope), json_encode($rec));
    return $rec;
}

function wn_migration_clear_failure($scope) {
    $p = _wn_migration_fail_path($scope);
    if (is_file($p)) @unlink($p);
}

/** The recorded failure for a scope, or null. */
function wn_migration_failure($scope) {
    $p = _wn_migration_fail_path($scope);
    if (!is_file($p)) return null;
    $j = json_decode((string)@file_get_contents($p), true);
    return is_array($j) ? $j : null;
}

/**
 * Optional pre-step sidecar: <version>.pre.sql beside <version>.sql, executed in
 * autocommit immediately before that file, only when that file is about to run.
 * For a migration that assumes an object which, historically, only PHP setup code
 * created (e.g. Primo Dollar main/1.7 alters primodollar_email_subscribers), so a
 * FRESH database can replay the set without rewriting an already-applied file.
 * Must be idempotent (CREATE TABLE IF NOT EXISTS …). Not a ledger entry: the
 * name does not match /^\d+\.\d+\.sql$/, so the older loops and the audit ignore it.
 *
 * @return true|array  true, or [error message, mysql code]
 */
function wn_migration_run_prestep($conn, $pre_file, $label, $version) {
    if (!is_file($pre_file)) return true;
    $sql = file_get_contents($pre_file);
    if ($sql === false) return ["Migration $label/$version pre-step: cannot read file", ''];
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || wn_migration_skip_reason($stmt) !== null) continue;
        try {
            $conn->exec($stmt);
        } catch (PDOException $e) {
            $code = isset($e->errorInfo[1]) ? (string)$e->errorInfo[1] : '';
            if (in_array($code, ['1050', '1060', '1061', '1091'], true)) continue;
            $msg = "Migration $label/$version pre-step failed: " . $e->getMessage();
            $_SESSION['error'] = $msg;
            error_log($msg);
            return [$msg, $code];
        }
    }
    return true;
}

/**
 * Run every migration file in ($current, $target] in version order, STOPPING at
 * the first failure. The ledger (db_version) is bumped per file by
 * run_migration(), so after a failure it stays at the last success and the
 * failed file re-runs on the next request (all files are idempotent-on-rerun via
 * run_migration's 1050/1060/1061/1091 handling). Also records a failure when the
 * files run out below $target (a version constant declared ahead of its file).
 *
 * @return array ['from','to','applied'=>[versions],'failed'=>record|null,'reached'=>bool]
 */
function wn_migrate_pending($conn, $db_type, $current, $target_version, $base_dir, $label, $scope) {
    $out = ['from' => (float)$current, 'to' => (float)$current, 'applied' => [], 'failed' => null, 'reached' => false];
    foreach (get_available_migrations($db_type, $base_dir) as $ver) {
        if ($ver <= $current) continue;
        if ($ver > $target_version) break;

        $file = rtrim($base_dir, '/') . '/' . $db_type . '/' . number_format($ver, 1, '.', '') . '.sql';
        if (!file_exists($file)) continue;

        $pre = wn_migration_run_prestep($conn, substr($file, 0, -4) . '.pre.sql', $label, $ver);
        if ($pre !== true) {
            $out['failed'] = wn_migration_record_failure($scope, $label, $ver, substr($file, 0, -4) . '.pre.sql', $pre[0], $pre[1]);
            error_log("Migration loop $label: pre-step for " . number_format($ver, 1, '.', '') . " failed — stopped");
            return $out;
        }
        if (!run_migration($conn, $file, $label, $ver)) {
            $err  = $_SESSION['error'] ?? "Migration $label/$ver failed";
            $code = preg_match('/SQLSTATE\[\w+\]: [^:]*: (\d+)/', (string)$err, $m) ? $m[1] : '';
            $out['failed'] = wn_migration_record_failure($scope, $label, $ver, $file, $err, $code);
            error_log("Migration loop $label: stopped at " . number_format($ver, 1, '.', '')
                . " — later migrations wait until it succeeds (ledger stays at " . number_format($out['to'], 1, '.', '') . ")");
            return $out;
        }
        $out['applied'][] = $ver;
        $out['to'] = $ver;
    }
    $now = get_current_db_version($conn);
    $out['to'] = $now;
    if ($now >= $target_version) {
        $out['reached'] = true;
        wn_migration_clear_failure($scope);
    } else {
        $out['failed'] = wn_migration_record_failure($scope, $label, $target_version, '',
            "Declared target " . number_format((float)$target_version, 1, '.', '') . " but no $db_type migration file reaches it (ledger "
            . number_format($now, 1, '.', '') . ")", 'no_file');
    }
    return $out;
}

// ─── CHILD-APP API ──────────────────────────────────────────────────────────
//
// The ONE child-app migration loop. Every child app's appMigrationFunctions.php
// wraps these (function_exists) and keeps a stop-on-failure fallback for an
// older core. Apps declare their targets once, in include/migration_versions.php
// ($child_db_version / $child_shard_version), which every bootstrap includes.

/**
 * Flag/failure scope for one child database. Keyed on the app's migrations
 * directory (realpath), not its slug: the schema audit discovers apps by
 * webroot directory and must derive the same key, and the slug and the
 * webroot can differ (ContactSwipe = contactswipe served from contactsweep/).
 */
function wn_child_migration_scope($base_dir, $db_type, $shard_id = null) {
    $real = realpath($base_dir);
    $key  = rtrim($real !== false ? $real : (string)$base_dir, '/');
    $s = 'child_' . substr(md5($key), 0, 12) . '_' . $db_type;
    if ($shard_id !== null) $s .= '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$shard_id);
    return $s;
}

/**
 * Migrate one child database ('main' or one shard) to $target_version.
 *
 * Idempotent and cheap at steady state: a per-scope, per-target temp flag skips
 * even the version read once the database is confirmed current.
 *
 * @param PDO    $conn
 * @param string $db_type         'main' or 'shard'
 * @param float  $target_version
 * @param string $base_dir        the app's db_migrations/ directory
 * @param array  $opts            shard_id (for shards), label
 * @return array  wn_migrate_pending() result, or ['status' => 'current']
 */
function wn_child_migrate($conn, $db_type, $target_version, $base_dir, $opts = []) {
    $shard_id = $opts['shard_id'] ?? null;
    $scope = wn_child_migration_scope($base_dir, $db_type, $shard_id);
    if (_wn_migration_flag_exists($scope, $target_version)) return ['status' => 'current', 'reached' => true, 'failed' => null];

    $current = get_current_db_version($conn);
    if ($current >= $target_version) {
        wn_migration_clear_failure($scope);
        _wn_migration_flag_set($scope, $target_version);
        return ['status' => 'current', 'reached' => true, 'failed' => null];
    }

    $label = $opts['label'] ?? ($shard_id !== null ? "child-shard/$shard_id" : "child-$db_type");
    $r = wn_migrate_pending($conn, $db_type, $current, $target_version, $base_dir, $label, $scope);
    if ($r['reached']) _wn_migration_flag_set($scope, $target_version);
    $r['status'] = $r['failed'] ? 'failed' : 'migrated';
    return $r;
}

/**
 * Migrate every configured child shard. Per-shard flags + a per-request
 * connection budget (WN_SHARD_MIGRATE_BUDGET, default 3; 0 = unlimited), same
 * reasoning as check_and_migrate_all_shards(). Each shard stops at its own
 * first failure; other shards still migrate.
 *
 * @param array    $shard_configs  [$shard_id => cfg] ($childShardConfigs)
 * @param callable $prime          fn($shard_id): PDO|false (child_prime_shard)
 * @return array   [$shard_id => result]
 */
function wn_child_migrate_shards($shard_configs, $prime, $target_version, $base_dir, $budget = null) {
    $results = [];
    if (empty($shard_configs)) return $results;

    $all_scope = wn_child_migration_scope($base_dir, 'shards');
    if (_wn_migration_flag_exists($all_scope, $target_version)) return $results;

    if (!get_available_migrations('shard', $base_dir)) {
        _wn_migration_flag_set($all_scope, $target_version);
        return $results;
    }

    $budget = ($budget === null)
        ? (defined('WN_SHARD_MIGRATE_BUDGET') ? (int)WN_SHARD_MIGRATE_BUDGET : 3)
        : (int)$budget;
    $spent = 0;
    $all_at_target = true;

    foreach ($shard_configs as $shard_id => $cfg) {
        $scope = wn_child_migration_scope($base_dir, 'shard', $shard_id);
        if (_wn_migration_flag_exists($scope, $target_version)) continue;

        if ($budget > 0 && $spent >= $budget) { $all_at_target = false; break; }
        $spent++;

        $conn = call_user_func($prime, $shard_id);
        if (!$conn) { $all_at_target = false; continue; }

        $results[$shard_id] = $r = wn_child_migrate($conn, 'shard', $target_version, $base_dir, ['shard_id' => $shard_id]);
        if (empty($r['reached'])) $all_at_target = false;
    }

    if ($all_at_target) _wn_migration_flag_set($all_scope, $target_version);
    return $results;
}

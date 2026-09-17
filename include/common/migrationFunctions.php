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
        _wn_migration_flag_set('main', $db_version);
        return;
    }

    // Determine migration directory
    $base_dir = defined('APP_MIGRATION_DIR') ? APP_MIGRATION_DIR : (__DIR__ . '/../../db_migrations/');

    $available = get_available_migrations('main', $base_dir);
    foreach ($available as $ver) {
        if ($ver <= $current) continue;
        if ($ver > $db_version) break;

        $file = rtrim($base_dir, '/') . '/main/' . number_format($ver, 1, '.', '') . '.sql';
        if (!file_exists($file)) continue;

        run_migration($db, $file, 'main', $ver);
    }

    if (get_current_db_version($db) >= $db_version) {
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
            _wn_migration_flag_set($scope, $shard_version);
            continue;
        }

        foreach ($available as $ver) {
            if ($ver <= $current) continue;
            if ($ver > $shard_version) break;

            $file = rtrim($base_dir, '/') . '/shard/' . number_format($ver, 1, '.', '') . '.sql';
            if (!file_exists($file)) continue;

            run_migration($conn, $file, "shard/$shard_id", $ver);
        }

        if (get_current_db_version($conn) >= $shard_version) {
            _wn_migration_flag_set($scope, $shard_version);
        } else {
            $all_at_target = false;
        }
    }

    if ($all_at_target) _wn_migration_flag_set('shards', $shard_version);
}

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

            $upper = strtoupper($stmt);
            if (strpos($upper, 'START TRANSACTION') !== false) continue;
            if (strpos($upper, 'COMMIT') !== false) continue;
            if (strpos($upper, 'ROLLBACK') !== false) continue;

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
 * Check and migrate all shard databases.
 *
 * Gated by a per-target-version flag so steady-state requests skip the
 * shard prime entirely. Pre-flag behavior was to prime every shard on
 * every request just to read its version row — significant connection
 * pressure under load (max_connections incident 2026-05-08).
 */
function check_and_migrate_all_shards() {
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

    $all_at_target = true;
    foreach ($shardConfigs as $shard_id => $cfg) {
        $conn = prime_shard($shard_id);
        if (!$conn) { $all_at_target = false; continue; }

        $current = get_current_db_version($conn);
        if ($current >= $shard_version) continue;

        foreach ($available as $ver) {
            if ($ver <= $current) continue;
            if ($ver > $shard_version) break;

            $file = rtrim($base_dir, '/') . '/shard/' . number_format($ver, 1, '.', '') . '.sql';
            if (!file_exists($file)) continue;

            run_migration($conn, $file, "shard/$shard_id", $ver);
        }

        if (get_current_db_version($conn) < $shard_version) $all_at_target = false;
    }

    if ($all_at_target) _wn_migration_flag_set('shards', $shard_version);
}

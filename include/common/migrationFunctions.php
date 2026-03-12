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

    try {
        $conn->beginTransaction();

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (!$stmt) continue;

            $upper = strtoupper($stmt);
            if (strpos($upper, 'START TRANSACTION') !== false) continue;
            if (strpos($upper, 'COMMIT') !== false) continue;
            if (strpos($upper, 'ROLLBACK') !== false) continue;

            $conn->exec($stmt);
        }

        // Update db_version table
        $conn->exec("UPDATE db_version SET version = $version WHERE version_id = 1");

        $conn->commit();
        return true;

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = "Migration $type/$version failed: " . $e->getMessage();
        error_log("Migration $type/$version failed: " . $e->getMessage());
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
 * Check and migrate the main database.
 */
function check_and_migrate_main_db() {
    global $db, $db_version;

    $current = get_current_db_version($db);
    if ($current >= $db_version) return;

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
}

/**
 * Check and migrate all shard databases.
 */
function check_and_migrate_all_shards() {
    global $shardConfigs, $shard_version;

    if (empty($shardConfigs)) return;

    // Determine migration directory
    $base_dir = defined('APP_MIGRATION_DIR') ? APP_MIGRATION_DIR : (__DIR__ . '/../../db_migrations/');

    $available = get_available_migrations('shard', $base_dir);
    if (empty($available)) return;

    foreach ($shardConfigs as $shard_id => $cfg) {
        $conn = prime_shard($shard_id);
        if (!$conn) continue;

        $current = get_current_db_version($conn);
        if ($current >= $shard_version) continue;

        foreach ($available as $ver) {
            if ($ver <= $current) continue;
            if ($ver > $shard_version) break;

            $file = rtrim($base_dir, '/') . '/shard/' . number_format($ver, 1, '.', '') . '.sql';
            if (!file_exists($file)) continue;

            run_migration($conn, $file, "shard/$shard_id", $ver);
        }
    }
}

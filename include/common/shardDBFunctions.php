<?php
/**
 * shardDBFunctions.php
 * Shard database connection management and query helpers.
 */

// Global array to hold open shard PDO connections
$shard_connections = [];

/**
 * Open (or reuse) a PDO connection to the given shard.
 * Must be called before any db_query_shard() call for that shard.
 *
 * @param string $shard_id  e.g. 'shard1', 'shard2'
 * @return PDO|false
 */
function prime_shard($shard_id) {
    global $shardConfigs, $shard_connections;

    // Already open
    if (isset($shard_connections[$shard_id])) {
        return $shard_connections[$shard_id];
    }

    if (!isset($shardConfigs[$shard_id])) {
        error_log("prime_shard: unknown shard '$shard_id'");
        return false;
    }

    $cfg = $shardConfigs[$shard_id];
    try {
        $conn = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
            $cfg['user'],
            $cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $shard_connections[$shard_id] = $conn;
        return $conn;
    } catch (PDOException $e) {
        error_log("prime_shard: connection failed for '$shard_id': " . $e->getMessage());
        return false;
    }
}

/**
 * Execute a query on a specific shard.
 *
 * @param string $shard_id
 * @param string $sql
 * @return PDOStatement|false
 */
function db_query_shard($shard_id, $sql) {
    global $shard_connections;

    if (!isset($shard_connections[$shard_id])) {
        prime_shard($shard_id);
    }

    if (!isset($shard_connections[$shard_id])) {
        $_SESSION['error'] = "Shard '$shard_id' is not available.";
        return false;
    }

    try {
        return $shard_connections[$shard_id]->query($sql);
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Shard query error: ' . $e->getMessage();
        return false;
    }
}

/**
 * Execute a prepared statement on a specific shard.
 *
 * @param string $shard_id
 * @param string $sql
 * @param array  $params
 * @return PDOStatement|false
 */
function db_query_shard_prepared($shard_id, $sql, $params = []) {
    global $shard_connections;

    if (!isset($shard_connections[$shard_id])) {
        prime_shard($shard_id);
    }

    if (!isset($shard_connections[$shard_id])) {
        $_SESSION['error'] = "Shard '$shard_id' is not available.";
        return false;
    }

    try {
        $stmt = $shard_connections[$shard_id]->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Shard query error: ' . $e->getMessage();
        return false;
    }
}

/**
 * Get the last insert ID from a shard connection.
 *
 * @param string $shard_id
 * @return string
 */
function shard_insert_id($shard_id) {
    global $shard_connections;
    if (!isset($shard_connections[$shard_id])) return '0';
    return $shard_connections[$shard_id]->lastInsertId();
}

<?php
/**
 * mysqlFunctions.php
 * PDO wrapper functions for the main database.
 */

/**
 * Execute a query on the main database.
 *
 * @param string $sql The SQL query
 * @return PDOStatement|false
 */
function db_query($sql) {
    global $db;
    try {
        $stmt = $db->query($sql);
        return $stmt;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        return false;
    }
}

/**
 * Execute a prepared statement on the main database.
 *
 * @param string $sql    The SQL with placeholders
 * @param array  $params The parameters to bind
 * @return PDOStatement|false
 */
function db_query_prepared($sql, $params = []) {
    global $db;
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        return false;
    }
}

/**
 * Get the last insert ID from the main database.
 *
 * @return string
 */
function db_insert_id() {
    global $db;
    return $db->lastInsertId();
}

/**
 * Get the last database error message.
 *
 * @return string
 */
function db_error() {
    global $db;
    $err = $db->errorInfo();
    return $err[2] ?? 'Unknown database error';
}

/**
 * Fetch a single row as associative array.
 *
 * @param PDOStatement $stmt
 * @return array|false
 */
function db_fetch($stmt) {
    if (!$stmt) return false;
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch all rows as associative arrays.
 *
 * @param PDOStatement $stmt
 * @return array
 */
function db_fetch_all($stmt) {
    if (!$stmt) return [];
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get the number of rows affected or returned.
 *
 * @param PDOStatement $stmt
 * @return int
 */
function db_num_rows($stmt) {
    if (!$stmt) return 0;
    return $stmt->rowCount();
}

<?php
/**
 * errorLogFunctions.php
 * Database-backed error logging and retrieval functions.
 */

/**
 * Log an error to the error_log DB table.
 * Uses direct PDO to avoid recursion if db_query() itself errors.
 * Falls back to error_log() if DB is unavailable.
 *
 * @param string $level   DEBUG|INFO|WARNING|ERROR|FATAL
 * @param string $message Error message
 * @param string $file    File where error occurred
 * @param int    $line    Line number
 * @param string $trace   Stack trace string
 */
function log_error_to_db($level, $message, $file = null, $line = null, $trace = null) {
    // Deduplication: skip if same error already logged this request
    static $_logged_hashes = [];
    $hash = md5($file . ':' . $line . ':' . $message);
    if (isset($_logged_hashes[$hash])) {
        return;
    }
    $_logged_hashes[$hash] = true;

    // Guard: need a DB connection
    if (!isset($GLOBALS['db'])) {
        error_log("[$level] $message in $file on line $line");
        return;
    }

    try {
        $db = $GLOBALS['db'];

        // Detect source app from file path
        $source_app = 'admin';
        if ($file) {
            // Match directory name after common webroot patterns
            if (preg_match('#[/\\\\]([a-zA-Z0-9_-]+)[/\\\\](?:include|views|app|api|auth|assets|actions|cron)#', $file, $m)) {
                $source_app = $m[1];
            }
        }

        // Current page
        $page = $_GET['page'] ?? $_REQUEST['page'] ?? null;

        // Build context JSON
        $context = [];
        // GET params
        if (!empty($_GET)) {
            $context['get'] = $_GET;
        }
        // POST action only (not full POST — could contain sensitive data)
        if (!empty($_POST['action'])) {
            $context['post_action'] = $_POST['action'];
        }
        // Session user info
        if (!empty($_SESSION['user_id'])) {
            $context['session'] = [
                'user_id'  => $_SESSION['user_id'],
                'email'    => $_SESSION['email'] ?? null,
                'shard_id' => $_SESSION['shard_id'] ?? null,
            ];
        }
        // Server/memory info
        $context['memory'] = [
            'usage'      => memory_get_usage(),
            'peak_usage' => memory_get_peak_usage(),
        ];
        $context['php_version'] = phpversion();

        $context_json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $stmt = $db->prepare(
            "INSERT INTO error_log
                (level, message, file, line, stack_trace, context_json, source_app, page,
                 request_uri, request_method, user_id, ip_address, user_agent, php_version, memory_usage)
             VALUES
                (:level, :message, :file, :line, :trace, :context, :source, :page,
                 :uri, :method, :uid, :ip, :ua, :phpver, :mem)"
        );

        $stmt->execute([
            ':level'   => $level,
            ':message' => mb_substr($message, 0, 65535),
            ':file'    => $file ? mb_substr($file, 0, 500) : null,
            ':line'    => $line,
            ':trace'   => $trace,
            ':context' => $context_json,
            ':source'  => mb_substr($source_app, 0, 50),
            ':page'    => $page ? mb_substr($page, 0, 100) : null,
            ':uri'     => isset($_SERVER['REQUEST_URI']) ? mb_substr($_SERVER['REQUEST_URI'], 0, 500) : null,
            ':method'  => $_SERVER['REQUEST_METHOD'] ?? null,
            ':uid'     => $_SESSION['user_id'] ?? null,
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'      => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            ':phpver'  => phpversion(),
            ':mem'     => memory_get_usage(),
        ]);
    } catch (\Throwable $e) {
        // DB logging failed — fall back to standard error_log
        error_log("[$level] $message in $file on line $line");
        error_log("Error log DB write failed: " . $e->getMessage());
    }
}

/**
 * Get paginated error log entries with optional filters.
 *
 * @param int   $page     Current page (1-based)
 * @param int   $per_page Items per page
 * @param array $filters  Optional: level, source_app, search, date_from, date_to
 * @return array ['items' => [...], 'total' => int]
 */
function get_error_logs_paginated($page = 1, $per_page = 50, $filters = []) {
    $where = '1=1';
    $params = [];

    if (!empty($filters['level'])) {
        $where .= ' AND level = :level';
        $params[':level'] = $filters['level'];
    }
    if (!empty($filters['source_app'])) {
        $where .= ' AND source_app = :source_app';
        $params[':source_app'] = $filters['source_app'];
    }
    if (!empty($filters['search'])) {
        $where .= ' AND (message LIKE :search OR file LIKE :search2)';
        $params[':search'] = '%' . $filters['search'] . '%';
        $params[':search2'] = '%' . $filters['search'] . '%';
    }
    if (isset($filters['status'])) {
        if ($filters['status'] === 'resolved') {
            $where .= ' AND resolved_at IS NOT NULL';
        } elseif ($filters['status'] === 'open') {
            $where .= ' AND resolved_at IS NULL';
        }
    }
    if (!empty($filters['date_from'])) {
        $where .= ' AND created >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where .= ' AND created <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }

    $offset = (int)(($page - 1) * $per_page);
    $limit  = (int)$per_page;

    // Count total
    $countStmt = db_query_prepared("SELECT COUNT(*) as cnt FROM error_log WHERE $where", $params);
    $total = (int)($countStmt ? db_fetch($countStmt)['cnt'] : 0);

    // Fetch page (LIMIT values injected as ints — safe, not user-controlled strings)
    $r = db_query_prepared(
        "SELECT * FROM error_log WHERE $where ORDER BY resolved_at IS NOT NULL ASC, created DESC LIMIT $offset, $limit",
        $params
    );
    $items = $r ? db_fetch_all($r) : [];

    return ['items' => $items, 'total' => $total];
}

/**
 * Get error log statistics for dashboard badges.
 *
 * @return array [errors_today, warnings_today, fatals_today, total]
 */
function get_error_log_stats() {
    $r = db_query("SELECT
        SUM(CASE WHEN level = 'ERROR' AND created >= CURDATE() THEN 1 ELSE 0 END) as errors_today,
        SUM(CASE WHEN level = 'WARNING' AND created >= CURDATE() THEN 1 ELSE 0 END) as warnings_today,
        SUM(CASE WHEN level = 'FATAL' AND created >= CURDATE() THEN 1 ELSE 0 END) as fatals_today,
        SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) as open,
        COUNT(*) as total
        FROM error_log");
    $row = $r ? db_fetch($r) : [];
    return [
        'errors_today'   => (int)($row['errors_today'] ?? 0),
        'warnings_today' => (int)($row['warnings_today'] ?? 0),
        'fatals_today'   => (int)($row['fatals_today'] ?? 0),
        'resolved'       => (int)($row['resolved'] ?? 0),
        'open'           => (int)($row['open'] ?? 0),
        'total'          => (int)($row['total'] ?? 0),
    ];
}

/**
 * Get distinct source apps from error log for filter dropdown.
 *
 * @return array List of source_app strings
 */
function get_error_log_sources() {
    $r = db_query("SELECT DISTINCT source_app FROM error_log ORDER BY source_app");
    return $r ? array_column(db_fetch_all($r), 'source_app') : [];
}

/**
 * Delete error log entries older than N days.
 *
 * @param int $older_than_days
 * @return int Number of deleted rows
 */
function clear_error_logs($older_than_days = 30) {
    $days = (int)$older_than_days;
    db_query("DELETE FROM error_log WHERE created < DATE_SUB(NOW(), INTERVAL $days DAY)");
    // PDO rowCount not reliable via db_query wrapper, use ROW_COUNT()
    $r = db_query("SELECT ROW_COUNT() as cnt");
    return $r ? (int)db_fetch($r)['cnt'] : 0;
}

/**
 * Mark an error log entry as resolved.
 *
 * @param int $error_id
 * @param int $user_id  The admin who resolved it
 * @return bool
 */
function resolve_error_log($error_id, $user_id) {
    $id  = (int)$error_id;
    $uid = (int)$user_id;
    return (bool)db_query("UPDATE error_log SET resolved_at = NOW(), resolved_by = '$uid' WHERE error_id = '$id'");
}

/**
 * Un-resolve an error log entry (reopen it).
 *
 * @param int $error_id
 * @return bool
 */
function unresolve_error_log($error_id) {
    $id = (int)$error_id;
    return (bool)db_query("UPDATE error_log SET resolved_at = NULL, resolved_by = NULL WHERE error_id = '$id'");
}

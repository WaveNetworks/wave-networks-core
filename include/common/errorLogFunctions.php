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
        // Device tracking
        if (!empty($_SESSION['device_id'])) {
            $context['device_id'] = (int)$_SESSION['device_id'];
        }
        // Server/memory info
        $context['memory'] = [
            'usage'      => memory_get_usage(),
            'peak_usage' => memory_get_peak_usage(),
        ];
        $context['php_version'] = phpversion();

        $context_json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        // Check for existing unresolved error with same hash — increment count instead of inserting
        $existing = $db->prepare(
            "SELECT error_id FROM error_log WHERE error_hash = :hash AND resolved_at IS NULL LIMIT 1"
        );
        $existing->execute([':hash' => $hash]);
        $existing_row = $existing->fetch(\PDO::FETCH_ASSOC);

        if ($existing_row) {
            $update = $db->prepare(
                "UPDATE error_log SET occurrence_count = occurrence_count + 1, last_seen_at = NOW() WHERE error_id = :id"
            );
            $update->execute([':id' => $existing_row['error_id']]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO error_log
                    (level, message, file, line, stack_trace, context_json, source_app, page,
                     request_uri, request_method, user_id, ip_address, user_agent, php_version,
                     memory_usage, occurrence_count, last_seen_at, error_hash)
                 VALUES
                    (:level, :message, :file, :line, :trace, :context, :source, :page,
                     :uri, :method, :uid, :ip, :ua, :phpver, :mem, 1, NOW(), :hash)"
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
                ':hash'    => $hash,
            ]);
        }
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

/**
 * Get error logs grouped by device, user, or IP with rollup summaries.
 *
 * @param string $group_by  'device', 'user', or 'ip'
 * @param array  $filters   Same filters as get_error_logs_paginated
 * @return array ['groups' => [...]]
 */
function get_error_logs_grouped($group_by = 'ip', $filters = []) {
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

    // Determine GROUP BY column and select expression
    switch ($group_by) {
        case 'device':
            $group_col = "CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.device_id')) AS UNSIGNED)";
            $group_alias = 'device_id';
            $where .= " AND JSON_EXTRACT(context_json, '$.device_id') IS NOT NULL";
            break;
        case 'user':
            $group_col = 'user_id';
            $group_alias = 'user_id';
            break;
        case 'ip':
        default:
            $group_col = 'ip_address';
            $group_alias = 'ip_address';
            break;
    }

    $sql = "SELECT
                $group_col AS group_key,
                COUNT(*) AS total_errors,
                SUM(CASE WHEN level = 'FATAL' THEN 1 ELSE 0 END) AS fatal_count,
                SUM(CASE WHEN level = 'ERROR' THEN 1 ELSE 0 END) AS error_count,
                SUM(CASE WHEN level = 'WARNING' THEN 1 ELSE 0 END) AS warning_count,
                SUM(CASE WHEN level = 'INFO' THEN 1 ELSE 0 END) AS info_count,
                SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_count,
                MIN(created) AS first_seen,
                MAX(created) AS last_seen,
                GROUP_CONCAT(DISTINCT source_app ORDER BY source_app SEPARATOR ', ') AS sources,
                GROUP_CONCAT(DISTINCT level ORDER BY level SEPARATOR ', ') AS levels
            FROM error_log
            WHERE $where
            GROUP BY $group_col
            ORDER BY MAX(created) DESC
            LIMIT 200";

    $r = db_query_prepared($sql, $params);
    $groups = $r ? db_fetch_all($r) : [];

    // For user grouping, fetch email addresses
    if ($group_by === 'user' && !empty($groups)) {
        $user_ids = array_filter(array_column($groups, 'group_key'));
        if ($user_ids) {
            $id_list = implode(',', array_map('intval', $user_ids));
            $ur = db_query("SELECT user_id, email FROM user WHERE user_id IN ($id_list)");
            $users = [];
            if ($ur) {
                foreach (db_fetch_all($ur) as $u) {
                    $users[$u['user_id']] = $u['email'];
                }
            }
            foreach ($groups as &$g) {
                $g['email'] = $users[$g['group_key']] ?? null;
            }
            unset($g);
        }
    }

    // For device grouping, fetch device info
    if ($group_by === 'device' && !empty($groups)) {
        $device_ids = array_filter(array_column($groups, 'group_key'));
        if ($device_ids) {
            $id_list = implode(',', array_map('intval', $device_ids));
            $dr = db_query("SELECT device_id, user_id, browser, last_used FROM device WHERE device_id IN ($id_list)");
            $devices = [];
            if ($dr) {
                foreach (db_fetch_all($dr) as $d) {
                    $devices[$d['device_id']] = $d;
                }
            }
            foreach ($groups as &$g) {
                $dev = $devices[$g['group_key']] ?? null;
                $g['device_user_id'] = $dev['user_id'] ?? null;
                $g['device_browser'] = $dev['browser'] ?? null;
                $g['device_last_used'] = $dev['last_used'] ?? null;
            }
            unset($g);
        }
    }

    return ['groups' => $groups, 'group_by' => $group_by];
}

/**
 * Get error log entries for a specific group (device, user, or IP).
 *
 * @param string $group_by   'device', 'user', or 'ip'
 * @param string $group_key  The group key value
 * @param array  $filters    Same filters as get_error_logs_paginated
 * @return array ['items' => [...], 'total' => int]
 */
function get_error_logs_for_group($group_by, $group_key, $filters = []) {
    // Add the group filter to existing filters
    switch ($group_by) {
        case 'device':
            $filters['device_id'] = (int)$group_key;
            break;
        case 'user':
            $filters['user_id'] = $group_key;
            break;
        case 'ip':
            $filters['ip_address'] = $group_key;
            break;
    }

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
    if (isset($filters['device_id'])) {
        $where .= " AND JSON_EXTRACT(context_json, '$.device_id') = :device_id";
        $params[':device_id'] = (int)$filters['device_id'];
    }
    if (isset($filters['user_id'])) {
        if ($filters['user_id'] === '' || $filters['user_id'] === null) {
            $where .= ' AND user_id IS NULL';
        } else {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }
    }
    if (isset($filters['ip_address'])) {
        if ($filters['ip_address'] === '' || $filters['ip_address'] === null) {
            $where .= ' AND ip_address IS NULL';
        } else {
            $where .= ' AND ip_address = :ip_address';
            $params[':ip_address'] = $filters['ip_address'];
        }
    }

    $r = db_query_prepared(
        "SELECT * FROM error_log WHERE $where ORDER BY created DESC LIMIT 100",
        $params
    );
    $items = $r ? db_fetch_all($r) : [];

    return ['items' => $items, 'total' => count($items)];
}

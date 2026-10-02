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

        // Record EVERY logged-in user who hit this error (the de-duplicated row
        // above keeps only the first). Cheap idempotent upsert, logged-in only,
        // in its own guard so a failure here never fails the request or the main
        // log. A fix can later credit/notify all of them via error_fixed_event.
        if (!empty($_SESSION['user_id'])) {
            try {
                $u = $db->prepare(
                    "INSERT INTO error_occurrence_user
                        (error_hash, user_id, first_seen_at, last_seen_at, occurrence_count)
                     VALUES (:hash, :uid, NOW(), NOW(), 1)
                     ON DUPLICATE KEY UPDATE
                        last_seen_at = NOW(),
                        occurrence_count = occurrence_count + 1"
                );
                $u->execute([':hash' => $hash, ':uid' => (int)$_SESSION['user_id']]);
            } catch (\Throwable $eu) {
                // Non-fatal: the de-duplicated error_log row is already written.
            }
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
    $row = ($r ? db_fetch($r) : null) ?: [];
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
 * @param int    $error_id
 * @param int    $user_id            The admin who resolved it
 * @param string $resolution_reason  One of: fixed, already_fixed, cant_fix, noise, wont_fix (optional)
 * @param string $resolution_notes   Free-text explanation (optional, max 500 chars)
 * @param string $resolution_ref     Link to the fix — a commit sha or task id (optional, max 255)
 * @return bool
 */
function resolve_error_log($error_id, $user_id, $resolution_reason = null, $resolution_notes = null, $resolution_ref = null) {
    $allowed = ['fixed', 'already_fixed', 'cant_fix', 'noise', 'wont_fix'];
    if ($resolution_reason !== null && !in_array($resolution_reason, $allowed, true)) {
        $resolution_reason = null;
    }
    if ($resolution_notes !== null) {
        $resolution_notes = mb_substr((string)$resolution_notes, 0, 500);
    }
    if ($resolution_ref !== null) {
        $resolution_ref = mb_substr(trim((string)$resolution_ref), 0, 255);
        if ($resolution_ref === '') { $resolution_ref = null; }
    }
    $ok = (bool)db_query_prepared(
        "UPDATE error_log
         SET resolved_at = NOW(),
             resolved_by = ?,
             resolution_reason = ?,
             resolution_notes = ?,
             resolution_ref = ?
         WHERE error_id = ?",
        [(int)$user_id, $resolution_reason, $resolution_notes, $resolution_ref, (int)$error_id]
    );

    // A genuine fix that carries a link to the fix is announced so child apps can
    // credit and notify everyone who hit it. noise/wont_fix/already_fixed/cant_fix,
    // or a 'fixed' with no ref, are NOT announced — nothing shipped to credit for.
    if ($ok && $resolution_reason === 'fixed' && $resolution_ref !== null) {
        record_error_fixed_event((int)$error_id);
    }
    return $ok;
}

/**
 * Announce that an error was fixed: snapshot the affected users and a privacy-safe
 * description (date + page, NEVER the stack trace) into error_fixed_event, which
 * child apps drain from their own cron to credit / notify those users.
 *
 * The resolving request is admin's and never loads a child app, so this mirrors the
 * user_deletion_event model: a durable queue polled via apiListFixedErrorsSince.
 * Idempotent per error_id (UNIQUE(error_id) + INSERT IGNORE).
 *
 * @param int $error_id
 * @return int event rows written (0 if already announced or no such error)
 */
function record_error_fixed_event($error_id) {
    $eid = (int)$error_id;
    if ($eid <= 0) { return 0; }
    try {
        $r = db_query_prepared(
            "SELECT error_hash, source_app, page, user_id, resolution_ref, resolved_at
               FROM error_log WHERE error_id = ?",
            [$eid]
        );
        $row = $r ? db_fetch($r) : null;
        if (!$row || empty($row['error_hash'])) { return 0; }
        $hash = $row['error_hash'];

        // Every logged-in user who ever hit this error, plus the one named on the
        // de-duplicated row itself (covers errors logged before this table existed).
        $ids = [];
        $ur = db_query_prepared(
            "SELECT user_id FROM error_occurrence_user WHERE error_hash = ?",
            [$hash]
        );
        while ($ur && ($u = db_fetch($ur))) { $ids[(int)$u['user_id']] = true; }
        if (!empty($row['user_id'])) { $ids[(int)$row['user_id']] = true; }
        unset($ids[0]);
        $user_ids = array_values(array_map('intval', array_keys($ids)));

        // The date a user last ran into it — the only time surface users are shown.
        $occurred_on = null;
        $lr = db_query_prepared(
            "SELECT MAX(last_seen_at) AS last FROM error_occurrence_user WHERE error_hash = ?",
            [$hash]
        );
        $lrow = $lr ? db_fetch($lr) : null;
        if ($lrow && !empty($lrow['last'])) { $occurred_on = substr((string)$lrow['last'], 0, 10); }

        $ins = db_query_prepared(
            "INSERT IGNORE INTO error_fixed_event
                (error_id, error_hash, source_app, page, occurred_on,
                 affected_user_ids, resolution_ref, resolved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $eid,
                $hash,
                $row['source_app'] ?: null,
                $row['page'] ?: null,
                $occurred_on,
                json_encode($user_ids),
                $row['resolution_ref'] ?: null,
                $row['resolved_at'] ?: date('Y-m-d H:i:s'),
            ]
        );
        return $ins ? $ins->rowCount() : 0;
    } catch (\Throwable $e) {
        error_log("record_error_fixed_event error_id=$eid: " . $e->getMessage());
        return 0;
    }
}

/**
 * Fixed-error announcements since a cursor, for a child app to credit/notify its
 * users. Privacy-safe projection only — date + page, never the stack trace.
 *
 * @param int    $since_event_id Return events with event_id greater than this (cursor)
 * @param string $source_app     Optional: only this app's errors
 * @param int    $limit          Max rows (1..500)
 * @return array rows with decoded affected_user_ids + a ready-to-show message
 */
function get_fixed_error_events_since($since_event_id = 0, $source_app = null, $limit = 100) {
    $since = max(0, (int)$since_event_id);
    $limit = max(1, min(500, (int)$limit));
    $where = 'event_id > ?';
    $params = [$since];
    if ($source_app !== null && $source_app !== '') {
        $where .= ' AND source_app = ?';
        $params[] = (string)$source_app;
    }
    $r = db_query_prepared(
        "SELECT event_id, error_id, error_hash, source_app, page, occurred_on,
                affected_user_ids, resolution_ref, resolved_at
           FROM error_fixed_event
          WHERE $where
          ORDER BY event_id ASC
          LIMIT $limit",
        $params
    );
    $rows = $r ? db_fetch_all($r) : [];
    foreach ($rows as &$row) {
        $uids = json_decode((string)($row['affected_user_ids'] ?? ''), true);
        $row['affected_user_ids'] = is_array($uids) ? array_values(array_map('intval', $uids)) : [];
        $row['affected_count'] = count($row['affected_user_ids']);
        // The only sentence a user is ever shown — no stack trace, file, or line.
        $where_txt = !empty($row['page']) ? ('on the ' . $row['page'] . ' page') : 'in the app';
        $when_txt  = !empty($row['occurred_on']) ? (' on ' . $row['occurred_on']) : '';
        $row['user_message'] = 'A problem you ran into' . $when_txt . ' ' . $where_txt . ' is now fixed.';
    }
    unset($row);
    return $rows;
}

/**
 * Un-resolve an error log entry (reopen it).
 *
 * @param int $error_id
 * @return bool
 */
function unresolve_error_log($error_id) {
    $id = (int)$error_id;
    return (bool)db_query("UPDATE error_log SET resolved_at = NULL, resolved_by = NULL, resolution_reason = NULL, resolution_notes = NULL WHERE error_id = '$id'");
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
    if (!empty($filters['date_from'])) {
        $where .= ' AND created >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where .= ' AND created <= :date_to';
        $params[':date_to'] = $filters['date_to'];
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
    if (!empty($filters['date_from'])) {
        $where .= ' AND created >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where .= ' AND created <= :date_to';
        $params[':date_to'] = $filters['date_to'];
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

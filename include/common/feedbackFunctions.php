<?php
/**
 * feedbackFunctions.php
 * Helper functions for the feedback & change request system.
 * Available to child apps via common.php include chain.
 */

/**
 * Detect the highest user role from session.
 * @return string
 */
function get_user_role_label() {
    if (!empty($_SESSION['is_owner']))    return 'owner';
    if (!empty($_SESSION['is_admin']))    return 'admin';
    if (!empty($_SESSION['is_manager']))  return 'manager';
    if (!empty($_SESSION['is_employee'])) return 'employee';
    return 'user';
}

/**
 * Detect source app from the current request URI.
 * @return string
 */
function detect_source_app_from_url() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/([^/]+)/(?:app|api|auth)/#', $uri, $m)) {
        return $m[1];
    }
    return 'admin';
}

// ─── Feedback Submission ────────────────────────────────────────────────────

/**
 * Submit a feedback entry.
 *
 * @param string $message  The feedback text
 * @param string $type     'bug', 'suggestion', 'general', or 'review'
 * @param array  $opts     Optional: source_app, page_url, context_json, user_id, user_role,
 *                         rating (1-5, only used when type='review')
 * @return int|false       feedback_id on success
 */
function submit_feedback($message, $type = 'general', $opts = []) {
    $valid_types = ['bug', 'suggestion', 'general', 'review'];
    if (!in_array($type, $valid_types)) {
        $type = 'general';
    }

    $s_type       = sanitize($type, SQL);
    $source_app   = $opts['source_app'] ?? detect_source_app_from_url();
    $s_source     = sanitize($source_app, SQL);
    $s_page       = isset($opts['page_url']) ? "'" . sanitize($opts['page_url'], SQL) . "'" : 'NULL';
    $s_message    = sanitize($message, SQL);
    $user_id      = isset($opts['user_id']) ? intval($opts['user_id']) : (isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null);
    $user_role    = sanitize($opts['user_role'] ?? get_user_role_label(), SQL);

    // Merge rating into context_json when type is review
    $ctx_data = [];
    if (!empty($opts['context_json'])) {
        $decoded = json_decode($opts['context_json'], true);
        if (is_array($decoded)) $ctx_data = $decoded;
    }
    if ($type === 'review' && isset($opts['rating'])) {
        $rating = max(1, min(5, intval($opts['rating'])));
        $ctx_data['rating'] = $rating;
    }
    $context_json = !empty($ctx_data)
        ? "'" . sanitize(json_encode($ctx_data), SQL) . "'"
        : 'NULL';

    $uid_col = $user_id !== null ? "'$user_id'" : 'NULL';

    $r = db_query("INSERT INTO feedback (feedback_type, source_app, page_url, user_id, user_role, message, context_json)
                    VALUES ('$s_type', '$s_source', $s_page, $uid_col, '$user_role', '$s_message', $context_json)");

    if (!$r) return false;
    $feedback_id = (int) db_insert_id();

    // Notify the user that their feedback was received
    if ($user_id !== null) {
        $type_label = $type === 'bug' ? 'bug report'
            : ($type === 'suggestion' ? 'suggestion'
            : ($type === 'review' ? 'review' : 'feedback'));
        _notify_feedback_user($user_id, 'feedback_received',
            'Thanks for your ' . $type_label . '!',
            'Your ' . $type_label . ' has been received and will be reviewed. We appreciate you taking the time to help improve the platform.',
            ['source_app' => $source_app]
        );
    }

    return $feedback_id;
}

// ─── Feedback Queries ───────────────────────────────────────────────────────

/**
 * Get feedback entries with filters and pagination.
 *
 * @param array $filters  Optional: feedback_type, source_app, user_id, status, search,
 *                        change_request_id, page, per_page
 * @return array ['items' => array, 'total' => int, 'page' => int, 'per_page' => int]
 */
function get_feedback_entries($filters = []) {
    $where = [];

    if (!empty($filters['feedback_type'])) {
        $s = sanitize($filters['feedback_type'], SQL);
        $where[] = "f.feedback_type = '$s'";
    }
    if (!empty($filters['source_app'])) {
        $s = sanitize($filters['source_app'], SQL);
        $where[] = "f.source_app = '$s'";
    }
    if (isset($filters['user_id']) && $filters['user_id'] !== '') {
        $uid = intval($filters['user_id']);
        $where[] = "f.user_id = '$uid'";
    }
    if (!empty($filters['status'])) {
        $s = sanitize($filters['status'], SQL);
        $where[] = "f.status = '$s'";
    }
    if (isset($filters['change_request_id']) && $filters['change_request_id'] !== '') {
        $crid = intval($filters['change_request_id']);
        $where[] = "f.change_request_id = '$crid'";
    }
    if (!empty($filters['search'])) {
        $s = sanitize($filters['search'], SQL);
        $where[] = "(f.message LIKE '%$s%' OR f.page_url LIKE '%$s%')";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $page     = max(1, intval($filters['page'] ?? 1));
    $per_page = max(1, min(100, intval($filters['per_page'] ?? 25)));
    $offset   = ($page - 1) * $per_page;

    $total = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM feedback f $whereSQL"))['cnt'];

    $r = db_query("SELECT f.*, u.email as user_email
                    FROM feedback f
                    LEFT JOIN user u ON u.user_id = f.user_id
                    $whereSQL
                    ORDER BY f.created DESC
                    LIMIT $offset, $per_page");
    $items = $r ? db_fetch_all($r) : [];

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/**
 * Get a single feedback entry by ID.
 *
 * @param int $feedback_id
 * @return array|null
 */
function get_feedback_by_id($feedback_id) {
    $id = intval($feedback_id);
    $r = db_query("SELECT f.*, u.email as user_email
                    FROM feedback f
                    LEFT JOIN user u ON u.user_id = f.user_id
                    WHERE f.feedback_id = '$id'");
    return $r ? db_fetch($r) : null;
}

/**
 * Get feedback statistics.
 *
 * @return array
 */
function get_feedback_stats() {
    $r = db_query("SELECT status, COUNT(*) as cnt FROM feedback GROUP BY status");
    $by_status = [];
    $total = 0;
    if ($r) {
        foreach (db_fetch_all($r) as $row) {
            $by_status[$row['status']] = (int) $row['cnt'];
            $total += (int) $row['cnt'];
        }
    }

    $r2 = db_query("SELECT feedback_type, COUNT(*) as cnt FROM feedback GROUP BY feedback_type");
    $by_type = [];
    if ($r2) {
        foreach (db_fetch_all($r2) as $row) {
            $by_type[$row['feedback_type']] = (int) $row['cnt'];
        }
    }

    return [
        'total'      => $total,
        'new'        => $by_status['new'] ?? 0,
        'reviewed'   => $by_status['reviewed'] ?? 0,
        'grouped'    => $by_status['grouped'] ?? 0,
        'dismissed'  => $by_status['dismissed'] ?? 0,
        'by_type'    => $by_type,
    ];
}

/**
 * Get distinct source apps from feedback.
 * @return array
 */
function get_feedback_source_apps() {
    $r = db_query("SELECT DISTINCT source_app FROM feedback ORDER BY source_app");
    if (!$r) return [];
    $apps = [];
    foreach (db_fetch_all($r) as $row) {
        $apps[] = $row['source_app'];
    }
    return $apps;
}

// ─── Upvoting ───────────────────────────────────────────────────────────────

/**
 * Toggle upvote on feedback. Inserts if not upvoted, deletes if already upvoted.
 *
 * @param int $feedback_id
 * @param int $user_id
 * @return array ['upvoted' => bool, 'upvotes' => int]
 */
function upvote_feedback($feedback_id, $user_id) {
    $fid = intval($feedback_id);
    $uid = intval($user_id);

    // Check if already upvoted
    $r = db_query("SELECT upvote_id FROM feedback_upvote WHERE feedback_id = '$fid' AND user_id = '$uid'");
    $existing = $r ? db_fetch($r) : null;

    if ($existing) {
        // Remove upvote
        db_query("DELETE FROM feedback_upvote WHERE upvote_id = '" . intval($existing['upvote_id']) . "'");
        db_query("UPDATE feedback SET upvotes = GREATEST(upvotes - 1, 0) WHERE feedback_id = '$fid'");
        $upvoted = false;
    } else {
        // Add upvote
        db_query("INSERT INTO feedback_upvote (feedback_id, user_id) VALUES ('$fid', '$uid')");
        db_query("UPDATE feedback SET upvotes = upvotes + 1 WHERE feedback_id = '$fid'");
        $upvoted = true;
    }

    $row = db_fetch(db_query("SELECT upvotes FROM feedback WHERE feedback_id = '$fid'"));
    return ['upvoted' => $upvoted, 'upvotes' => (int) ($row['upvotes'] ?? 0)];
}

/**
 * Get feedback IDs the user has upvoted.
 *
 * @param int $user_id
 * @return array of feedback_ids
 */
function get_user_upvotes($user_id) {
    $uid = intval($user_id);
    $r = db_query("SELECT feedback_id FROM feedback_upvote WHERE user_id = '$uid'");
    if (!$r) return [];
    $ids = [];
    foreach (db_fetch_all($r) as $row) {
        $ids[] = (int) $row['feedback_id'];
    }
    return $ids;
}

// ─── Change Requests ────────────────────────────────────────────────────────

/**
 * Create a change request.
 *
 * @param string $title
 * @param string $description
 * @param string $type        'change' or 'addition'
 * @param int    $created_by  User ID
 * @param array  $opts        Optional: priority, source_app, assigned_to
 * @return int|false          change_request_id
 */
function create_change_request($title, $description, $type, $created_by, $opts = []) {
    $valid_types = ['change', 'addition'];
    if (!in_array($type, $valid_types)) return false;

    $s_title    = sanitize($title, SQL);
    $s_desc     = sanitize($description, SQL);
    $s_type     = sanitize($type, SQL);
    $s_priority = sanitize($opts['priority'] ?? 'medium', SQL);
    $s_source   = isset($opts['source_app']) ? "'" . sanitize($opts['source_app'], SQL) . "'" : 'NULL';
    $s_assigned = isset($opts['assigned_to']) ? intval($opts['assigned_to']) : null;
    $created_by = intval($created_by);

    $assigned_col = $s_assigned !== null ? "'$s_assigned'" : 'NULL';

    $r = db_query("INSERT INTO change_request (title, description, request_type, priority, source_app, created_by, assigned_to)
                    VALUES ('$s_title', '$s_desc', '$s_type', '$s_priority', $s_source, '$created_by', $assigned_col)");

    if (!$r) return false;
    return (int) db_insert_id();
}

/**
 * Update a change request.
 *
 * @param int   $id     change_request_id
 * @param array $fields Fields to update (title, description, status, priority, assigned_to, request_type)
 * @return bool
 */
function update_change_request($id, $fields) {
    $id = intval($id);

    // Get current state before update (for status change notifications)
    $old_status = null;
    if (isset($fields['status'])) {
        $r = db_query("SELECT status, title, created_by, source_app FROM change_request WHERE change_request_id = '$id'");
        $old_cr = $r ? db_fetch($r) : null;
        $old_status = $old_cr['status'] ?? null;
    }

    $sets = [];

    $allowed = ['title', 'description', 'status', 'priority', 'assigned_to', 'request_type', 'source_app'];
    foreach ($allowed as $col) {
        if (isset($fields[$col])) {
            if ($col === 'assigned_to' && $fields[$col] === '') {
                $sets[] = "assigned_to = NULL";
            } else {
                $s = sanitize($fields[$col], SQL);
                $sets[] = "$col = '$s'";
            }
        }
    }

    // Auto-set completed_at when status changes to completed
    if (isset($fields['status']) && $fields['status'] === 'completed') {
        $sets[] = "completed_at = NOW()";
    } elseif (isset($fields['status']) && $fields['status'] !== 'completed') {
        $sets[] = "completed_at = NULL";
    }

    if (empty($sets)) return false;

    $result = (bool) db_query("UPDATE change_request SET " . implode(', ', $sets) . " WHERE change_request_id = '$id'");

    // Cascade to linked feedback so the submitter sees their item resolved
    // instead of stuck on 'grouped' forever. Status map:
    //   CR completed → feedback 'resolved'
    //   CR rejected  → feedback 'dismissed'
    //   CR back to in_progress/approved/proposed → feedback stays/reverts to 'grouped'
    if ($result && isset($fields['status']) && $old_status !== $fields['status']) {
        $new = $fields['status'];
        if ($new === 'completed') {
            db_query("UPDATE feedback SET status = 'resolved' WHERE change_request_id = '$id' AND status IN ('grouped','new','reviewed')");
        } elseif ($new === 'rejected') {
            db_query("UPDATE feedback SET status = 'dismissed' WHERE change_request_id = '$id' AND status IN ('grouped','new','reviewed')");
        } elseif (in_array($new, ['in_progress','approved','proposed','paused'], true)) {
            // Re-opening: pull resolved/dismissed feedback back to grouped so
            // notifications fire again if the CR cycles.
            db_query("UPDATE feedback SET status = 'grouped' WHERE change_request_id = '$id' AND status IN ('resolved','dismissed')");
        }
    }

    // Notify on status change
    if ($result && isset($fields['status']) && $old_status !== null && $old_status !== $fields['status']) {
        _notify_cr_status_change($id, $old_cr, $fields['status']);
    }

    return $result;
}

/**
 * Get change requests with filters and pagination.
 *
 * @param array $filters Optional: status, request_type, priority, search, page, per_page
 * @return array ['items' => array, 'total' => int, 'page' => int, 'per_page' => int]
 */
function get_change_requests($filters = []) {
    $where = [];

    if (!empty($filters['status'])) {
        $s = sanitize($filters['status'], SQL);
        $where[] = "cr.status = '$s'";
    }
    if (!empty($filters['exclude_status'])) {
        $s = sanitize($filters['exclude_status'], SQL);
        $where[] = "cr.status != '$s'";
    }
    if (!empty($filters['request_type'])) {
        $s = sanitize($filters['request_type'], SQL);
        $where[] = "cr.request_type = '$s'";
    }
    if (!empty($filters['priority'])) {
        $s = sanitize($filters['priority'], SQL);
        $where[] = "cr.priority = '$s'";
    }
    if (!empty($filters['search'])) {
        $s = sanitize($filters['search'], SQL);
        $where[] = "(cr.title LIKE '%$s%' OR cr.description LIKE '%$s%')";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $page     = max(1, intval($filters['page'] ?? 1));
    $per_page = max(1, min(100, intval($filters['per_page'] ?? 25)));
    $offset   = ($page - 1) * $per_page;

    $total = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM change_request cr $whereSQL"))['cnt'];

    $r = db_query("SELECT cr.*,
                           (SELECT COUNT(*) FROM feedback WHERE change_request_id = cr.change_request_id) as feedback_count
                    FROM change_request cr
                    $whereSQL
                    ORDER BY FIELD(cr.status, 'in_progress','approved','proposed','paused','rejected','completed'),
                             FIELD(cr.priority, 'critical','high','medium','low'),
                             cr.created DESC
                    LIMIT $offset, $per_page");
    $items = $r ? db_fetch_all($r) : [];

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/**
 * Get a single change request with grouped feedback.
 *
 * @param int $id
 * @return array|null
 */
function get_change_request_detail($id) {
    $id = intval($id);
    $r = db_query("SELECT cr.* FROM change_request cr WHERE cr.change_request_id = '$id'");
    $cr = $r ? db_fetch($r) : null;
    if (!$cr) return null;

    // Get grouped feedback
    $r2 = db_query("SELECT f.*, u.email as user_email
                     FROM feedback f
                     LEFT JOIN user u ON u.user_id = f.user_id
                     WHERE f.change_request_id = '$id'
                     ORDER BY f.upvotes DESC, f.created DESC");
    $cr['feedback'] = $r2 ? db_fetch_all($r2) : [];
    $cr['feedback_count'] = count($cr['feedback']);

    return $cr;
}

/**
 * Group feedback with a change request.
 *
 * @param int $feedback_id
 * @param int $change_request_id
 * @return bool
 */
function group_feedback_with_request($feedback_id, $change_request_id) {
    $fid  = intval($feedback_id);
    $crid = intval($change_request_id);
    $result = (bool) db_query("UPDATE feedback SET change_request_id = '$crid', status = 'grouped' WHERE feedback_id = '$fid'");

    // Notify the feedback author that their feedback led to a change request
    if ($result) {
        $feedback = get_feedback_by_id($fid);
        $cr = db_fetch(db_query("SELECT title FROM change_request WHERE change_request_id = '$crid'"));
        if ($feedback && !empty($feedback['user_id']) && $cr) {
            _notify_feedback_user((int)$feedback['user_id'], 'feedback_update',
                'Your feedback is being acted on',
                'Your feedback has been grouped into a change request: "' . $cr['title'] . '". We\'ll keep you updated on its progress.',
                ['source_app' => $feedback['source_app'] ?? null]
            );
        }
    }

    return $result;
}

/**
 * Ungroup feedback from a change request.
 *
 * @param int $feedback_id
 * @return bool
 */
function ungroup_feedback($feedback_id) {
    $fid = intval($feedback_id);
    return (bool) db_query("UPDATE feedback SET change_request_id = NULL, status = 'reviewed' WHERE feedback_id = '$fid'");
}

// ─── Notification Helpers ──────────────────────────────────────────────────

/**
 * Send a notification to a feedback/CR user. Looks up shard_id from user table.
 * Silently does nothing if user not found or notifications unavailable.
 *
 * @param int    $user_id
 * @param string $category_slug
 * @param string $title
 * @param string $body
 * @param array  $opts  Optional: source_app
 */
function _notify_feedback_user($user_id, $category_slug, $title, $body, $opts = []) {
    if (!function_exists('send_notification')) return;

    global $db;
    $uid = intval($user_id);
    $r = $db->query("SELECT shard_id FROM user WHERE user_id = '$uid'");
    $user = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
    if (!$user || empty($user['shard_id'])) return;

    send_notification($uid, $user['shard_id'], $category_slug, $title, $body, $opts);
}

/**
 * Notify relevant users when a change request status changes.
 * Notifies: the CR creator + all users whose feedback is grouped into this CR.
 *
 * @param int    $cr_id
 * @param array  $old_cr    Previous CR data (title, created_by, source_app)
 * @param string $new_status
 */
function _notify_cr_status_change($cr_id, $old_cr, $new_status) {
    $cr_id = intval($cr_id);
    $title = $old_cr['title'] ?? 'Change request';
    $source_app = $old_cr['source_app'] ?? null;

    $status_labels = [
        'proposed'    => 'proposed',
        'approved'    => 'approved',
        'in_progress' => 'now being worked on',
        'completed'   => 'completed',
        'paused'      => 'paused',
        'rejected'    => 'rejected',
    ];
    $status_label = $status_labels[$new_status] ?? $new_status;

    $notif_title = 'Change request ' . $status_label;
    $notif_body  = '"' . $title . '" has been ' . $status_label . '.';

    if ($new_status === 'completed') {
        $notif_body .= ' Thank you for helping improve the platform!';
    }

    // Collect unique user IDs to notify: CR creator + feedback authors
    $user_ids = [];

    if (!empty($old_cr['created_by'])) {
        $user_ids[(int)$old_cr['created_by']] = true;
    }

    $r = db_query("SELECT DISTINCT user_id FROM feedback WHERE change_request_id = '$cr_id' AND user_id IS NOT NULL");
    if ($r) {
        foreach (db_fetch_all($r) as $row) {
            $user_ids[(int)$row['user_id']] = true;
        }
    }

    $opts = [];
    if ($source_app) {
        $opts['source_app'] = $source_app;
    }

    foreach (array_keys($user_ids) as $uid) {
        _notify_feedback_user($uid, 'feedback_update', $notif_title, $notif_body, $opts);
    }
}

// ─── Category Registration (idempotent, runs at include time) ──────────────

if (function_exists('register_notification_category') && isset($db)) {
    register_notification_category('feedback_received', 'Feedback Received',
        'Confirmation when you submit feedback',
        ['icon' => 'bi-chat-dots', 'default_frequency' => 'realtime', 'created_by_app' => 'admin']
    );
    register_notification_category('feedback_update', 'Feedback Updates',
        'Updates when your feedback leads to changes or change requests are updated',
        ['icon' => 'bi-arrow-repeat', 'default_frequency' => 'realtime', 'created_by_app' => 'admin']
    );
}

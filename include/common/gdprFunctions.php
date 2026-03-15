<?php
/**
 * gdprFunctions.php
 * GDPR compliance helpers: consent tracking, data export, account deletion.
 * Auto-included via glob in common.php.
 */

// ── Consent tracking ─────────────────────────────────────────────────────────

/**
 * Record a consent event (grant or withdraw).
 */
function record_consent($user_id, $consent_type, $action, $version_id = null) {
    $s_uid   = (int) $user_id;
    $s_type  = sanitize($consent_type, SQL);
    $s_act   = sanitize($action, SQL);
    $ip      = sanitize($_SERVER['REMOTE_ADDR'] ?? '', SQL);
    $ua      = sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512), SQL);
    $vid     = $version_id ? (int) $version_id : 'NULL';

    db_query("INSERT INTO user_consent (user_id, consent_type, consent_version_id, action, ip_address, user_agent)
              VALUES ('$s_uid', '$s_type', $vid, '$s_act', '$ip', '$ua')");
}

/**
 * Get the latest consent action for a user + type.
 * Returns 'granted', 'withdrawn', or null (never consented).
 */
function get_consent_status($user_id, $consent_type) {
    $r = db_query_prepared(
        "SELECT action FROM user_consent WHERE user_id = ? AND consent_type = ? ORDER BY created DESC LIMIT 1",
        [(int)$user_id, $consent_type]
    );
    $row = db_fetch($r);
    return $row ? $row['action'] : null;
}

/**
 * Get all consent statuses for a user.
 * Returns ['consent_type' => 'granted'|'withdrawn', ...]
 */
function get_all_consent_statuses($user_id) {
    $r = db_query_prepared(
        "SELECT consent_type, action FROM user_consent
         WHERE user_id = ? AND consent_id IN (
             SELECT MAX(consent_id) FROM user_consent WHERE user_id = ? GROUP BY consent_type
         )",
        [(int)$user_id, (int)$user_id]
    );
    $statuses = [];
    while ($row = db_fetch($r)) {
        $statuses[$row['consent_type']] = $row['action'];
    }
    return $statuses;
}

/**
 * Get full consent history for a user (for audit/export).
 */
function get_consent_history($user_id) {
    $r = db_query_prepared(
        "SELECT uc.*, cv.version_label, cv.consent_type as cv_type
         FROM user_consent uc
         LEFT JOIN consent_version cv ON uc.consent_version_id = cv.version_id
         WHERE uc.user_id = ?
         ORDER BY uc.created DESC",
        [(int)$user_id]
    );
    $rows = [];
    while ($row = db_fetch($r)) { $rows[] = $row; }
    return $rows;
}

/**
 * Get the latest version for a consent type.
 */
function get_latest_consent_version($consent_type) {
    $r = db_query_prepared(
        "SELECT * FROM consent_version WHERE consent_type = ? ORDER BY effective_date DESC, version_id DESC LIMIT 1",
        [$consent_type]
    );
    return db_fetch($r) ?: null;
}

/**
 * Get all consent types and their latest versions.
 */
function get_all_consent_versions() {
    $r = db_query(
        "SELECT cv1.* FROM consent_version cv1
         INNER JOIN (
             SELECT consent_type, MAX(version_id) as max_id FROM consent_version GROUP BY consent_type
         ) cv2 ON cv1.version_id = cv2.max_id
         ORDER BY cv1.consent_type"
    );
    $versions = [];
    while ($row = db_fetch($r)) { $versions[$row['consent_type']] = $row; }
    return $versions;
}

// ── Account deletion ─────────────────────────────────────────────────────────

/**
 * Request account deletion with 30-day cooling-off period.
 * Returns the request_id or false if one is already pending.
 */
function request_account_deletion($user_id, $reason = '') {
    // Check for existing pending request
    $r = db_query_prepared(
        "SELECT request_id FROM account_deletion_request WHERE user_id = ? AND status = 'pending'",
        [(int)$user_id]
    );
    if (db_fetch($r)) { return false; }

    $s_uid    = (int) $user_id;
    $s_reason = sanitize($reason, SQL);

    db_query("INSERT INTO account_deletion_request (user_id, reason, status, requested_at, cancel_before)
              VALUES ('$s_uid', '$s_reason', 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");

    return db_insert_id();
}

/**
 * Cancel a pending deletion request.
 */
function cancel_account_deletion($user_id) {
    db_query_prepared(
        "UPDATE account_deletion_request SET status = 'cancelled', cancelled_at = NOW()
         WHERE user_id = ? AND status = 'pending'",
        [(int)$user_id]
    );
}

/**
 * Get the active (pending) deletion request for a user.
 */
function get_pending_deletion($user_id) {
    $r = db_query_prepared(
        "SELECT * FROM account_deletion_request WHERE user_id = ? AND status = 'pending' LIMIT 1",
        [(int)$user_id]
    );
    return db_fetch($r) ?: null;
}

// ── Data export ──────────────────────────────────────────────────────────────

/**
 * Request a data export. Returns export_id or false if one is already pending/processing.
 */
function request_data_export($user_id, $format = 'json') {
    // Check for existing active request
    $r = db_query_prepared(
        "SELECT export_id FROM data_export_request WHERE user_id = ? AND status IN ('pending','processing')",
        [(int)$user_id]
    );
    if (db_fetch($r)) { return false; }

    $s_uid = (int) $user_id;
    $s_fmt = sanitize($format, SQL);

    db_query("INSERT INTO data_export_request (user_id, format, status, requested_at)
              VALUES ('$s_uid', '$s_fmt', 'pending', NOW())");

    return db_insert_id();
}

/**
 * Get the latest data export request for a user.
 */
function get_latest_export($user_id) {
    $r = db_query_prepared(
        "SELECT * FROM data_export_request WHERE user_id = ? ORDER BY requested_at DESC LIMIT 1",
        [(int)$user_id]
    );
    return db_fetch($r) ?: null;
}

/**
 * Build and store a user's data export package.
 * Collects from admin main, admin shard, and returns data array.
 * Child apps should extend this by adding their own data.
 */
function build_export_data($user_id, $shard_id) {
    $uid = (int) $user_id;

    // Admin main: user record (excluding password hash)
    $user = get_user($uid);
    unset($user['password']);

    // Admin shard: user profile
    $profile = get_user_profile($uid, $shard_id) ?: [];

    // Consent history
    $consent = get_consent_history($uid);

    // Notifications (if function exists)
    $notifications = [];
    if (function_exists('get_user_notifications_all')) {
        $notifications = get_user_notifications_all($uid, $shard_id);
    }

    // Devices / sessions
    $r = db_query_prepared("SELECT device_id, browser, ip_address, created, last_used FROM device WHERE user_id = ?", [$uid]);
    $devices = [];
    while ($row = db_fetch($r)) { $devices[] = $row; }

    return [
        'exported_at' => date('c'),
        'user' => $user,
        'profile' => $profile,
        'consent_history' => $consent,
        'devices' => $devices,
        'notifications' => $notifications,
    ];
}

/**
 * Mark an export as ready with file path and expiry (7 days).
 */
function complete_data_export($export_id, $file_path, $file_size) {
    $s_eid  = (int) $export_id;
    $s_path = sanitize($file_path, SQL);
    $s_size = (int) $file_size;

    db_query("UPDATE data_export_request SET
        status = 'ready',
        completed_at = NOW(),
        file_path = '$s_path',
        file_size = '$s_size',
        expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
        WHERE export_id = '$s_eid'");
}

<?php
/**
 * loginHistoryFunctions.php
 * Login history tracking and retrieval.
 * Auto-included via glob in common.php / common_auth.php.
 */

/**
 * Record a login event.
 *
 * @param int    $user_id
 * @param string $method   'password', 'oauth', 'remember_me', 'saml', '2fa'
 * @param string $status   'success' or 'failed'
 */
function record_login($user_id, $method = 'password', $status = 'success') {
    $s_uid    = (int) $user_id;
    $s_method = sanitize($method, SQL);
    $s_status = sanitize($status, SQL);
    $ip       = sanitize($_SERVER['REMOTE_ADDR'] ?? '', SQL);
    $ua       = sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512), SQL);
    $browser  = sanitize(parse_browser_name($_SERVER['HTTP_USER_AGENT'] ?? ''), SQL);

    db_query("INSERT INTO login_history (user_id, ip_address, user_agent, browser, login_method, status)
              VALUES ('$s_uid', '$ip', '$ua', '$browser', '$s_method', '$s_status')");
}

/**
 * Get paginated login history for a user.
 *
 * @param int $user_id
 * @param int $limit
 * @param int $offset
 * @return array
 */
function get_login_history($user_id, $limit = 20, $offset = 0) {
    $uid    = (int) $user_id;
    $limit  = (int) $limit;
    $offset = (int) $offset;

    $r = db_query("SELECT * FROM login_history WHERE user_id = '$uid' ORDER BY created DESC LIMIT $offset, $limit");
    $rows = [];
    while ($row = db_fetch($r)) { $rows[] = $row; }
    return $rows;
}

/**
 * Count total login history entries for a user.
 */
function count_login_history($user_id) {
    $uid = (int) $user_id;
    $r = db_query("SELECT COUNT(*) as cnt FROM login_history WHERE user_id = '$uid'");
    $row = db_fetch($r);
    return $row ? (int)$row['cnt'] : 0;
}

/**
 * Check if user needs to re-consent to updated policies.
 * Returns array of consent_types that need re-consent, or empty array if all good.
 *
 * @param int $user_id
 * @return array  e.g. ['terms_of_service' => ['version_id' => 3, 'version_label' => '2.0', ...], ...]
 */
function check_reconsent_needed($user_id) {
    $uid = (int) $user_id;
    $required_types = ['terms_of_service', 'privacy_policy'];
    $needs_reconsent = [];

    foreach ($required_types as $type) {
        $latest_version = get_latest_consent_version($type);
        if (!$latest_version) continue;

        // Get user's latest consent for this type
        $r = db_query_prepared(
            "SELECT consent_version_id FROM user_consent
             WHERE user_id = ? AND consent_type = ? AND action = 'granted'
             ORDER BY created DESC LIMIT 1",
            [$uid, $type]
        );
        $user_consent = db_fetch($r);

        if (!$user_consent) {
            // Never consented
            $needs_reconsent[$type] = $latest_version;
        } elseif ((int)$user_consent['consent_version_id'] < (int)$latest_version['version_id']) {
            // Consented to an older version
            $needs_reconsent[$type] = $latest_version;
        }
    }

    return $needs_reconsent;
}

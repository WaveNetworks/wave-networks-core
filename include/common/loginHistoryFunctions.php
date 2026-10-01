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

    // Test accounts (is_test_account=1) are exempt from re-consent so automated
    // test runs do not break when consent_version is bumped. Real PII never goes
    // against test accounts; the exemption is safe.
    $r_test = db_query_prepared("SELECT is_test_account FROM user WHERE user_id = ?", [$uid]);
    $test_row = db_fetch($r_test);
    if ($test_row && (int)$test_row['is_test_account'] === 1) {
        return [];
    }

    // The legal documents decide (legalFunctions.php): owed when never accepted, or when a
    // version published with "requires re-acceptance" has taken effect since the newest
    // one this user accepted. A minor edit published without it asks nobody again.
    return function_exists('wn_legal_pending') ? wn_legal_pending($uid) : [];
}

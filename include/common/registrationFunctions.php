<?php
/**
 * registrationFunctions.php
 * Open registration, in two halves, shared by every door into it.
 *
 * The web form (loginActions.php `register`) and the bundled mobile client
 * (mobileAuthActions.php `deviceRegister`) create accounts through these two functions,
 * so there is ONE definition of what a valid sign-up is and ONE definition of what an
 * account is made of (user row, shard profile, home dir, consent, experiment claim,
 * confirmation email). A second copy would drift, and the drift would be a hole in every
 * app on the host.
 *
 * It is split in two on purpose. Each door puts its own bot gate BETWEEN the halves:
 * reCAPTCHA on the web, rate limits + honeypot on a device (reCAPTCHA cannot run on an
 * app:// origin). The gate sits before the "already registered" check so neither door
 * answers "that email has an account" to a caller who has not passed it.
 */

/** registration_mode from auth_settings: 'open' | 'confirm' | 'invite' | 'closed'. */
function wn_registration_mode() {
    $settings = db_fetch(db_query("SELECT registration_mode FROM auth_settings WHERE setting_id = 1"));
    return $settings['registration_mode'] ?? 'open';
}

/**
 * Validate a sign-up. Returns the error map ([] when valid), keyed exactly as the web
 * form always keyed them. Does NOT check whether the email is taken — see the file header.
 *
 * $in: email, password, confirm_password, agree_terms
 */
function wn_registration_validate(array $in, $mode) {
    $errs = array();

    if ($mode === 'closed') {
        $errs['mode'] = 'Registration is currently closed.';
    }

    if (!valid_email($in['email'] ?? ''))                        { $errs['email'] = 'Valid email is required.'; }
    if (!valid_password($in['password'] ?? ''))                   { $errs['password'] = 'Password must be at least 8 characters.'; }
    if (($in['password'] ?? '') !== ($in['confirm_password'] ?? '')) { $errs['confirm'] = 'Passwords do not match.'; }
    if (empty($in['agree_terms']))                                { $errs['terms'] = 'You must agree to the Terms of Service and Privacy Policy.'; }

    return $errs;
}

/**
 * Create the account. Call only after wn_registration_validate() passed and the caller's
 * bot gate passed.
 *
 * $in: email, password, first_name, last_name
 * @return array ['errors' => [...]] on failure, or
 *               ['user_id' => int, 'needs_confirm' => bool, 'email' => string]
 */
function wn_registration_create(array $in, $mode) {
    $email      = trim($in['email'] ?? '');
    $first_name = trim($in['first_name'] ?? '');
    $last_name  = trim($in['last_name'] ?? '');

    if (get_user_by_email($email)) {
        return ['errors' => ['email' => 'An account with this email already exists.']];
    }

    $hashed      = hash_password($in['password'] ?? '');
    $shard_id    = get_least_loaded_shard();
    $confirmHash = generateHashCode(100);

    $needs_confirm = ($mode === 'confirm');
    $is_confirmed  = $needs_confirm ? 0 : 1;

    $r = db_query("INSERT INTO user (email, password, shard_id, is_confirmed, confirm_hash, confirm_hash_created, created_date)
                    VALUES ('" . sanitize($email, SQL) . "', '$hashed', '$shard_id', '$is_confirmed', '$confirmHash', NOW(), NOW())");

    if (!$r) {
        return ['errors' => ['db' => db_error()]];
    }

    $new_id = db_insert_id();

    // Create profile on shard
    prime_shard($shard_id);
    db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                    VALUES ('$new_id', '" . sanitize($first_name, SQL) . "', '" . sanitize($last_name, SQL) . "', NOW())");

    // Create homedir
    $_SESSION['shard_id'] = $shard_id;
    create_home_dir_id($new_id);
    unset($_SESSION['shard_id']);

    // Record consent for Terms of Service and Privacy Policy
    if (function_exists('record_consent')) {
        $tos_ver = get_latest_consent_version('terms_of_service');
        $pp_ver  = get_latest_consent_version('privacy_policy');
        record_consent($new_id, 'terms_of_service', 'granted', $tos_ver ? (int)$tos_ver['version_id'] : null);
        record_consent($new_id, 'privacy_policy', 'granted', $pp_ver ? (int)$pp_ver['version_id'] : null);
    }

    // Claim anonymous A/B experiment assignments to the new user (Task #795)
    // so this device's pre-register variant exposure links to the user_id.
    if (function_exists('claim_experiment_assignments') && function_exists('current_device_id')) {
        $cdid = current_device_id();
        if ($cdid) { claim_experiment_assignments($cdid, (int)$new_id); }
    }

    if ($needs_confirm) {
        send_confirmation_email($email, $confirmHash);
    }

    return ['user_id' => (int)$new_id, 'needs_confirm' => $needs_confirm, 'email' => $email];
}

// ─── Device sign-up throttle ─────────────────────────────────────────────────
// reCAPTCHA's stand-in for bundled clients. Every attempt that gets past the honeypot is
// counted — failures included, or "email already exists" becomes a free enumeration
// oracle. Only hashes are kept (no raw IP, no raw device id), and rows age out after two
// days. Times are written by PHP in UTC and compared against a PHP-computed cutoff, so the
// check does not depend on the MySQL session zone.

/** [window seconds, max attempts] per key. */
function wn_device_register_limits() {
    return [
        'ip'     => [[3600, 5], [86400, 20]],
        'device' => [[3600, 3], [86400, 10]],
        // Site-wide ceiling: a botnet rotating IPs and device ids still hits this.
        'all'    => [[3600, 60]],
    ];
}

function wn_device_register_ensure_schema() {
    db_query("CREATE TABLE IF NOT EXISTS `device_register_attempt` (
        `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ip_hash` CHAR(64) NOT NULL,
        `device_hash` CHAR(64) NULL DEFAULT NULL,
        `created` DATETIME NOT NULL,
        PRIMARY KEY (`attempt_id`),
        KEY `idx_ip` (`ip_hash`, `created`),
        KEY `idx_device` (`device_hash`, `created`),
        KEY `idx_created` (`created`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Check the limits for this caller and, if under them, record the attempt.
 * @return int 0 when allowed, otherwise the number of seconds to wait.
 */
function wn_device_register_throttle($ip, $device) {
    $ip_hash     = hash('sha256', 'wn-reg-ip|' . $ip);
    $device_hash = $device !== '' ? hash('sha256', 'wn-reg-dev|' . $device) : null;
    $now         = time();

    $count = function ($where, $window) use ($now) {
        $since = gmdate('Y-m-d H:i:s', $now - $window);
        $r = db_query("SELECT COUNT(*) AS n FROM device_register_attempt WHERE $where AND created > '$since'");
        if ($r === false) { return false; }
        $row = db_fetch($r);
        return (int)($row['n'] ?? 0);
    };

    // A core that has not run migration 5.2 yet (an app mid-redeploy) must not fail open
    // or fatal: create the table and carry on.
    // db_query() reports a failure through $_SESSION['error'], which would ride out in the
    // response as if the caller had done something wrong — so put it back afterwards.
    $prior_error = $_SESSION['error'] ?? null;
    if ($count('1=1', 60) === false) {
        wn_device_register_ensure_schema();
        $_SESSION['error'] = $prior_error;
    }

    $keys = ['ip' => "ip_hash = '$ip_hash'", 'all' => '1=1'];
    if ($device_hash) { $keys['device'] = "device_hash = '$device_hash'"; }

    foreach (wn_device_register_limits() as $key => $rules) {
        if (!isset($keys[$key])) { continue; }
        foreach ($rules as [$window, $max]) {
            $n = $count($keys[$key], $window);
            if ($n !== false && $n >= $max) {
                return $window;
            }
        }
    }

    $dh = $device_hash ? "'$device_hash'" : 'NULL';
    db_query("INSERT INTO device_register_attempt (ip_hash, device_hash, created)
              VALUES ('$ip_hash', $dh, '" . gmdate('Y-m-d H:i:s', $now) . "')");

    // Housekeeping: nothing older than the longest window is ever read again.
    if (mt_rand(1, 20) === 1) {
        db_query("DELETE FROM device_register_attempt WHERE created < '" . gmdate('Y-m-d H:i:s', $now - 2 * 86400) . "'");
    }

    return 0;
}

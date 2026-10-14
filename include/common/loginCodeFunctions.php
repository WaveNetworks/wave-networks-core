<?php
/**
 * loginCodeFunctions.php
 * "Email me a sign-in code" — sign in without a password (ContactSwipe task #2389).
 *
 * A 6-digit code, emailed through the core mailer, valid 10 minutes, single use,
 * stored only as a password_hash(). At most 5 tries per code; requests are rate
 * limited per email and per IP. The reply never says whether an account exists.
 * The code replaces the PASSWORD only: an account with 2FA on still goes to 2fa.php.
 *
 * OPT-IN PER APP: the auth page that offers it defines WN_LOGIN_CODE before
 * including its bootstrap's auth template, or the app's include/common_auth.php
 * does — see wn_login_code_enabled(). Core's own admin login does not offer it.
 *
 * Tables: login_code (main/5.7).
 */

const WN_LOGIN_CODE_TTL_MIN      = 10;
const WN_LOGIN_CODE_MAX_TRIES    = 5;
const WN_LOGIN_CODE_PER_EMAIL_HR = 5;
const WN_LOGIN_CODE_PER_IP_HR    = 20;

function wn_login_code_enabled() {
    return defined('WN_LOGIN_CODE') && WN_LOGIN_CODE;
}

/** The one generic reply for a request, account or not. */
function wn_login_code_sent_message() {
    return 'If that email has an account, we sent it a sign-in code. It works for '
         . WN_LOGIN_CODE_TTL_MIN . ' minutes.';
}

/**
 * Handle "email me a code" for $email. Returns '' when the generic reply should be
 * shown (sent, or no such account), or an error string (rate limited / bad input).
 */
function wn_login_code_request($email) {
    $email = strtolower(trim($email));
    if (!valid_email($email)) { return 'Please enter a valid email address.'; }

    $s_email = sanitize($email, SQL);
    $ip      = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $s_ip    = sanitize($ip, SQL);

    $by_email = db_fetch(db_query("SELECT COUNT(*) AS n FROM login_code WHERE email = '$s_email' AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)"));
    $by_ip    = db_fetch(db_query("SELECT COUNT(*) AS n FROM login_code WHERE ip_address = '$s_ip' AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)"));
    if ((int)($by_email['n'] ?? 0) >= WN_LOGIN_CODE_PER_EMAIL_HR || (int)($by_ip['n'] ?? 0) >= WN_LOGIN_CODE_PER_IP_HR) {
        return 'Too many sign-in codes requested. Please wait a while and try again.';
    }

    $user = get_user_by_email($email);
    $uid  = $user ? (int)$user['user_id'] : 0;
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // A new code retires every earlier one for this address.
    db_query("UPDATE login_code SET used = 1 WHERE email = '$s_email' AND used = 0");

    $s_hash = sanitize(password_hash($code, PASSWORD_DEFAULT), SQL);
    $s_ua   = sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512), SQL);
    $ttl    = (int)WN_LOGIN_CODE_TTL_MIN;
    db_query("INSERT INTO login_code (email, user_id, code_hash, ip_address, user_agent, created, expires)
              VALUES ('$s_email', '$uid', '$s_hash', '$s_ip', '$s_ua', NOW(), DATE_ADD(NOW(), INTERVAL $ttl MINUTE))");

    // Unknown address: the row above keeps the rate limit and the reply identical; nothing is sent.
    if ($uid) {
        wn_login_code_send_email($email, $code);
    }
    return '';
}

/**
 * Check $code for $email. Returns ['user' => row] on success (the code is spent),
 * or ['error' => message]. Only the newest live code counts, and each wrong try
 * uses up one of its WN_LOGIN_CODE_MAX_TRIES.
 */
function wn_login_code_verify($email, $code) {
    $generic = 'That code is wrong or has expired. Request a new one.';
    $email   = strtolower(trim($email));
    $code    = preg_replace('/\D/', '', (string)$code);
    if (!valid_email($email) || strlen($code) !== 6) { return ['error' => $generic]; }

    $s_email = sanitize($email, SQL);
    $row = db_fetch(db_query("SELECT * FROM login_code WHERE email = '$s_email' AND used = 0 AND expires > NOW()
                              ORDER BY login_code_id DESC LIMIT 1"));
    if (!$row || (int)$row['attempts'] >= WN_LOGIN_CODE_MAX_TRIES) {
        return ['error' => $generic];
    }

    $id = (int)$row['login_code_id'];
    if (!password_verify($code, $row['code_hash']) || !(int)$row['user_id']) {
        db_query("UPDATE login_code SET attempts = attempts + 1 WHERE login_code_id = '$id'");
        if (!empty($row['user_id']) && function_exists('record_login')) {
            record_login((int)$row['user_id'], 'email_code', 'failed');
        }
        $left = WN_LOGIN_CODE_MAX_TRIES - (int)$row['attempts'] - 1;
        return ['error' => $left > 0 ? 'That code is not right. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.' : $generic];
    }

    // Spend it atomically: a second tab racing the same code gets nothing.
    $spent = db_query("UPDATE login_code SET used = 1 WHERE login_code_id = '$id' AND used = 0");
    if (!$spent || $spent->rowCount() < 1) { return ['error' => $generic]; }

    $user = get_user((int)$row['user_id']);
    if (!$user) { return ['error' => $generic]; }
    return ['user' => $user];
}

/** The email: the code, a one-tap link, who asked and when, "if this wasn't you". */
function wn_login_code_send_email($email, $code) {
    $b        = function_exists('get_branding') ? get_branding() : [];
    $site     = $b['site_name'] ?? 'your account';
    $base     = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/auth/x.php')), '/');
    $link     = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')
              . $base . '/auth/code.php?e=' . rawurlencode($email) . '&c=' . $code;
    $browser  = function_exists('parse_browser_name') ? parse_browser_name($_SERVER['HTTP_USER_AGENT'] ?? '') : '';
    $who      = trim(($browser && $browser !== 'Other' ? $browser . ' ' : 'a browser ') . 'at IP address ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $when     = gmdate('j M Y, H:i') . ' UTC';
    $e        = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $body = '<h2>Your sign-in code</h2>'
          . '<p style="font-size:28px;letter-spacing:6px;font-weight:bold;margin:16px 0">' . $e($code) . '</p>'
          . '<p>Enter it on the sign-in page, or tap the button to sign in on this device:</p>'
          . '<p><a href="' . $e($link) . '" style="display:inline-block;padding:10px 18px;background:#0d6efd;color:#fff;border-radius:6px;text-decoration:none">Sign in to ' . $e($site) . '</a></p>'
          . '<p>The code works once, for ' . WN_LOGIN_CODE_TTL_MIN . ' minutes.</p>'
          . '<p style="color:#666">Asked for from ' . $e($who) . ' on ' . $e($when) . '.<br>'
          . 'If this wasn\'t you, ignore this email. Nobody can sign in without the code.</p>';

    $text = "Your " . $site . " sign-in code: " . $code . "\n\n"
          . "Enter it on the sign-in page, or open this link on this device:\n" . $link . "\n\n"
          . "The code works once, for " . WN_LOGIN_CODE_TTL_MIN . " minutes.\n\n"
          . "Asked for from " . $who . " on " . $when . ".\n"
          . "If this wasn't you, ignore this email. Nobody can sign in without the code.\n";

    $subject = $code . ' is your ' . $site . ' sign-in code';
    if (function_exists('send_email_now')) {
        return send_email_now($email, '', $subject, $body, ['source_app' => 'core', 'alt_body' => $text]);
    }
    return send_email($email, '', $subject, $body);
}

/**
 * Finish a password-less sign-in exactly like the password path in loginActions:
 * 2FA if the account has it, then session, device, history, re-consent, home.
 * Never returns.
 */
function wn_login_code_complete($user) {
    wn_login_finish($user, 'email_code', true);
}

/** Shared tail of the password-less sign-ins ($method = login_history.login_method). Never returns. */
function wn_login_finish($user, $method, $two_factor = true) {
    // "Continue with Google" for an email that already had an account waits on this code (task #2461).
    $linked = function_exists('oauth_apply_pending_link') && oauth_apply_pending_link($user, $method);

    if ($two_factor && !empty($user['totp_enabled'])) {
        if ($linked || strpos((string)$method, 'oauth_') === 0) { $_SESSION['oauth_device_2fa'] = true; }
        $_SESSION['2fa_pending'] = true;
        $_SESSION['2fa_user_id'] = $user['user_id'];
        header('Location: 2fa.php');
        exit;
    }

    // The code arrived in their inbox, which is all confirming an email ever proved.
    if (empty($user['is_confirmed'])) {
        $uid = (int)$user['user_id'];
        db_query("UPDATE user SET is_confirmed = 1, confirm_hash = NULL WHERE user_id = '$uid'");
    }

    // Started from the phone app (task #2461): the sign-in goes back to the app, not into this browser.
    if (($linked || strpos((string)$method, 'oauth_') === 0) && function_exists('oauth_device_pending') && oauth_device_pending()) {
        oauth_device_handoff($user, $method);
    }

    load_user_session($user);
    if (function_exists('get_or_create_device')) { get_or_create_device(); }
    if (function_exists('record_login')) { record_login($user['user_id'], $method, 'success'); }

    if (function_exists('check_reconsent_needed')) {
        $reconsent = function_exists('wn_legal_gate_needed') ? wn_legal_gate_needed($user['user_id']) : check_reconsent_needed($user['user_id']);
        if (!empty($reconsent)) {
            $_SESSION['reconsent_needed'] = $reconsent;
            header('Location: consent.php');
            exit;
        }
    }

    $_SESSION['success'] = 'Welcome back!';
    header('Location: ' . (function_exists('get_post_login_home') ? get_post_login_home() : '../app/'));
    exit;
}

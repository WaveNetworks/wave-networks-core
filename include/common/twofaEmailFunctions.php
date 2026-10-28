<?php
/**
 * twofaEmailFunctions.php
 * "Can't use any of these?" — a code to your VERIFIED email as the LAST-RESORT second
 * factor (ContactSwipe task #2660, part 2 of #2658). Without it, a lost phone meant a
 * locked-out account.
 *
 * Same mechanics as the emailed sign-in code (loginCodeFunctions.php): 6 digits, valid
 * 10 minutes, single use, stored only as a password_hash(), 5 tries per code, rate
 * limited per account and per IP. A separate purpose and table (twofa_email_code,
 * main/6.8), so neither code can ever stand in for the other.
 *
 * It replaces the SECOND factor only:
 *   - the first step must have been the password (or, later, a passkey). Signed in with
 *     the emailed sign-in code? Then the email cannot ALSO be the second step: two
 *     emailed codes are one factor. wn_2fa_email_refusal() says so and offers the fix.
 *   - only to an address the account has verified (user.is_confirmed).
 * Every use: a notice email, a push/notification to the user's devices, a login_history
 * row (login_method '2fa_email'), and the sign-in lands on "Review your sign-in methods"
 * when the app names that page (WN_2FA_REVIEW_URL).
 *
 * OPT-IN PER APP, like WN_LOGIN_CODE: the app's include/common_auth.php defines
 * WN_2FA_EMAIL. Core's own admin login does not offer it.
 *
 * Session: $_SESSION['2fa_first_factor'] is set by every path that sends a user to
 * 2fa.php (loginActions password → 'password', wn_login_finish → its method, SAML → 'saml').
 */

const WN_2FA_EMAIL_TTL_MIN     = 10;
const WN_2FA_EMAIL_MAX_TRIES   = 5;
const WN_2FA_EMAIL_PER_USER_HR = 5;
const WN_2FA_EMAIL_PER_IP_HR   = 20;

function wn_2fa_email_enabled() {
    return defined('WN_2FA_EMAIL') && WN_2FA_EMAIL;
}

/** The first steps an emailed code may complete. Anything else needs a real factor. */
function wn_2fa_email_first_factors() {
    return ['password', 'passkey'];
}

/** What the email stands in for, as a person reads it ("your authenticator app"). */
function wn_2fa_email_factor_name($user) {
    return 'authenticator app';
}

/** "j•••@example.com": enough to know which inbox, not enough to learn an address. */
function wn_2fa_email_masked($email) {
    $email = (string)$email;
    $at = strpos($email, '@');
    if ($at === false || $at < 1) { return $email; }
    return substr($email, 0, 1) . str_repeat('•', max(1, min(6, $at - 1))) . substr($email, $at);
}

/**
 * Why the email fallback can't be used for this sign-in, or '' when it can.
 * $first_factor = how the first step was done ($_SESSION['2fa_first_factor']).
 */
function wn_2fa_email_refusal($user, $first_factor) {
    if (!wn_2fa_email_enabled()) {
        return 'Email codes are not offered here.';
    }
    if (!$user || empty($user['email']) || empty($user['is_confirmed'])) {
        return 'Your email address is not verified, so a code can\'t be sent to it.';
    }
    if (!in_array((string)$first_factor, wn_2fa_email_first_factors(), true)) {
        if ($first_factor === 'email_code') {
            return 'You signed in with a code from your email, so your email can\'t also be your second step. '
                 . 'Use your ' . wn_2fa_email_factor_name($user) . ', or go back and sign in with your password.';
        }
        return 'An email code can only finish a sign-in that started with your password. '
             . 'Go back and sign in with your password.';
    }
    return '';
}

/**
 * Send a code to the user's verified email. Returns '' when sent, or an error string
 * (refused, rate limited). Never sends to an unverified address.
 */
function wn_2fa_email_request($user, $first_factor) {
    $why = wn_2fa_email_refusal($user, $first_factor);
    if ($why !== '') { return $why; }

    $uid  = (int)$user['user_id'];
    $ip   = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $s_ip = sanitize($ip, SQL);
    $by_user = db_fetch(db_query("SELECT COUNT(*) AS n FROM twofa_email_code WHERE user_id = '$uid' AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)"));
    $by_ip   = db_fetch(db_query("SELECT COUNT(*) AS n FROM twofa_email_code WHERE ip_address = '$s_ip' AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)"));
    if ((int)($by_user['n'] ?? 0) >= WN_2FA_EMAIL_PER_USER_HR || (int)($by_ip['n'] ?? 0) >= WN_2FA_EMAIL_PER_IP_HR) {
        return 'Too many email codes requested. Please wait a while and try again.';
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // A new code retires every earlier one for this account.
    db_query("UPDATE twofa_email_code SET used = 1 WHERE user_id = '$uid' AND used = 0");
    $s_hash = sanitize(password_hash($code, PASSWORD_DEFAULT), SQL);
    $s_ua   = sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512), SQL);
    $ttl    = (int)WN_2FA_EMAIL_TTL_MIN;
    db_query("INSERT INTO twofa_email_code (user_id, code_hash, ip_address, user_agent, created, expires)
              VALUES ('$uid', '$s_hash', '$s_ip', '$s_ua', NOW(), DATE_ADD(NOW(), INTERVAL $ttl MINUTE))");

    wn_2fa_email_send_code($user, $code);
    return '';
}

/**
 * Check $code for $user_id. Returns ['ok' => true] (the code is spent) or ['error' => msg].
 * Only the newest live code counts; each wrong try uses one of its WN_2FA_EMAIL_MAX_TRIES.
 */
function wn_2fa_email_verify($user_id, $code) {
    $generic = 'That code is wrong or has expired. Send a new one.';
    $uid  = (int)$user_id;
    $code = preg_replace('/\D/', '', (string)$code);
    if ($uid <= 0 || strlen($code) !== 6) { return ['error' => $generic]; }

    $row = db_fetch(db_query("SELECT * FROM twofa_email_code WHERE user_id = '$uid' AND used = 0 AND expires > NOW()
                              ORDER BY twofa_email_code_id DESC LIMIT 1"));
    if (!$row || (int)$row['attempts'] >= WN_2FA_EMAIL_MAX_TRIES) { return ['error' => $generic]; }

    $id = (int)$row['twofa_email_code_id'];
    if (!password_verify($code, $row['code_hash'])) {
        db_query("UPDATE twofa_email_code SET attempts = attempts + 1 WHERE twofa_email_code_id = '$id'");
        if (function_exists('record_login')) { record_login($uid, '2fa_email', 'failed'); }
        $left = WN_2FA_EMAIL_MAX_TRIES - (int)$row['attempts'] - 1;
        return ['error' => $left > 0 ? 'That code is not right. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.' : $generic];
    }

    // Spend it atomically: a second tab racing the same code gets nothing.
    $spent = db_query("UPDATE twofa_email_code SET used = 1 WHERE twofa_email_code_id = '$id' AND used = 0");
    if (!$spent || $spent->rowCount() < 1) { return ['error' => $generic]; }
    return ['ok' => true];
}

function wn_2fa_email_who() {
    $browser = function_exists('parse_browser_name') ? parse_browser_name($_SERVER['HTTP_USER_AGENT'] ?? '') : '';
    return trim(($browser && $browser !== 'Other' ? $browser . ' ' : 'a browser ') . 'at IP address ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/** The code email. No sign-in link: this is the second step, the password page already ran. */
function wn_2fa_email_send_code($user, $code) {
    $b    = function_exists('get_branding') ? get_branding() : [];
    $site = $b['site_name'] ?? 'your account';
    $e    = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $who  = wn_2fa_email_who();
    $when = gmdate('j M Y, H:i') . ' UTC';
    $factor = wn_2fa_email_factor_name($user);

    $body = '<h2>Your code to finish signing in</h2>'
          . '<p style="font-size:28px;letter-spacing:6px;font-weight:bold;margin:16px 0">' . $e($code) . '</p>'
          . '<p>Someone signed in to ' . $e($site) . ' with your password and asked to use this email instead of your ' . $e($factor) . '. '
          . 'The code works once, for ' . WN_2FA_EMAIL_TTL_MIN . ' minutes.</p>'
          . '<p style="color:#666">Asked for from ' . $e($who) . ' on ' . $e($when) . '.<br>'
          . 'If this wasn\'t you, someone knows your password: don\'t share this code, and change your password.</p>';
    $text = "Your code to finish signing in to " . $site . ": " . $code . "\n\n"
          . "Someone signed in with your password and asked to use this email instead of your " . $factor . ".\n"
          . "The code works once, for " . WN_2FA_EMAIL_TTL_MIN . " minutes.\n\n"
          . "Asked for from " . $who . " on " . $when . ".\n"
          . "If this wasn't you, someone knows your password: don't share this code, and change your password.\n";
    $subject = $code . ' is your ' . $site . ' code to finish signing in';
    if (function_exists('send_email_now')) {
        return send_email_now($user['email'], '', $subject, $body, ['source_app' => 'core', 'alt_body' => $text]);
    }
    return send_email($user['email'], '', $subject, $body);
}

/** After a sign-in finished with an email code: the notice email + a notification to every device. */
function wn_2fa_email_used_notice($user) {
    $b      = function_exists('get_branding') ? get_branding() : [];
    $site   = $b['site_name'] ?? 'your account';
    $factor = wn_2fa_email_factor_name($user);
    $who    = wn_2fa_email_who();
    $when   = gmdate('j M Y, H:i') . ' UTC';
    $review = wn_2fa_email_review_url(true);
    $e      = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $line   = 'Your account was signed into using an email code instead of your ' . $factor . '.';

    $body = '<h2>' . $e($line) . '</h2>'
          . '<p>From ' . $e($who) . ' on ' . $e($when) . '.</p>'
          . '<p>If you lost your ' . $e($factor) . ', set up a new one so you don\'t need your email next time.'
          . ($review !== '' ? ' <a href="' . $e($review) . '">Review your sign-in methods</a>.' : '') . '</p>'
          . '<p style="color:#666">If this wasn\'t you, change your password now and sign out of all sessions.</p>';
    $text = $line . "\n\nFrom " . $who . " on " . $when . ".\n\n"
          . "If you lost your " . $factor . ", set up a new one so you don't need your email next time."
          . ($review !== '' ? "\nReview your sign-in methods: " . $review : '') . "\n\n"
          . "If this wasn't you, change your password now and sign out of all sessions.\n";
    $subject = 'Signed in to ' . $site . ' with an email code';
    if (function_exists('send_email_now')) {
        send_email_now($user['email'], '', $subject, $body, ['source_app' => 'core', 'alt_body' => $text]);
    } elseif (function_exists('send_email')) {
        send_email($user['email'], '', $subject, $body);
    }
    if (function_exists('send_notification')) {
        send_notification((int)$user['user_id'], $user['shard_id'], 'security', 'Signed in with an email code',
            $line . ' ' . $who . ', ' . gmdate('H:i') . ' UTC. If that was not you, change your password.',
            array_filter(['source_app' => 'core', 'action_url' => wn_2fa_email_review_url(false) ?: null,
                          'action_label' => 'Review your sign-in methods']));
    }
}

/**
 * The app's "sign-in methods" page (WN_2FA_REVIEW_URL, relative to auth/), or ''.
 * $absolute = for an email, resolved against this request's host.
 */
function wn_2fa_email_review_url($absolute) {
    if (!defined('WN_2FA_REVIEW_URL') || !WN_2FA_REVIEW_URL) { return ''; }
    $rel = (string)WN_2FA_REVIEW_URL;
    if (!$absolute || preg_match('#^https?://#', $rel)) { return $rel; }
    $auth = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/auth/x.php'), '/');      // /app/auth
    $path = $auth . '/' . $rel;
    // Resolve ../ segments so the link reads cleanly.
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') { array_pop($out); } elseif ($seg !== '.') { $out[] = $seg; }
    }
    return (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . implode('/', $out);
}

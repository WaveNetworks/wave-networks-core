<?php
/**
 * oauthFunctions.php
 * OAuth 2.0 redirect and callback helpers for Google, GitHub, Facebook.
 */

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Facebook;

/**
 * Get an OAuth2 provider instance.
 *
 * @param string $provider_name  'google', 'github', 'facebook'
 * @return \League\OAuth2\Client\Provider\AbstractProvider|false
 */
function oauth_provider($provider_name) {
    global $google_client_id, $google_client_secret;
    global $github_client_id, $github_client_secret;
    global $facebook_app_id, $facebook_app_secret;

    // Admin › OAuth providers is where an owner pastes the client (secret sealed at rest);
    // config/env globals stay as the fallback. Before this the saved row was never read.
    $row = oauth_provider_row($provider_name);
    if ($row && !empty($row['client_id'])) {
        $secret = function_exists('wn_secret_open') ? wn_secret_open($row['client_secret']) : $row['client_secret'];
        if ($provider_name === 'google')   { $google_client_id = $row['client_id'];   $google_client_secret = $secret; }
        if ($provider_name === 'github')   { $github_client_id = $row['client_id'];   $github_client_secret = $secret; }
        if ($provider_name === 'facebook') { $facebook_app_id  = $row['client_id'];   $facebook_app_secret  = $secret; }
    }

    $base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $redirect = $base_url . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/auth/oauth_callback.php';

    switch ($provider_name) {
        case 'google':
            if (empty($google_client_id)) return false;
            return new Google([
                'clientId'     => $google_client_id,
                'clientSecret' => $google_client_secret,
                'redirectUri'  => $redirect,
            ]);

        case 'github':
            if (empty($github_client_id)) return false;
            return new Github([
                'clientId'     => $github_client_id,
                'clientSecret' => $github_client_secret,
                'redirectUri'  => $redirect,
            ]);

        case 'facebook':
            if (empty($facebook_app_id)) return false;
            return new Facebook([
                'clientId'     => $facebook_app_id,
                'clientSecret' => $facebook_app_secret,
                'redirectUri'  => $redirect,
                'graphApiVersion' => 'v18.0',
            ]);

        default:
            return false;
    }
}

/** The enabled oauth_provider row for $provider_name, or null. */
function oauth_provider_row($provider_name) {
    $n = sanitize((string)$provider_name, SQL);
    $r = db_query("SELECT * FROM oauth_provider WHERE provider_name = '$n' AND is_enabled = 1 ORDER BY provider_id DESC LIMIT 1");
    $row = $r ? db_fetch($r) : null;
    return $row ?: null;
}

/** Enabled providers that can actually run (a client id from the row or config). */
function oauth_enabled_providers() {
    $out = [];
    $rows = db_fetch_all(db_query("SELECT provider_name FROM oauth_provider WHERE is_enabled = 1"));
    foreach (($rows ?: []) as $r) {
        $n = strtolower((string)$r['provider_name']);
        if (!in_array($n, $out, true) && oauth_provider($n)) { $out[] = $n; }
    }
    return $out;
}

/**
 * Redirect the user to an OAuth provider's auth URL.
 *
 * @param string $provider_name
 */
function oauth_redirect($provider_name) {
    $provider = oauth_provider($provider_name);
    if (!$provider) {
        $_SESSION['error'] = 'OAuth provider not configured.';
        return;
    }

    $options = [];
    if ($provider_name === 'google') {
        $options['scope'] = ['email', 'profile'];
    } elseif ($provider_name === 'facebook') {
        $options['scope'] = ['email'];
    }

    $authUrl = $provider->getAuthorizationUrl($options);
    $_SESSION['oauth2state']    = $provider->getState();
    $_SESSION['oauth_provider'] = $provider_name;

    header('Location: ' . $authUrl);
    exit;
}

/**
 * Handle the OAuth callback — exchange code for token, get user info.
 *
 * @param string $provider_name
 * @param string $code
 * @param string $state
 * @return array|false  ['email' => ..., 'name' => ..., 'oauth_id' => ...]
 */
function oauth_callback($provider_name, $code, $state) {
    if (empty($state) || $state !== ($_SESSION['oauth2state'] ?? '')) {
        unset($_SESSION['oauth2state']);
        $_SESSION['error'] = 'Invalid OAuth state. Please try again.';
        return false;
    }

    $provider = oauth_provider($provider_name);
    if (!$provider) {
        $_SESSION['error'] = 'OAuth provider not configured.';
        return false;
    }

    try {
        $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        $user  = $provider->getResourceOwner($token);

        $data = $user->toArray();

        // Only Google states it; GitHub/Facebook hand back their primary (verified) address.
        $verified = $provider_name === 'google'
            ? filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            : true;

        return [
            'email'          => strtolower(trim($data['email'] ?? '')),
            'email_verified' => $verified,
            'name'           => $data['name'] ?? ($data['login'] ?? ''),
            'oauth_id'       => (string)($data['id'] ?? $data['sub'] ?? ''),
        ];

    } catch (\Exception $e) {
        error_log("OAuth callback error ($provider_name): " . $e->getMessage());
        $_SESSION['error'] = 'OAuth authentication failed. Please try again.';
        return false;
    }
}

/**
 * The whole callback page (core's and every child app's auth/oauth_callback.php).
 *
 * Who the provider says you are decides, in this order:
 *  1. signed in already  → link the provider to THIS account (account_security's buttons);
 *  2. the provider id is linked to an account → sign that account in;
 *  3. the email belongs to an account → never link silently (task #2461): email a sign-in
 *     code; the link is made when that code is used (wn_login_finish → oauth_apply_pending_link);
 *  4. nobody → create the account, confirmed, and send it through the normal first sign-in.
 *
 * Phone app (task #2461): the app opens ?provider=google&device=1&challenge=<sha256 hex> in the
 * SYSTEM browser (Google refuses embedded WebViews). The sign-in then ends in
 * oauth_device_handoff() instead of a browser session: a one-time code goes back to the app's
 * own scheme, declared by the app's page as $opts['device_scheme'] (never taken from the URL).
 * Never returns.
 */
function oauth_handle_callback_page($opts = []) {
    if (isset($_GET['provider'])) {
        unset($_SESSION['oauth_device']);
        if (!empty($_GET['device'])) {
            $scheme    = (string)($opts['device_scheme'] ?? '');
            $challenge = strtolower((string)($_GET['challenge'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9.+-]{2,40}$/', $scheme) || in_array($scheme, ['http', 'https', 'javascript', 'data', 'file', 'about', 'blob', 'intent'], true)) {
                $_SESSION['error'] = 'This app cannot sign in with ' . ucfirst((string)$_GET['provider']) . ' yet.';
                header('Location: login.php');
                exit;
            }
            if (!preg_match('/^[0-9a-f]{64}$/', $challenge)) {
                $_SESSION['error'] = 'That sign-in link is incomplete. Start again from the app.';
                header('Location: login.php');
                exit;
            }
            $_SESSION['oauth_device'] = ['scheme' => $scheme, 'challenge' => $challenge, 'expires' => time() + 1800];
        }
        oauth_redirect(strtolower((string)$_GET['provider']));
        header('Location: login.php');
        exit;
    }

    $provider = $_SESSION['oauth_provider'] ?? '';
    $code     = $_GET['code'] ?? '';
    $state    = $_GET['state'] ?? '';

    if (!$provider || !$code) {
        $_SESSION['error'] = isset($_GET['error']) ? 'Sign-in was cancelled.' : 'Invalid OAuth callback.';
        header('Location: login.php');
        exit;
    }

    $userData = oauth_callback($provider, $code, $state);
    unset($_SESSION['oauth_provider'], $_SESSION['oauth2state']);
    $label = ucfirst($provider);

    if (!$userData || empty($userData['email']) || $userData['oauth_id'] === '') {
        $_SESSION['error'] = $_SESSION['error'] ?? 'Could not retrieve your email from ' . $label . '.';
        header('Location: login.php');
        exit;
    }
    if (empty($userData['email_verified'])) {
        $_SESSION['error'] = $label . ' has not verified that email address yet, so it cannot sign you in.';
        header('Location: login.php');
        exit;
    }

    $s_provider = sanitize($provider, SQL);
    $s_oid      = sanitize($userData['oauth_id'], SQL);

    // 1. Already signed in: this is "link my account" (not for the phone: whoever this browser
    //    happens to be signed in as is not who is signing in on the phone).
    if (!empty($_SESSION['user_id']) && !oauth_device_pending()) {
        $uid = (int)$_SESSION['user_id'];
        $other = db_fetch(db_query("SELECT user_id FROM user WHERE oauth_provider = '$s_provider' AND oauth_id = '$s_oid' AND user_id <> '$uid' LIMIT 1"));
        if ($other) {
            $_SESSION['error'] = 'That ' . $label . ' account is already linked to a different account.';
        } else {
            db_query("UPDATE user SET oauth_provider = '$s_provider', oauth_id = '$s_oid' WHERE user_id = '$uid'");
            $_SESSION['success'] = $label . ' sign-in is linked to your account.';
        }
        header('Location: ' . (function_exists('get_post_login_home') ? get_post_login_home() : '../app/'));
        exit;
    }

    // 2. Linked before.
    $user = db_fetch(db_query("SELECT * FROM user WHERE oauth_provider = '$s_provider' AND oauth_id = '$s_oid' LIMIT 1"));
    if ($user) {
        $user = get_user((int)$user['user_id']);
        wn_login_finish($user, 'oauth_' . $provider, true);
    }

    // 3. The email has an account that is not linked to this provider id: prove the inbox first.
    $user = get_user_by_email($userData['email']);
    if ($user) {
        if (!function_exists('wn_login_code_enabled') || !wn_login_code_enabled()) {
            $_SESSION['error'] = 'An account already uses ' . $userData['email'] . '. Sign in with your password, then link '
                               . $label . ' from your account security page.';
            header('Location: login.php');
            exit;
        }
        $_SESSION['oauth_link_pending'] = [
            'user_id'  => (int)$user['user_id'],
            'provider' => $provider,
            'oauth_id' => $userData['oauth_id'],
            'expires'  => time() + 60 * (defined('WN_LOGIN_CODE_TTL_MIN') ? WN_LOGIN_CODE_TTL_MIN : 15),
        ];
        $err = wn_login_code_request($userData['email']);
        $_SESSION['login_code_email'] = $userData['email'];
        if ($err) {
            $_SESSION['error'] = $err;
        } else {
            $_SESSION['success'] = 'You already have an account with ' . $userData['email'] . '. To connect ' . $label
                                 . ', enter the code we just emailed you. You only do this once.';
        }
        header('Location: code.php');
        exit;
    }

    // 4. New account.
    $settings = db_fetch(db_query("SELECT registration_mode FROM auth_settings WHERE setting_id = 1"));
    if (($settings['registration_mode'] ?? 'open') === 'closed') {
        $_SESSION['error'] = 'Registration is currently closed.';
        header('Location: login.php');
        exit;
    }

    $shard_id   = get_least_loaded_shard();
    $safe_email = sanitize($userData['email'], SQL);
    $r = db_query("INSERT INTO user (email, shard_id, is_confirmed, oauth_provider, oauth_id, created_date)
                   VALUES ('$safe_email', '$shard_id', 1, '$s_provider', '$s_oid', NOW())");
    if (!$r) {
        error_log('oauth register failed: ' . db_error());
        $_SESSION['error'] = 'Could not create your account. Please try again.';
        header('Location: login.php');
        exit;
    }

    $new_id    = db_insert_id();
    $nameParts = explode(' ', trim((string)$userData['name']), 2);
    prime_shard($shard_id);
    db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                    VALUES ('$new_id', '" . sanitize($nameParts[0] ?? '', SQL) . "', '" . sanitize($nameParts[1] ?? '', SQL) . "', NOW())");
    $_SESSION['shard_id'] = $shard_id;
    create_home_dir_id($new_id);

    wn_login_finish(get_user($new_id), 'oauth_' . $provider, false);
}

/**
 * Called when a sign-in finishes: a provider link waiting on THIS user proving their inbox
 * (an email-code sign-in) is made now. Anything else leaves it waiting until it expires.
 */
function oauth_apply_pending_link($user, $method) {
    $p = $_SESSION['oauth_link_pending'] ?? null;
    if (!$p) return false;
    if (($p['expires'] ?? 0) < time()) { unset($_SESSION['oauth_link_pending']); return false; }
    if ($method !== 'email_code' || (int)$p['user_id'] !== (int)$user['user_id']) return false;

    $uid = (int)$user['user_id'];
    $s_provider = sanitize($p['provider'], SQL);
    $s_oid      = sanitize($p['oauth_id'], SQL);
    $taken = db_fetch(db_query("SELECT user_id FROM user WHERE oauth_provider = '$s_provider' AND oauth_id = '$s_oid' AND user_id <> '$uid' LIMIT 1"));
    if (!$taken) {
        db_query("UPDATE user SET oauth_provider = '$s_provider', oauth_id = '$s_oid' WHERE user_id = '$uid'");
    }
    unset($_SESSION['oauth_link_pending']);
    return true;
}

/** A phone sign-in started in this browser (and not expired): it ends in oauth_device_handoff(). */
function oauth_device_pending() {
    $d = $_SESSION['oauth_device'] ?? null;
    if (!$d) return false;
    if (($d['expires'] ?? 0) < time()) { unset($_SESSION['oauth_device']); return false; }
    return true;
}

/**
 * End a phone sign-in in the browser: mint a one-time code bound to the app's challenge, and hand
 * it to the app's scheme ({scheme}://open/auth?c=…). The browser gets NO session. The app trades the
 * code + its verifier for a device token (deviceOAuthExchange). Never returns.
 */
function oauth_device_handoff($user, $method) {
    $d = $_SESSION['oauth_device'];
    unset($_SESSION['oauth_device']);

    $code = bin2hex(random_bytes(24));
    $uid  = (int)$user['user_id'];
    db_query("INSERT INTO oauth_handoff (code_hash, challenge, user_id, provider, created, expires_at)
              VALUES ('" . hash('sha256', $code) . "', '" . sanitize($d['challenge'], SQL) . "', '$uid', '"
              . sanitize(substr((string)$method, 0, 30), SQL) . "', NOW(), NOW() + INTERVAL 5 MINUTE)");
    if (function_exists('record_login')) { record_login($uid, $method, 'success'); }

    $url = $d['scheme'] . '://open/auth?c=' . $code;
    $b   = function_exists('get_branding') ? get_branding() : [];
    $app = $b['site_name'] ?? 'the app';
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Signed in</title></head>
<body style="font-family:system-ui,-apple-system,sans-serif;text-align:center;padding:48px 24px;color:#1f1f1f">
<h1 style="font-size:1.4rem">You're signed in</h1>
<p>Going back to <?= h($app) ?>…</p>
<p><a href="<?= h($url) ?>" id="wnBack" style="display:inline-block;padding:12px 24px;border-radius:8px;background:#1f1f1f;color:#fff;text-decoration:none">Open <?= h($app) ?></a></p>
<p style="color:#666;font-size:.9rem">You can close this tab afterwards.</p>
<script>setTimeout(function () { location.href = document.getElementById('wnBack').getAttribute('href'); }, 150);</script>
</body></html><?php
    exit;
}

/**
 * Trade a phone sign-in code for its user (deviceOAuthExchange). The code is single use and the
 * verifier must hash to the challenge the app sent when it started, so a code seen by anything
 * else on the phone is worthless. Returns the user row or an error string.
 */
function oauth_device_exchange($code, $verifier) {
    $code = (string)$code; $verifier = (string)$verifier;
    if (!preg_match('/^[0-9a-f]{48}$/', $code) || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $verifier)) {
        return 'That sign-in link is not valid. Start again from the app.';
    }
    $h   = hash('sha256', $code);
    $row = db_fetch(db_query("SELECT * FROM oauth_handoff WHERE code_hash = '$h' LIMIT 1"));
    if (!$row || $row['used_at'] !== null || strtotime($row['expires_at'] . ' UTC') < time()) {
        return 'That sign-in has expired or was already used. Start again from the app.';
    }
    if (!hash_equals($row['challenge'], hash('sha256', $verifier))) {
        return 'That sign-in was started on another device. Start again from the app.';
    }
    $r = db_query("UPDATE oauth_handoff SET used_at = NOW() WHERE code_hash = '$h' AND used_at IS NULL");
    if (!$r || (method_exists($r, 'rowCount') && $r->rowCount() < 1)) {
        return 'That sign-in has expired or was already used. Start again from the app.';
    }
    db_query("DELETE FROM oauth_handoff WHERE expires_at < NOW() - INTERVAL 1 DAY");
    $user = get_user((int)$row['user_id']);
    return $user ?: 'That account no longer exists.';
}

/**
 * The sign-in buttons for every runnable provider ($verb: 'Continue' / 'Sign up').
 * Google gets its own mark and white button (Google's branding rules); others stay plain.
 */
function oauth_buttons_html($verb = 'Continue') {
    $names = oauth_enabled_providers();
    if (!$names) return '';
    $g = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true" style="vertical-align:-3px;margin-right:8px">'
       . '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
       . '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
       . '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
       . '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
    $out = '<div class="d-grid gap-2 mb-3 wn-oauth-buttons">';
    foreach ($names as $n) {
        $href = 'oauth_callback.php?provider=' . rawurlencode($n);
        if ($n === 'google') {
            $out .= '<a href="' . h($href) . '" class="btn wn-oauth-google" data-oauth="google" '
                  . 'style="background:#fff;color:#1f1f1f;border:1px solid #747775;font-weight:500">'
                  . $g . h($verb) . ' with Google</a>';
        } else {
            $out .= '<a href="' . h($href) . '" class="btn btn-outline-secondary" data-oauth="' . h($n) . '">'
                  . h($verb) . ' with ' . h(ucfirst($n)) . '</a>';
        }
    }
    return $out . '</div>';
}

<?php
/**
 * mobileAuthFunctions.php
 * Bearer device-token auth + CORS for bundled mobile clients (child-app spec 05).
 *
 * WHY THIS EXISTS
 * A Cordova/WebView build loads its UI from the device filesystem, so its origin is
 * a device-local origin (Android https://localhost, iOS app scheme, older file://) (iOS WKWebView). Session cookies are unreliable
 * or absent there, and WKWebView enforces CORS on every request the page makes. Neither
 * problem is solvable in a child app — both live in this bootstrap.
 *
 * WHAT IT IS NOT
 * It is NOT a second authentication system. A "device token" is an ordinary `api_key`
 * row — the same credential `common.php` already accepts from the `wn_auto_login`
 * cookie for remember-me. All this does is let that credential arrive in an
 * Authorization header instead of a cookie, and then hand off to the SAME
 * load_user_session(). Every existing action, view and permission check keeps working,
 * unmodified, because by the time they run the session looks exactly as it always has.
 *
 * Consequences worth knowing:
 *   - Revocation is core's device revocation. A mobile login shows up in the user's
 *     device list like any other session and can be killed from there.
 *   - Service API keys (wn_sk_…) are a different Bearer credential handled separately
 *     in common_api.php. This function set deliberately ignores them.
 */

/**
 * The raw Bearer credential on this request, if any.
 *
 * Apache strips the Authorization header from CGI/FastCGI unless it is passed through,
 * hence the REDIRECT_ fallback; without it, Bearer auth silently never fires on some
 * hosts and looks like an expired token.
 */
function wn_bearer_credential() {
    // Note the emptiness check rather than `??`: some Apache/CGI setups set
    // HTTP_AUTHORIZATION to an EMPTY STRING while the real value sits in
    // REDIRECT_HTTP_AUTHORIZATION. With `??` the empty string wins, the fallback never
    // fires, and Bearer auth silently fails on exactly the hosts it was written for.
    $h = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) { $h = $_SERVER[$k]; break; }
    }

    if ($h === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }

    return preg_match('/^Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : '';
}

/**
 * The device token on this request — i.e. a Bearer credential that is NOT a service
 * API key. Returns '' when there is none, so the caller can take the cookie path.
 */
function wn_device_token() {
    $t = wn_bearer_credential();
    if ($t === '' || strpos($t, 'wn_sk_') === 0) { return ''; }
    return $t;
}

/**
 * Authenticate this request from its device token and populate the session.
 *
 * Hands off to load_user_session(), which is what the cookie path does — so a request
 * authenticated here is indistinguishable from a normal browser session downstream.
 *
 * @return array|false The user row, or false if the token is missing/invalid/revoked.
 */
function wn_authenticate_device_token() {
    $token = wn_device_token();
    if ($token === '') { return false; }

    $user = validate_api_key($token);   // same validator the wn_auto_login cookie uses
    if (!$user) { return false; }

    load_user_session($user);
    $_SESSION['auth_method'] = 'device_token';

    return $user;
}

/**
 * Origins a bundled client can legitimately call from.
 *
 * `null` is not a mistake: a page loaded from file:// sends `Origin: null`. The rest are
 * the WebView schemes iOS/Android use for a local bundle. None of these can be spoofed
 * into a browser-based CSRF, and Bearer (not cookies) is the credential anyway.
 *
 * Override per-deployment with $cors_allowed_origins in config/config.php.
 */
function wn_cors_origins() {
    global $cors_allowed_origins;

    $default = [
        'null',                     // file:// (Android WebView, and any local bundle)
        'file://',
        'https://localhost',        // Android WebView (cordova-android default origin)
        'app://localhost',          // iOS WKWebView with a custom scheme
        'ionic://localhost',
        'capacitor://localhost',
        'http://localhost',
        'http://localhost:8100',    // local dev server
    ];

    return is_array($cors_allowed_origins ?? null)
        ? array_merge($default, $cors_allowed_origins)
        : $default;
}

/**
 * Emit CORS headers for a bundled client, and answer preflight.
 *
 * WKWebView enforces CORS, so without this the device build fetches nothing at all —
 * this is a hard requirement, not a nicety.
 *
 * We echo the specific origin rather than sending `*`, and we never send
 * Allow-Credentials: the credential is the Bearer token, and cookies must stay out of
 * this path. That combination is what keeps it from being a CSRF surface.
 *
 * Call BEFORE any output.
 */
function wn_send_cors_headers() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || headers_sent()) { return; }

    if (!in_array($origin, wn_cors_origins(), true)) { return; }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-Wn-Device');
    header('Access-Control-Max-Age: 86400');

    // Preflight: answer and stop. Nothing downstream should run for an OPTIONS.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Issue a device token for a user, reusing core's remember-me machinery.
 *
 * The token IS an api_key row, so it appears in the user's device list and is revoked
 * by the same code that revokes any other remembered device.
 *
 * @return string The token. Show it to the client once; it is stored on the device.
 */
function wn_issue_device_token($user_id, $device_label = '') {
    $device_id = $_SESSION['device_id'] ?? null;

    if (!$device_id) {
        // A bundled client has no tracking cookie, so it supplies its own stable id
        // via X-Wn-Device. Fall back to minting one rather than failing the login.
        $cookie_id = $_SERVER['HTTP_X_WN_DEVICE'] ?? '';
        if ($cookie_id === '' || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $cookie_id)) {
            $cookie_id = generateHashCode(64);
        }
        $existing  = get_device_by_cookie($cookie_id);
        $device_id = $existing ? (int)$existing['device_id'] : register_device($cookie_id, $user_id);
    }

    return create_api_key($user_id, $device_id, 'yes');
}

/**
 * Revoke one device token (mobile logout). Revoking is deleting the api_key row — the
 * same thing "sign out this device" does in the profile UI.
 */
function wn_revoke_device_token($token) {
    $token = sanitize($token, SQL);
    if ($token === '') { return false; }
    db_query("DELETE FROM api_key WHERE api_key = '$token'");
    return true;
}

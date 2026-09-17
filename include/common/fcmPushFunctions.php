<?php
/**
 * fcmPushFunctions.php
 * Native push (Cordova/Capacitor apps) through Firebase Cloud Messaging HTTP v1.
 *
 * A native app registers its FCM token through the same registerPushSubscription
 * action the browser uses, as endpoint "native:<token>" with no p256dh/auth.
 * send_push_to_user() routes those rows here; browser rows still go to Web Push.
 * FCM carries both platforms — iOS delivery goes FCM → APNs with the APNs key the
 * app owner uploads to the Firebase project.
 *
 * Credentials: the Firebase service-account JSON, per app, from either
 *   - credential FCM_SERVICE_ACCOUNT_JSON (credentials.json paste-in), or
 *   - $fcm_service_account_file in config (a path above the webroot).
 * No credentials → native rows are skipped, never an error.
 */

use Firebase\JWT\JWT;

const FCM_NATIVE_PREFIX = 'native:';

function is_native_push_endpoint($endpoint) {
    return strncmp((string)$endpoint, FCM_NATIVE_PREFIX, strlen(FCM_NATIVE_PREFIX)) === 0;
}

/** The decoded service account, or null when native push is not configured. */
function fcm_service_account() {
    static $sa = false;
    if ($sa !== false) { return $sa; }
    global $fcm_service_account_file;
    $raw = function_exists('credential_get') ? credential_get('FCM_SERVICE_ACCOUNT_JSON') : null;
    if (($raw === null || $raw === '') && !empty($fcm_service_account_file) && is_file($fcm_service_account_file)) {
        $raw = @file_get_contents($fcm_service_account_file);
    }
    $j = $raw ? json_decode((string)$raw, true) : null;
    $sa = (is_array($j) && !empty($j['project_id']) && !empty($j['client_email']) && !empty($j['private_key']))
        ? $j : null;
    return $sa;
}

/**
 * OAuth access token for FCM, cached until shortly before it expires. The cache
 * lives with the credential store (above the webroot), keyed by service account.
 */
function fcm_access_token() {
    $sa = fcm_service_account();
    if (!$sa) { return null; }

    global $files_location;
    $dir   = isset($files_location) ? rtrim($files_location, '/') : sys_get_temp_dir();
    $cache = $dir . '/fcm_token.' . substr(sha1($sa['client_email']), 0, 12) . '.json';
    $c = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (is_array($c) && !empty($c['access_token']) && ($c['expires_at'] ?? 0) > time() + 120) {
        return $c['access_token'];
    }

    $now = time();
    $token_uri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    try {
        $assertion = JWT::encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $token_uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], $sa['private_key'], 'RS256');
    } catch (\Throwable $e) {
        error_log('FCM: could not sign service-account assertion: ' . $e->getMessage());
        return null;
    }

    $resp = fcm_http($token_uri, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $assertion,
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    $j = json_decode($resp['body'], true);
    if ($resp['status'] !== 200 || empty($j['access_token'])) {
        error_log('FCM: token exchange failed (HTTP ' . $resp['status'] . ')');
        return null;
    }
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($cache, json_encode([
            'access_token' => $j['access_token'],
            'expires_at'   => $now + (int)($j['expires_in'] ?? 3600),
        ]), LOCK_EX);
        @chmod($cache, 0600);
    }
    return $j['access_token'];
}

/** Minimal POST helper. @return array ['status'=>int, 'body'=>string] */
function fcm_http($url, $body, array $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $out    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $out === false ? '' : (string)$out];
}

/**
 * Send one message to one native token.
 * @return string 'ok' | 'gone' (token dead — delete the row) | 'error' | 'unconfigured'
 */
function fcm_send_to_token($token, $title, $body, array $payload = []) {
    $sa     = fcm_service_account();
    $access = $sa ? fcm_access_token() : null;
    if (!$sa || !$access) { return 'unconfigured'; }

    // FCM data values must all be strings.
    $data = [];
    foreach (array_merge(['title' => $title, 'body' => $body], $payload) as $k => $v) {
        if (is_scalar($v) || $v === null) { $data[(string)$k] = (string)$v; }
        else { $data[(string)$k] = json_encode($v); }
    }
    $tag = $payload['tag'] ?? '';

    $message = [
        'token'        => $token,
        'notification' => ['title' => (string)$title, 'body' => (string)$body],
        'data'         => $data,
        'android'      => array_filter([
            'priority'     => 'HIGH',
            'notification' => $tag !== '' ? ['tag' => (string)$tag] : null,
        ]),
        'apns'         => [
            'payload' => ['aps' => array_filter([
                'sound'     => 'default',
                'thread-id' => $tag !== '' ? (string)$tag : null,
            ])],
        ],
    ];

    $resp = fcm_http(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send',
        json_encode(['message' => $message]),
        ['Authorization: Bearer ' . $access, 'Content-Type: application/json']
    );
    if ($resp['status'] === 200) { return 'ok'; }

    $err  = json_decode($resp['body'], true)['error'] ?? [];
    $code = '';
    foreach (($err['details'] ?? []) as $d) {
        if (!empty($d['errorCode'])) { $code = $d['errorCode']; break; }
    }
    // UNREGISTERED = app uninstalled / token rotated. INVALID_ARGUMENT on a send
    // whose only per-device field is the token means the token itself is bad.
    if ($code === 'UNREGISTERED' || $resp['status'] === 404
        || ($code === 'INVALID_ARGUMENT' && stripos((string)($err['message'] ?? ''), 'token') !== false)) {
        return 'gone';
    }
    if ($resp['status'] === 401) {
        // A revoked/rotated service account: drop the cached token so the next send re-mints.
        global $files_location;
        $dir = isset($files_location) ? rtrim($files_location, '/') : sys_get_temp_dir();
        @unlink($dir . '/fcm_token.' . substr(sha1($sa['client_email']), 0, 12) . '.json');
    }
    error_log('FCM send failed (HTTP ' . $resp['status'] . ($code ? ", $code" : '') . ')');
    return 'error';
}

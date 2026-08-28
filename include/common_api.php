<?php
/**
 * common_api.php
 * API bootstrap — JSON output, no template rendering.
 */

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}

// Shared bootstrap: autoload, config + credentials, PDO, helpers, migrations.
// Everything below is what makes THIS entry point different — the auth posture.
require_once __DIR__ . '/bootstrap.php';

// 6. Session — or, for a bundled mobile client, deliberately no session at all.
//
// CORS first: WKWebView enforces it, so a bundled client gets nothing without these
// headers, and a preflight must be answered before anything else runs.
if (function_exists('wn_send_cors_headers')) { wn_send_cors_headers(); }

// A device token authenticates the request by itself. Starting a PHP session for it
// would write a session file (they are file-backed, in $files_location/sessions) on
// every poll from every phone — pure garbage on a shared host. So for these requests
// $_SESSION is an ordinary in-memory array: load_user_session() fills it, every
// downstream action reads it exactly as always, and nothing is persisted.
$_DEVICE_TOKEN_AUTH = false;
if (function_exists('wn_device_token') && wn_device_token() !== '') {
    $_SESSION = [];
    $_DEVICE_TOKEN_AUTH = (bool)wn_authenticate_device_token();

    if (!$_DEVICE_TOKEN_AUTH) {
        if (!headers_sent()) { http_response_code(401); }
        echo json_encode(['error' => 'Login required.', 'success' => '', 'info' => '', 'warning' => '', 'results' => []]);
        exit;
    }
} else {
    init_session_storage();
    session_start();
}

// 6b. Service API key authentication (Bearer token)
$_SERVICE_API_KEY = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(wn_sk_.+)$/i', $authHeader, $m)) {
    $_SERVICE_API_KEY = validate_service_api_key($m[1]);
    if (!$_SERVICE_API_KEY) {
        if (!headers_sent()) { http_response_code(401); }
        echo json_encode(['error' => 'Invalid or revoked API key.', 'success' => '', 'info' => '', 'warning' => '', 'results' => []]);
        exit;
    }
}

// 6c. Device tracking — identify API callers via cookie or X-Wn-Device header
if (function_exists('get_or_create_device')) {
    try { get_or_create_device(); } catch (Exception $e) { /* graceful */ }
}

// 7. definition.php
include(__DIR__ . '/definition.php');

// 8. Action includes
foreach (glob(__DIR__ . '/actions/*/*.php') as $f) { include_once($f); }

// 9. Action logging — record what was just executed
if (function_exists('log_user_action')) {
    $__action = $_POST['action'] ?? 'view';
    try { log_user_action($__action); } catch (Exception $e) { /* silent */ }
    unset($__action);
}

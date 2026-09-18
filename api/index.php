<?php
/**
 * api/index.php
 * Single API endpoint for wave-networks-core.
 * ALL API requests go through this file.
 * Action files are auto-included via common_api.php → glob.
 */
include(__DIR__ . '/../include/common_api.php');

// An action name this deployment does not dispatch is refused by name (404) instead of
// answering the same empty envelope as "nothing found" (include/common/apiDispatchFunctions.php).
if (function_exists('wn_refuse_unknown_action')) { wn_refuse_unknown_action(); }

// Collect response
$response = [
    'error'   => $_SESSION['error'] ?? '',
    'success' => $_SESSION['success'] ?? '',
    'info'    => $_SESSION['info'] ?? '',
    'warning' => $_SESSION['warning'] ?? '',
    'results' => $data,
];

// Set HTTP status code. Keep a more specific one an action already chose — 401/403 from
// require_api_scope, 404 from the unknown-action refusal, 426 from a version pin — and fall
// back to a blanket 400 only when nothing more specific was set.
if (!empty($_SESSION['error']) && !headers_sent() && http_response_code() < 400) {
    http_response_code(400);
}

// Clear session messages
$_SESSION['error']   = null;
$_SESSION['success'] = null;
$_SESSION['info']    = null;
$_SESSION['warning'] = null;

echo json_encode($response);

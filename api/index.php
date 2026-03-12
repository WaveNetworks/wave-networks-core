<?php
/**
 * api/index.php
 * Single API endpoint for wave-networks-core.
 * ALL API requests go through this file.
 * Action files are auto-included via common_api.php → glob.
 */
include(__DIR__ . '/../include/common_api.php');

// Collect response
$response = [
    'error'   => $_SESSION['error'] ?? '',
    'success' => $_SESSION['success'] ?? '',
    'info'    => $_SESSION['info'] ?? '',
    'warning' => $_SESSION['warning'] ?? '',
    'results' => $data,
];

// Set HTTP status code
if (!empty($_SESSION['error'])) {
    http_response_code(400);
}

// Clear session messages
$_SESSION['error']   = null;
$_SESSION['success'] = null;
$_SESSION['info']    = null;
$_SESSION['warning'] = null;

echo json_encode($response);

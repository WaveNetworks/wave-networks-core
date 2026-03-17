<?php
/**
 * branding.php
 * Serves branding assets (logos, favicons, PWA icons) from $files_location/branding/.
 * Lightweight — loads only config, no session/auth/helpers.
 *
 * URL: /admin/branding/{filename} (rewritten by .htaccess)
 */

// Get filename from rewrite or query string
$file = $_GET['f'] ?? '';

if (!$file || preg_match('/[\/\\\\]|\.\./', $file)) {
    http_response_code(400);
    exit;
}

// Load config to get $files_location
$configFile = __DIR__ . '/config/config.php';
if (file_exists($configFile)) {
    include($configFile);
} elseif (getenv('FILES_LOCATION')) {
    $files_location = getenv('FILES_LOCATION');
} else {
    $files_location = __DIR__ . '/../../files/';
}

if (empty($files_location)) {
    http_response_code(500);
    exit;
}

$path = rtrim($files_location, '/') . '/branding/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

// MIME type from extension
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime_map = [
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'ico'  => 'image/x-icon',
];
$mime = $mime_map[$ext] ?? (mime_content_type($path) ?: 'application/octet-stream');

// Cache for 1 hour, revalidate
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=3600, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($path);

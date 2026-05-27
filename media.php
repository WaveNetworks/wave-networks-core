<?php
/**
 * media.php
 * Serves media-library assets from $files_location/media/.
 * Lightweight — loads only config, no session/auth/helpers (assets are public,
 * embedded in apps/ads). Mirrors branding.php.
 *
 * URL: /admin/media/{filename} (rewritten by .htaccess)
 */

$file = $_GET['f'] ?? '';

if (!$file || preg_match('/[\/\\\\]|\.\./u', $file)) {
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

$path = rtrim($files_location, '/') . '/media/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime_map = [
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'ico'  => 'image/x-icon',
    'pdf'  => 'application/pdf',
    'mp4'  => 'video/mp4',
];
$mime = $mime_map[$ext] ?? (@mime_content_type($path) ?: 'application/octet-stream');

$etag = '"' . md5($path . filemtime($path)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=86400, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
readfile($path);

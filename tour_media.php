<?php
/**
 * tour_media.php
 * Serves onboarding tour media (uploaded welcome videos) from
 * $files_location/tour_media/. Lightweight — loads only config, no session/auth.
 *
 * URL: /admin/tour_media/{filename} (rewritten by .htaccess)
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

$path = rtrim($files_location, '/') . '/tour_media/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime_map = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'ogg'  => 'video/ogg',
    'ogv'  => 'video/ogg',
];
$mime = $mime_map[$ext] ?? (@mime_content_type($path) ?: 'application/octet-stream');

$size = filesize($path);
$etag = '"' . md5($path . filemtime($path)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=3600, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

// Honour a single Range request so the <video> element can seek.
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = ($m[1] === '') ? 0 : (int)$m[1];
    $end   = ($m[2] === '') ? $size - 1 : (int)$m[2];
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $end = min($end, $size - 1);
    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . $length);
    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

header('Content-Length: ' . $size);
readfile($path);

<?php
/**
 * feedback_screenshot.php
 * Serves admin feedback screenshots from $files_location/feedback/screenshots/.
 * Admin-only — bootstraps the full session/auth stack so we get the same
 * has_role() check the rest of the admin uses.
 *
 * URL: /admin/feedback/screenshot/<feedback_id>  (rewritten by .htaccess)
 */

include(__DIR__ . '/include/common.php');

if (!has_role('admin')) {
    http_response_code(403);
    exit;
}

$fid = intval($_GET['id'] ?? 0);
if ($fid <= 0) {
    http_response_code(400);
    exit;
}

$row = get_feedback_by_id($fid);
if (!$row || empty($row['screenshot_path'])) {
    http_response_code(404);
    exit;
}

$base = feedback_screenshots_base_dir();
if (!$base) {
    http_response_code(500);
    exit;
}

// Defensive: only serve files that resolve under the screenshots base dir.
$safe = preg_replace('#[^A-Za-z0-9/_.\-]#', '', $row['screenshot_path']);
if (!$safe || strpos($safe, '..') !== false) {
    http_response_code(400);
    exit;
}
$path = $base . $safe;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$etag = '"' . md5($path . filemtime($path)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=3600, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
readfile($path);

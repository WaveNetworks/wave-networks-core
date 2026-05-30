<?php
/**
 * use_case_screenshot.php
 * Serves Playwright use_case screenshots from
 * $files_location/use_case_screenshots/{run_id}/{name}.png.
 * Admin-only — bootstraps the full session/auth stack so we get the same
 * has_role() check the rest of the admin uses.
 *
 * URL: /admin/use_case_screenshot.php?run_id=<run_id>&f=<name>.png
 */

include(__DIR__ . '/include/common.php');

if (!has_role('admin')) {
    http_response_code(403);
    exit;
}

$run_id = intval($_GET['run_id'] ?? 0);
$name   = use_case_screenshot_safe_name($_GET['f'] ?? '');
if ($run_id <= 0 || $name === null) {
    http_response_code(400);
    exit;
}

$base = use_case_screenshots_base_dir();
if (!$base) {
    http_response_code(500);
    exit;
}

$path = $base . $run_id . '/' . $name;
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

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
readfile($path);

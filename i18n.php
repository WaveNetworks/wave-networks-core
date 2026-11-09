<?php
/**
 * i18n.php — a published catalog for JavaScript (assets/js/wn-i18n.js), public and read-only.
 *
 *   GET /admin/i18n.php?locale=pt-BR,es     the first wanted tag that is published
 *   GET /admin/i18n.php?locale=es&preview=es.<code>   a tester's unpublished language (never cached)
 *   200 {locale, dir, strings}   400 no usable tag   404 none of them published
 *
 * No session, no database: translations of the interface are not private, and the phone's baked
 * shell has neither. Only files the multi-lingual sub-admin published are served, and a tag can
 * never name a path (wn_locale_normalize). The body's hash is the ETag; the client's fetch uses
 * cache:'no-cache', so the browser revalidates by itself. A manual If-None-Match would be a
 * non-simple header and cost a CORS preflight.
 */
require __DIR__ . '/include/common/i18nFunctions.php';
require_once __DIR__ . '/include/common/mobileAuthFunctions.php';
if (is_file(__DIR__ . '/config/config.php')) include __DIR__ . '/config/config.php';  // $cors_allowed_origins

header('Vary: Origin');
wn_send_cors_headers();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, OPTIONS');
    exit('{"error":"method not allowed"}');
}

$r = wn_i18n_payload($_GET['locale'] ?? '', $_GET['preview'] ?? '');
http_response_code($r['status']);
if ($r['status'] !== 200) {
    header('Cache-Control: no-store');
    exit($r['body']);
}
header(!empty($r['preview']) ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=0, must-revalidate');
header('ETag: ' . $r['etag']);
if (wn_i18n_etag_matches($_SERVER['HTTP_IF_NONE_MATCH'] ?? '', $r['etag'])) {
    http_response_code(304);
    exit;
}
echo $r['body'];

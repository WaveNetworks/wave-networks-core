<?php
/**
 * assetFunctions.php
 * Cache-busted URLs for core's own static assets (CSS/JS under admin/).
 *
 * Hosts' CDNs cache a bare `style.css` URL across deploys, so a core CSS/JS fix
 * never reached users until the cache expired. Appending the file's mtime makes
 * every deploy that changes the file produce a new URL.
 *
 *   Core views/auth:  core_asset_url('assets/css/style.css')                  -> ../assets/css/style.css?v=1726...
 *   Child apps:       core_asset_url('assets/css/style.css', '../../admin/')  -> ../../admin/assets/css/style.css?v=1726...
 *   Absolute:         core_asset_url('assets/css/style.css', '/admin/')
 */

/**
 * @param string $relative Path relative to the core (admin/) root, e.g. 'assets/css/style.css'.
 *                         Any query string is dropped and replaced by the mtime version.
 * @param string $prefix   URL prefix that reaches admin/ from the page emitting the link.
 * @return string          Unescaped URL — pass through h() when printing into HTML.
 */
function core_asset_url($relative, $prefix = '../') {
    $relative = ltrim(strtok((string)$relative, '?#'), '/');
    $file     = dirname(__DIR__, 2) . '/' . $relative;
    $mtime    = is_file($file) ? (int)@filemtime($file) : 0;
    return $prefix . $relative . ($mtime ? '?v=' . $mtime : '');
}

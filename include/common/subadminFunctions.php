<?php
/**
 * subadminFunctions.php — registry of mounted core sub-admins (nokemo task #2396).
 *
 * A sub-admin (help-desk, multi-lingual, social-media, beta-testing, ...) is its own repo,
 * depends on this core and never duplicates it, and is deployed by the app's deploy.yml to
 * public_html/<mount>/, a sibling of admin/. Each carries a subadmin.json manifest at its root.
 * Contract: docs/SubAdmins.md.
 *
 * Mounted = a sibling directory whose subadmin.json parses and whose "mount" names that
 * directory. The deploy removes subadmin.json from a sub-admin that is no longer listed in
 * SUBADMINS, so disabling one drops it from here (and from the sidebar) without touching data.
 */

/**
 * @return array<string,array> mount => manifest (+ 'url' relative to admin/app/)
 */
function subadmin_registry() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $root = dirname(__DIR__, 3); // public_html/ (admin/include/common → admin → public_html)
    foreach (glob($root . '/*/subadmin.json') ?: [] as $file) {
        $dir = basename(dirname($file));
        if ($dir === 'admin') continue;
        $m = json_decode((string) @file_get_contents($file), true);
        if (!is_array($m) || empty($m['slug'])) continue;
        $mount = trim((string) ($m['mount'] ?? $m['slug']), '/');
        if ($mount !== $dir) continue; // a manifest only speaks for its own directory
        $m['mount'] = $mount;
        $m['name']  = (string) ($m['name'] ?? $m['slug']);
        $m['icon']  = preg_match('/^bi-[a-z0-9-]+$/', (string) ($m['icon'] ?? '')) ? $m['icon'] : 'bi-puzzle';
        $m['url']   = '../../' . rawurlencode($mount) . '/';
        $cache[$mount] = $m;
    }
    ksort($cache);
    return $cache;
}

/** Scopes declared by mounted sub-admins (manifest "scopes" list, or legacy "api_scope"). */
function subadmin_scopes() {
    $out = [];
    foreach (subadmin_registry() as $m) {
        foreach ((array) ($m['scopes'] ?? (isset($m['api_scope']) ? [$m['api_scope']] : [])) as $s) {
            if (is_string($s) && $s !== '') $out[$s] = $m['slug'];
        }
    }
    return $out;
}

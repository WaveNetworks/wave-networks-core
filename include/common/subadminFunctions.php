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

/**
 * Manifest "every" → interval in minutes: "1m", "15m", "2h", "1d" (a bare number = minutes).
 * Anything else → 0 (never runs). Daily jobs run at 00:00 server time, hourly ones on :00.
 */
function subadmin_cron_interval($every) {
    if (!preg_match('/^\s*(\d+)\s*([mhd]?)\s*$/i', (string) $every, $m) || (int) $m[1] < 1) return 0;
    $mult = ['' => 1, 'm' => 1, 'h' => 60, 'd' => 1440][strtolower($m[2])];
    return (int) $m[1] * $mult;
}

/**
 * Cron jobs that mounted sub-admins declare in their manifest ("cron": [{"path","every"}]),
 * due at $minuteOfDay (0..1439). Core's cron/cron.php runs them, so a sub-admin never needs
 * its own crontab line. A path must be a .php file INSIDE that sub-admin's directory.
 *
 * @return array<int,array{mount:string,label:string,file:string}>
 */
function subadmin_cron_due($minuteOfDay, $registry = null) {
    $root = dirname(__DIR__, 3);
    $out = [];
    foreach (($registry ?? subadmin_registry()) as $mount => $m) {
        $base = realpath($root . '/' . $mount);
        if ($base === false) continue;
        foreach ((array) ($m['cron'] ?? []) as $job) {
            $iv = subadmin_cron_interval($job['every'] ?? '');
            $rel = (string) ($job['path'] ?? '');
            if ($iv < 1 || $minuteOfDay % $iv !== 0 || substr($rel, -4) !== '.php') continue;
            $file = realpath($base . '/' . ltrim($rel, '/'));
            if ($file === false || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0) continue;
            $out[] = ['mount' => $mount, 'label' => $mount . '/' . ltrim($rel, '/'), 'file' => $file];
        }
    }
    return $out;
}

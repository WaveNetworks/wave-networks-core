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

/**
 * Links a mounted sub-admin offers to the APP's signed-in users (manifest "user_menu":
 * [{label, path, icon?, sub?}]), e.g. help-desk's "Help & support". Apps render these rows
 * in their own account/menu UI. url is root-relative (/<mount>/<path>): sub-admins sit at the
 * webroot beside the app. A path that leaves the mount (.., //, scheme) is dropped.
 * @return array<int,array{slug:string,label:string,sub:string,icon:string,url:string}>
 */
function subadmin_user_menu($registry = null) {
    $out = [];
    foreach ($registry ?? subadmin_registry() as $m) {
        foreach ((array) ($m['user_menu'] ?? []) as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $path  = ltrim((string) ($item['path'] ?? ''), '/');
            if ($label === '' || preg_match('#\.\.|//|:|\\\\#', $path)) continue;
            $icon = (string) ($item['icon'] ?? '');
            $out[] = [
                'slug'  => (string) $m['slug'],
                'label' => $label,
                'sub'   => trim((string) ($item['sub'] ?? '')),
                'icon'  => preg_match('/^bi-[a-z0-9-]+$/', $icon) ? $icon : (string) ($m['icon'] ?? 'bi-puzzle'),
                'url'   => '/' . rawurlencode((string) $m['mount']) . '/' . $path,
            ];
        }
    }
    return $out;
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

/**
 * Notification categories mounted sub-admins declare ("notification_categories":
 * [{"slug","name","description"?,"icon"?,"default_frequency"?}]). Slugs are [a-z0-9_]; the
 * icon defaults to the sub-admin's own. created_by_app = the sub-admin's slug.
 *
 * @return array<int,array{slug:string,name:string,description:string,opts:array}>
 */
function subadmin_notification_categories($registry = null) {
    $out = [];
    foreach (($registry ?? subadmin_registry()) as $m) {
        foreach ((array) ($m['notification_categories'] ?? []) as $c) {
            $slug = is_array($c) ? (string) ($c['slug'] ?? '') : '';
            if (!preg_match('/^[a-z0-9_]{1,50}$/', $slug)) continue;
            $icon = (string) ($c['icon'] ?? $m['icon'] ?? '');
            $freq = (string) ($c['default_frequency'] ?? 'realtime');
            $out[] = [
                'slug'        => $slug,
                'name'        => (string) ($c['name'] ?? $slug),
                'description' => (string) ($c['description'] ?? ''),
                'opts'        => [
                    'icon'              => preg_match('/^bi-[a-z0-9-]+$/', $icon) ? $icon : 'bi-bell',
                    'default_frequency' => in_array($freq, ['realtime', 'daily', 'weekly', 'off'], true) ? $freq : 'realtime',
                    'created_by_app'    => (string) $m['slug'],
                ],
            ];
        }
    }
    return $out;
}

/**
 * Register every mounted sub-admin's notification categories (idempotent; core's cron runs it
 * each minute, so a newly mounted sub-admin's categories appear in users' notification prefs
 * without a request-time write). A slug another app already owns is left alone, so a manifest
 * can never rename a core or child-app category. Returns the slugs registered.
 */
function subadmin_register_notification_categories($registry = null) {
    global $db;
    if (!function_exists('register_notification_category') || !isset($db)) return [];
    $done = [];
    foreach (subadmin_notification_categories($registry) as $c) {
        $s = sanitize($c['slug'], SQL);
        $r = $db->query("SELECT created_by_app FROM notification_category WHERE slug = '$s'");
        $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && (string) $row['created_by_app'] !== $c['opts']['created_by_app']) continue;
        register_notification_category($c['slug'], $c['name'], $c['description'], $c['opts']);
        $done[] = $c['slug'];
    }
    return $done;
}

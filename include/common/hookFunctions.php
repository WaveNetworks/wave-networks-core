<?php
/**
 * hookFunctions.php — hooks between an app and the core sub-admins mounted beside it.
 *
 * A sub-admin offers named hooks ("beta.tester.upsert"); the app calls them with wn_hook(). The
 * sub-admin's manifest names a hooks file (subadmin.json "hooks": "include/hooks.php") that
 * registers its providers with wn_hook_provide(). Core loads those files the first time a hook is
 * called, in-process (same host, same admin core, same shard registry), so an app never includes a
 * sub-admin's internals and holds no key for it. The manifest's "extension_points" documents each
 * hook (name, payload, result) — docs/SubAdmins.md "Hooks".
 *
 * Calls never throw. A sub-admin that is not mounted, not configured or failing answers
 * ['ok' => false, 'available' => bool, 'result' => null|array, 'error' => string] and the caller
 * decides what to do (usually: carry on and log), so an app keeps working without the sub-admin.
 */

if (!function_exists('wn_hook_provide')) {
    /** Register $fn as the provider of hook $name (one provider per name; a later call replaces it). */
    function wn_hook_provide($name, callable $fn, $owner = '') {
        $GLOBALS['__wn_hooks'][(string) $name] = ['fn' => $fn, 'owner' => (string) $owner];
    }
}

if (!function_exists('wn_hooks_load')) {
    /**
     * Require each mounted sub-admin's hooks file once. $registry/$root are for tests (default:
     * subadmin_registry() and public_html/). A hooks path must stay inside its own mount.
     * Returns the mounts whose hooks file was loaded on this call.
     */
    function wn_hooks_load($registry = null, $root = null) {
        static $done = [];
        $registry = $registry ?? (function_exists('subadmin_registry') ? subadmin_registry() : []);
        $root = rtrim($root ?? dirname(__DIR__, 3), '/');
        $loaded = [];
        foreach ($registry as $m) {
            $mount = (string) ($m['mount'] ?? '');
            $rel = (string) ($m['hooks'] ?? '');
            $key = $root . '/' . $mount;
            if ($mount === '' || $rel === '' || isset($done[$key])) continue;
            $done[$key] = true;
            if (!preg_match('#^[A-Za-z0-9_\-/]+\.php$#', $rel) || strpos($rel, '..') !== false) {
                error_log("wn_hooks_load: $mount hooks path refused: $rel");
                continue;
            }
            $file = $key . '/' . ltrim($rel, '/');
            if (!is_file($file)) { error_log("wn_hooks_load: $mount hooks file missing: $rel"); continue; }
            try {
                require_once $file;
                $loaded[] = $mount;
            } catch (Throwable $e) {
                error_log("wn_hooks_load: $mount: " . $e->getMessage());
            }
        }
        return $loaded;
    }
}

if (!function_exists('wn_hook_available')) {
    /** True when a mounted sub-admin provides hook $name. */
    function wn_hook_available($name) {
        wn_hooks_load();
        return isset($GLOBALS['__wn_hooks'][(string) $name]);
    }
}

if (!function_exists('wn_hook_names')) {
    /** Every provided hook name => owner, sorted. */
    function wn_hook_names() {
        wn_hooks_load();
        $out = array_map(fn($h) => $h['owner'], $GLOBALS['__wn_hooks'] ?? []);
        ksort($out);
        return $out;
    }
}

if (!function_exists('wn_hook')) {
    /**
     * Call hook $name with $payload (an array). The provider returns an array; an 'error' key in it
     * means it refused. Returns ['ok', 'available', 'result', 'error']. Never throws.
     */
    function wn_hook($name, $payload = []) {
        $name = (string) $name;
        wn_hooks_load();
        $h = $GLOBALS['__wn_hooks'][$name] ?? null;
        if (!$h) return ['ok' => false, 'available' => false, 'result' => null, 'error' => "No sub-admin offers the hook $name."];
        try {
            $r = call_user_func($h['fn'], is_array($payload) ? $payload : []);
        } catch (Throwable $e) {
            error_log("wn_hook $name: " . $e->getMessage());
            return ['ok' => false, 'available' => true, 'result' => null, 'error' => $e->getMessage()];
        }
        $err = is_array($r) && !empty($r['error']) ? (string) $r['error'] : null;
        return ['ok' => $err === null, 'available' => true, 'result' => $r, 'error' => $err];
    }
}

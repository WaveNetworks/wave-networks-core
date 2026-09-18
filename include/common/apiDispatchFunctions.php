<?php
/**
 * apiDispatchFunctions.php — an API call that did nothing says so.
 *
 * Actions are flat `if (($action ?? null) == 'name')` blocks included by a glob, so an action
 * name this deployment does not have simply matches nothing and the endpoint answers
 * 200 {"error":"","results":[]} — byte for byte what an action that legitimately found no rows
 * answers. A caller cannot tell "this app is older and has no such action" from "there is
 * nothing here", which is how a fleet-wide check can quietly pass on apps it never really ran
 * on (2026-09-18).
 *
 * So the endpoint asks first whether the action exists at all. The answer comes from the same
 * files the glob includes: their dispatch lines are read once and cached against the newest
 * mtime in the action directories, so this costs a stat per directory on a warm cache.
 *
 * It deliberately says only whether the NAME is dispatched here. Whether the caller may run it
 * is the action's own business (require_api_scope / session checks), and those already answer
 * loudly with 401/403.
 */

if (!function_exists('wn_action_dirs')) {
    /** The directories whose *.php files are globbed into a request, deepest app first. */
    function wn_action_dirs($app_root = null) {
        $dirs = [];
        if ($app_root) {
            foreach (['/include/actions/apiActions', '/include/actions/memberActions', '/include/actions/loginActions'] as $d) {
                if (is_dir($app_root . $d)) $dirs[] = $app_root . $d;
            }
        }
        $core = dirname(__DIR__);   // …/include
        foreach (['/actions/apiActions', '/actions/memberActions', '/actions/loginActions'] as $d) {
            if (is_dir($core . $d)) $dirs[] = $core . $d;
        }
        return $dirs;
    }
}

if (!function_exists('wn_known_actions')) {
    /**
     * Every action name dispatched by the files in $dirs. Recognises the idioms used across
     * this stack:  ($action ?? null) == 'x'   ($_POST['action'] ?? '') === 'x'
     *              $_REQUEST['action'] == 'x' and the same with double quotes.
     */
    function wn_known_actions(array $dirs) {
        static $memo = [];
        $key = md5(implode('|', $dirs));
        if (isset($memo[$key])) return $memo[$key];

        $files = [];
        $stamp = '';
        foreach ($dirs as $d) {
            foreach (glob($d . '/*.php') ?: [] as $f) { $files[] = $f; $stamp .= basename($f) . (int) @filemtime($f); }
        }
        $cache = rtrim(sys_get_temp_dir(), '/') . '/wn_actions_' . md5($key . $stamp) . '.json';
        $cached = @file_get_contents($cache);
        if ($cached !== false) {
            $names = json_decode($cached, true);
            if (is_array($names)) return $memo[$key] = $names;
        }

        $names = [];
        foreach ($files as $f) {
            $src = (string) @file_get_contents($f);
            if (preg_match_all('~\$(?:action|_POST\[[\'"]action[\'"]\]|_REQUEST\[[\'"]action[\'"]\]|_GET\[[\'"]action[\'"]\])[^;{]{0,40}?[=!]==?\s*[\'"]([A-Za-z][A-Za-z0-9_]*)[\'"]~', $src, $m)) {
                foreach ($m[1] as $n) $names[$n] = true;
            }
        }
        $names = array_keys($names);
        sort($names);
        @file_put_contents($cache, json_encode($names), LOCK_EX);
        return $memo[$key] = $names;
    }
}

if (!function_exists('wn_refuse_unknown_action')) {
    /**
     * Called by an api/index.php after the action files have run, BEFORE it builds its envelope.
     * When a request named an action this deployment does not dispatch, it sets the error and a
     * 404 so the caller can tell that apart from an empty result. Returns true when it refused.
     *
     * Anything the request already produced (an error, a flash, results) is left alone: the
     * action clearly ran.
     */
    function wn_refuse_unknown_action($app_root = null, $action = null) {
        global $data;
        $action = $action ?? ($_REQUEST['action'] ?? '');
        $action = is_string($action) ? trim($action) : '';
        if ($action === '') return false;
        if (!empty($_SESSION['error']) || !empty($_SESSION['success']) || !empty($_SESSION['info'])
            || !empty($_SESSION['warning']) || !empty($data)) return false;
        if (!preg_match('~^[A-Za-z][A-Za-z0-9_]{0,63}$~', $action)) {
            $_SESSION['error'] = 'That action name is not valid.';
            if (!headers_sent() && http_response_code() < 400) http_response_code(400);
            return true;
        }
        $known = wn_known_actions(wn_action_dirs($app_root));
        if ($known && in_array($action, $known, true)) return false;
        // Name only — never a list of what does exist.
        $_SESSION['error'] = "Unknown action '$action' on this deployment.";
        if (!headers_sent() && http_response_code() < 400) http_response_code(404);
        return true;
    }
}

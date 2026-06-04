<?php
/**
 * Action logging — records user/device actions for analytics and security.
 * Silent side-effect: never throws, never writes to STDERR.
 */

// Policy (ACTION_LOG_PARAM_ALLOWLIST, ACTION_LOG_DENY) lives in actionLogPolicy.php
// and is auto-included via glob — see that file for rules on adding entries.

function detect_source_app() {
    global $source_app;
    if (!empty($source_app)) return $source_app;

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/([a-zA-Z0-9_-]+)/(?:app|api|auth|views|assets|include|cron)#', $uri, $m)) {
        return $m[1];
    }
    return 'admin';
}

function log_user_action($action_name, $result = 'success', $params = [], $duration_ms = null) {
    try {
        $deny = defined('ACTION_LOG_DENY') ? ACTION_LOG_DENY : [];
        if (in_array($action_name, $deny, true)) return;

        $device_id = $_SESSION['device_id'] ?? null;
        if (!$device_id) {
            $cookie_name = 'wn_device';
            $cookie_id = $_SERVER['HTTP_X_WN_DEVICE'] ?? $_COOKIE[$cookie_name] ?? null;
            if ($cookie_id && function_exists('get_device_by_cookie')) {
                $device = get_device_by_cookie($cookie_id);
                if ($device) {
                    $device_id = (int)$device['device_id'];
                }
            }
            if (!$device_id) return;
        }
        $device_id = (int)$device_id;

        $user_id    = $_SESSION['user_id'] ?? null;
        $shard_id   = $_SESSION['shard_id'] ?? null;
        $app        = detect_source_app();
        $page       = $_GET['page'] ?? null;
        if (!$page) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (preg_match('#/([^/?]+?)(?:\.php)?(?:\?|$)#', basename(parse_url($uri, PHP_URL_PATH) ?: ''), $m)) {
                $page = $m[1];
            }
        }

        $allowed = defined('ACTION_LOG_PARAM_ALLOWLIST') ? ACTION_LOG_PARAM_ALLOWLIST : [];
        if (array_key_exists($action_name, $allowed)) {
            $keys = $allowed[$action_name];
            $params = is_array($params)
                ? array_intersect_key($params, array_flip($keys))
                : [];
        } else {
            // Fail-safe: unknown action → log with empty params, never raw body.
            $params = [];
        }

        // Auto-stamp A/B experiment assignments onto EVERY event (Task #795).
        // Added AFTER the allowlist filter so it survives redaction regardless of
        // the action, and read-only + request-cached so it costs at most one query
        // per request. The nightly rollup reads event_data._experiments to split
        // the funnel by variant. Empty when the device is in no active experiment.
        if (function_exists('get_all_assignments_for_device')) {
            try {
                $exp_assignments = get_all_assignments_for_device((string)$device_id, $app);
                if ($exp_assignments) { $params['_experiments'] = $exp_assignments; }
            } catch (Exception $e) { /* never block logging on experiment lookup */ }
        }

        $params_json = json_encode($params);

        $ip         = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $valid_results = ['success', 'error', 'redirect'];
        if (!in_array($result, $valid_results, true)) {
            $result = 'success';
        }

        if ($user_id && $shard_id) {
            $is_test = 0;
            try {
                $r = db_query_prepared("SELECT is_test_account FROM user WHERE user_id = ?", [(int)$user_id]);
                if ($r) {
                    $row = $r->fetch(PDO::FETCH_ASSOC);
                    if ($row) $is_test = (int)$row['is_test_account'];
                }
            } catch (Exception $e) { /* ignore */ }

            $expires = $is_test ? null : date('Y-m-d H:i:s', strtotime('+24 hours'));

            prime_shard($shard_id);
            db_query_shard_prepared($shard_id,
                "INSERT INTO user_action_log (user_id, device_id, session_id, source_app, page, action, params_json, result, duration_ms, ip_address, user_agent, created, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                [
                    (int)$user_id,
                    $device_id,
                    session_id(),
                    $app,
                    $page,
                    $action_name,
                    $params_json,
                    $result,
                    $duration_ms,
                    $ip,
                    $user_agent,
                    $expires,
                ]
            );
        } else {
            $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

            db_query_prepared(
                "INSERT INTO device_action_log (device_id, source_app, page, action, params_json, result, duration_ms, ip_address, user_agent, created, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                [
                    $device_id,
                    $app,
                    $page,
                    $action_name,
                    $params_json,
                    $result,
                    $duration_ms,
                    $ip,
                    $user_agent,
                    $expires,
                ]
            );
        }
    } catch (Exception $e) {
        error_log('actionLog: ' . $e->getMessage());
    }
}

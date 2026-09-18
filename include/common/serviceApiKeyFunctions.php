<?php
/**
 * serviceApiKeyFunctions.php
 * Service API key management for programmatic API access.
 * Separate from apiKeyFunctions.php which handles remember-me cookies.
 */

/**
 * Master list of available scopes: core's own, plus any the child app declares.
 *
 * Core lists only scopes that make sense for EVERY app. A scope that serves one
 * app (its debug endpoints, its own data) is declared by that app in
 * `api-scopes.json` at its repo root — see child_declared_scopes().
 *
 * @return array [scope_string => description]
 */
function get_available_scopes() {
    // Core wins on a name collision: an app cannot redescribe a core scope.
    return core_available_scopes() + child_declared_scopes();
}

/**
 * Scopes the child app declares in public_html/{slug}/api-scopes.json:
 *   {"scopes": {"myapp_debug:read": "Read myapp diagnostics"}}
 * Discovered like credentials.json. Missing or malformed file → no scopes.
 *
 * @return array [scope_string => description]
 */
function child_declared_scopes() {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache   = [];
    $webroot = dirname(dirname(dirname(__DIR__))); // public_html/ (admin/ is a child)
    foreach (glob($webroot . '/*/api-scopes.json') ?: [] as $f) {
        if (basename(dirname($f)) === 'admin') { continue; }
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || !isset($j['scopes']) || !is_array($j['scopes'])) { continue; }
        foreach ($j['scopes'] as $scope => $desc) {
            if (is_string($scope) && preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/', $scope)) {
                $cache[$scope] = (string)$desc;
            }
        }
    }
    return $cache;
}

/**
 * Scopes core itself provides. Add here only what every app can use.
 *
 * @return array [scope_string => description]
 */
function core_available_scopes() {
    return [
        'error_log:read'  => 'Read error logs',
        'system:read'     => 'Read host system metrics (database/shard sizes)',
        'error_log:write' => 'Resolve/unresolve error logs',
        'users:read'      => 'Read user list',
        'costs:write'     => 'Record cost entries (COGS, CAC, support)',
        'costs:read'      => 'Read cost data and reports',
        'feedback:read'   => 'Read user feedback',
        'feedback:write'  => 'Submit and manage feedback',
        'feedback:admin'  => 'Manage change requests and feedback status',
        'stripe:read'     => 'Read Stripe transactions, revenue, and LTV data',
        'stripe:write'    => 'Record Stripe transactions',
        'zoom:read'       => 'Read Zoom recordings and pipeline jobs',
        'zoom:write'      => 'Download recordings, update status, start pipeline',
        'monitoring:read' => 'Read registered apps, monitoring events, stats',
        'monitoring:write'=> 'Trigger checks, create tasks, send reports, manage CRs',
        'actions:read'    => 'Read user/device action logs and use_case derivations',
        'tests:write'     => 'Write use_case rows and use_case_test_run results',
        'media:read'      => 'Read media library assets (URLs, metadata) — for builder/agent embedding',
        'media:write'     => 'Upload media assets (e.g. archived source documents) — for builder/agent use',
        'provisioning:admin' => 'Claim and execute app provisioning jobs — decrypt creds, update status, register apps (openclaw runner only)',
        'credentials:read'  => 'Read which app credentials the app declares and which are still missing (never values)',
        'credentials:write' => 'Paste in / update app credential values (Amazon keys, API tokens, affiliate IDs, etc.)',
        'finds:write'       => 'Ingest candidate product finds into the Curate queue (external deal-finder bridge until PA-API)',
        'experiments:read'  => 'Read active A/B experiment summaries (significance, guardrails, staleness) for the heartbeat watchdog',
        'experiments:write' => 'Conclude experiments and record the winning variant (manual ship-the-winner step)',
        'shards:admin'      => 'List, register, test, migrate and retire shard databases (shard registry)',
    ];
}

/**
 * The scopes nokemo's monitoring key needs on every app.
 *
 * install/provision.php mints the key with this set, and validate_service_api_key()
 * tops up an existing monitoring key (identified by holding monitoring:write) when
 * this list grows. Every pipeline that gained a scope used to mean hand-editing the
 * key on every deployment — error_log:write, then tests:write/actions:read, then
 * media:read/write — and until someone did, that pipeline silently failed.
 *
 * @return string[]
 */
function monitor_key_scopes() {
    return [
        'error_log:read', 'error_log:write',
        'monitoring:read', 'monitoring:write',
        'feedback:read', 'feedback:write', 'feedback:admin',
        'credentials:read', 'credentials:write',
        'actions:read', 'tests:write',
        'media:read', 'media:write',
    ];
}

/**
 * Create a new service API key.
 * The full key is returned ONCE — only the bcrypt hash is stored.
 *
 * @param string $name       Human-friendly label
 * @param array  $scopes     Array of scope strings
 * @param int    $created_by User ID of the admin creating the key
 * @return array ['service_key_id' => int, 'full_key' => string, 'prefix' => string]
 */
function create_service_api_key($name, $scopes, $created_by) {
    $full_key   = 'wn_sk_' . generateHashCode(58);
    $prefix     = substr($full_key, 0, 12);
    $hash       = password_hash($full_key, PASSWORD_BCRYPT);
    $scopes_json = json_encode(array_values($scopes));

    $s_name = sanitize($name, SQL);
    $s_prefix = sanitize($prefix, SQL);
    $s_hash = sanitize($hash, SQL);
    $s_scopes = sanitize($scopes_json, SQL);
    $created_by = (int)$created_by;

    $r = db_query("INSERT INTO service_api_key (key_name, key_prefix, key_hash, scopes, created_by)
                    VALUES ('$s_name', '$s_prefix', '$s_hash', '$s_scopes', '$created_by')");

    if (!$r) {
        return false;
    }

    return [
        'service_key_id' => (int)db_insert_id(),
        'full_key'       => $full_key,
        'prefix'         => $prefix,
    ];
}

/**
 * Validate a service API key string.
 * Uses prefix lookup to narrow candidates, then bcrypt verify.
 * Updates last_used_at on success.
 *
 * @param string $key_string The full API key
 * @return array|false       Key row (with decoded scopes) or false
 */
function validate_service_api_key($key_string) {
    if (strlen($key_string) < 12) {
        return false;
    }

    $prefix = sanitize(substr($key_string, 0, 12), SQL);

    $r = db_query("SELECT * FROM service_api_key
                    WHERE key_prefix = '$prefix' AND revoked_at IS NULL");

    if (!$r) {
        return false;
    }

    $candidates = db_fetch_all($r);

    foreach ($candidates as $row) {
        if (password_verify($key_string, $row['key_hash'])) {
            // Update last_used_at
            $id = (int)$row['service_key_id'];
            db_query("UPDATE service_api_key SET last_used_at = NOW() WHERE service_key_id = '$id'");

            // Keep the monitoring key's scopes current (see monitor_key_scopes()).
            $scopes = json_decode($row['scopes'] ?? '[]', true) ?: [];
            if (in_array('monitoring:write', $scopes, true)) {
                $missing = array_values(array_diff(monitor_key_scopes(), $scopes));
                if ($missing) {
                    $merged = json_encode(array_values(array_merge($scopes, $missing)));
                    db_query("UPDATE service_api_key SET scopes = '" . sanitize($merged, SQL)
                        . "' WHERE service_key_id = '$id'");
                    $row['scopes'] = $merged;
                    error_log('service key ' . $id . ': monitoring key granted ' . implode(', ', $missing));
                }
            }
            return $row;
        }
    }

    return false;
}

/**
 * Revoke a service API key.
 *
 * @param int $service_key_id
 * @param int $revoked_by User ID of the admin revoking the key
 * @return bool
 */
function revoke_service_api_key($service_key_id, $revoked_by) {
    $id  = (int)$service_key_id;
    $uid = (int)$revoked_by;
    return (bool)db_query("UPDATE service_api_key SET revoked_at = NOW(), revoked_by = '$uid'
                           WHERE service_key_id = '$id' AND revoked_at IS NULL");
}

/**
 * Update the scopes for an existing (non-revoked) service API key.
 *
 * @param int   $service_key_id
 * @param array $scopes     New array of scope strings
 * @param int   $updated_by User ID of the admin making the change
 * @return bool
 */
function update_service_api_key_scopes($service_key_id, $scopes, $updated_by) {
    $id          = (int)$service_key_id;
    $scopes_json = sanitize(json_encode(array_values($scopes)), SQL);
    return (bool)db_query("UPDATE service_api_key SET scopes = '$scopes_json'
                           WHERE service_key_id = '$id' AND revoked_at IS NULL");
}

/**
 * Get all service API keys for admin listing.
 * Never returns key_hash.
 *
 * @return array
 */
function get_service_api_keys() {
    $r = db_query("SELECT service_key_id, key_name, key_prefix, scopes,
                          created_by, created_at, last_used_at, revoked_at, revoked_by
                   FROM service_api_key
                   ORDER BY revoked_at IS NOT NULL ASC, created_at DESC");
    return $r ? db_fetch_all($r) : [];
}

/**
 * Check if the current API key has a required scope.
 * Sets $_SESSION['error'] if scope is missing.
 *
 * @param string $scope Required scope string
 * @return bool
 */
function require_api_scope($scope) {
    global $_SERVICE_API_KEY;

    // A refusal is never silent, and never a 200: a caller must be able to tell
    // "this key may not do that" from "there was nothing to return" (2026-09-18).
    // The message names the scope the key needs and nothing else — not the key, not
    // the scopes it does hold, not who owns it.
    if (!$_SERVICE_API_KEY) {
        $_SESSION['error'] = "This endpoint needs a service API key with the $scope scope. "
            . 'Send it as: Authorization: Bearer wn_sk_…';
        if (!headers_sent() && http_response_code() < 400) { http_response_code(401); }
        return false;
    }

    $scopes = json_decode($_SERVICE_API_KEY['scopes'], true) ?: [];
    if (!in_array($scope, $scopes)) {
        $_SESSION['error'] = "Missing required scope: $scope — this API key does not carry it.";
        if (!headers_sent() && http_response_code() < 400) { http_response_code(403); }
        return false;
    }

    return true;
}

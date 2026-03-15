<?php
/**
 * serviceApiKeyFunctions.php
 * Service API key management for programmatic API access.
 * Separate from apiKeyFunctions.php which handles remember-me cookies.
 */

/**
 * Master list of available scopes.
 * Child apps extend this by registering their own scopes.
 *
 * @return array [scope_string => description]
 */
function get_available_scopes() {
    return [
        'error_log:read'  => 'Read error logs',
        'error_log:write' => 'Resolve/unresolve error logs',
        'users:read'      => 'Read user list',
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

    if (!$_SERVICE_API_KEY) {
        $_SESSION['error'] = 'Service API key required.';
        return false;
    }

    $scopes = json_decode($_SERVICE_API_KEY['scopes'], true) ?: [];
    if (!in_array($scope, $scopes)) {
        $_SESSION['error'] = "Missing required scope: $scope";
        return false;
    }

    return true;
}

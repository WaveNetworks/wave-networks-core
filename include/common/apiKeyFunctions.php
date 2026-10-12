<?php
/**
 * apiKeyFunctions.php
 * API key management for auto-login / remember-me tokens.
 *
 * The key is a bearer secret that is only ever COMPARED, so api_key.api_key holds
 * wn_token_hash($key) ('sha256:<hex>', task #2398). The raw key exists only in the
 * client's cookie / device store: create_api_key() returns it once, and nothing can read
 * it back — a flow that needs a key for a client issues a new one.
 * Lookups also accept a raw row during the rollout (main/6.0 hashes existing rows).
 */

/**
 * SQL list of the forms a stored key may take: its hash, then the legacy raw value.
 * Returns '' for input that is empty or already hash-shaped — the stored hash must never
 * work as a key (issued keys are generateHashCode() output and never carry the marker).
 */
function _api_key_lookup_in($api_key) {
    $api_key = (string)$api_key;
    if ($api_key === '' || strpos($api_key, WN_TOKEN_MARKER) === 0) { return ''; }
    return "'" . sanitize(wn_token_hash($api_key), SQL) . "', '" . sanitize($api_key, SQL) . "'";
}

/**
 * Create an API key (remember-me token) for a user+device.
 *
 * @param int    $user_id
 * @param int    $device_id
 * @param string $remember_me  'yes' or 'no'
 * @return string The generated API key — the only time it exists in plain text
 */
function create_api_key($user_id, $device_id, $remember_me = 'yes') {
    $user_id    = (int)$user_id;
    $device_id  = (int)$device_id;
    $api_key    = generateHashCode(150);
    $remember   = sanitize($remember_me, SQL);
    $key_hash   = sanitize(wn_token_hash($api_key), SQL); // hashed at rest, task #2398

    db_query("INSERT INTO api_key (device_id, user_id, api_key, key_born, remember_me)
              VALUES ('$device_id', '$user_id', '$key_hash', CURDATE(), '$remember')");

    return $api_key;
}

/**
 * Validate an API key and return the associated user.
 *
 * @param string $api_key
 * @return array|false  User row or false
 */
function validate_api_key($api_key) {
    $in = _api_key_lookup_in($api_key);
    if ($in === '') { return false; }
    $r = db_query("SELECT ak.*, u.* FROM api_key ak
                    JOIN user u ON ak.user_id = u.user_id
                    WHERE ak.api_key IN ($in) AND ak.remember_me = 'yes'
                    LIMIT 1");
    $row = db_fetch($r);
    if ($row) { unset($row['api_key']); } // never hand the stored value back to a caller
    return $row;
}

/**
 * Delete all API keys for a user (e.g. on logout-all).
 *
 * @param int $user_id
 */
function delete_user_api_keys($user_id) {
    $user_id = (int)$user_id;
    db_query("DELETE FROM api_key WHERE user_id = '$user_id'");
}

/**
 * Delete a specific API key.
 *
 * @param string $api_key
 */
function delete_api_key($api_key) {
    $in = _api_key_lookup_in($api_key);
    if ($in === '') { return; }
    db_query("DELETE FROM api_key WHERE api_key IN ($in)");
}

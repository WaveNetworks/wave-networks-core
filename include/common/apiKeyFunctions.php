<?php
/**
 * apiKeyFunctions.php
 * API key management for auto-login / remember-me tokens.
 */

/**
 * Create an API key (remember-me token) for a user+device.
 *
 * @param int    $user_id
 * @param int    $device_id
 * @param string $remember_me  'yes' or 'no'
 * @return string The generated API key
 */
function create_api_key($user_id, $device_id, $remember_me = 'yes') {
    $user_id    = (int)$user_id;
    $device_id  = (int)$device_id;
    $api_key    = generateHashCode(150);
    $remember   = sanitize($remember_me, SQL);

    db_query("INSERT INTO api_key (device_id, user_id, api_key, key_born, remember_me)
              VALUES ('$device_id', '$user_id', '$api_key', CURDATE(), '$remember')");

    return $api_key;
}

/**
 * Validate an API key and return the associated user.
 *
 * @param string $api_key
 * @return array|false  User row or false
 */
function validate_api_key($api_key) {
    $api_key = sanitize($api_key, SQL);
    $r = db_query("SELECT ak.*, u.* FROM api_key ak
                    JOIN user u ON ak.user_id = u.user_id
                    WHERE ak.api_key = '$api_key' AND ak.remember_me = 'yes'");
    return db_fetch($r);
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
    $api_key = sanitize($api_key, SQL);
    db_query("DELETE FROM api_key WHERE api_key = '$api_key'");
}

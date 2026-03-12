<?php
/**
 * deviceFunctions.php
 * Device tracking for auto-login / remember-me.
 */

/**
 * Register a new device entry.
 *
 * @param string $cookie_id
 * @return int device_id
 */
function register_device($cookie_id) {
    $cookie_id  = sanitize($cookie_id, SQL);
    $user_agent = sanitize($_SERVER['HTTP_USER_AGENT'] ?? '', SQL);
    $ip         = sanitize($_SERVER['REMOTE_ADDR'] ?? '', SQL);

    db_query("INSERT INTO device (cookie_id, user_agent, ip_address, created) VALUES ('$cookie_id', '$user_agent', '$ip', NOW())");
    return db_insert_id();
}

/**
 * Get a device by cookie_id.
 *
 * @param string $cookie_id
 * @return array|false
 */
function get_device_by_cookie($cookie_id) {
    $cookie_id = sanitize($cookie_id, SQL);
    $r = db_query("SELECT * FROM device WHERE cookie_id = '$cookie_id'");
    return db_fetch($r);
}

<?php
/**
 * deviceFunctions.php
 * Device tracking for auto-login / remember-me + session management.
 */

/**
 * Parse a simple browser name from user agent string.
 */
function parse_browser_name($ua) {
    if (!$ua) return 'Unknown';
    if (strpos($ua, 'Edg/') !== false)    return 'Edge';
    if (strpos($ua, 'OPR/') !== false)    return 'Opera';
    if (strpos($ua, 'Chrome/') !== false) return 'Chrome';
    if (strpos($ua, 'Safari/') !== false && strpos($ua, 'Chrome/') === false) return 'Safari';
    if (strpos($ua, 'Firefox/') !== false) return 'Firefox';
    if (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident/') !== false) return 'IE';
    return 'Other';
}

/**
 * Register a new device entry.
 *
 * @param string $cookie_id
 * @param int    $user_id
 * @return int device_id
 */
function register_device($cookie_id, $user_id = null) {
    $cookie_id  = sanitize($cookie_id, SQL);
    $user_agent = sanitize($_SERVER['HTTP_USER_AGENT'] ?? '', SQL);
    $ip         = sanitize($_SERVER['REMOTE_ADDR'] ?? '', SQL);
    $browser    = sanitize(parse_browser_name($_SERVER['HTTP_USER_AGENT'] ?? ''), SQL);
    $uid        = $user_id ? (int)$user_id : 'NULL';

    db_query("INSERT INTO device (user_id, cookie_id, user_agent, browser, ip_address, created, last_used)
              VALUES ($uid, '$cookie_id', '$user_agent', '$browser', '$ip', NOW(), NOW())");
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

/**
 * Track the current visitor's device via a persistent cookie.
 * Creates a new device row if no cookie exists, or touches the existing one.
 * Associates the device with the logged-in user if session has user_id.
 *
 * @return int|null device_id or null on failure
 */
function get_or_create_device() {
    $cookie_name     = 'wn_device';
    $cookie_lifetime = 86400 * 365; // 1 year

    $user_id = $_SESSION['user_id'] ?? null;

    if (isset($_COOKIE[$cookie_name])) {
        $cookie_id = $_COOKIE[$cookie_name];
        $device = get_device_by_cookie($cookie_id);

        if ($device) {
            touch_device($device['device_id']);
            // Associate device with user if logged in and device is anonymous
            if ($user_id && !$device['user_id']) {
                $did = (int)$device['device_id'];
                $uid = (int)$user_id;
                db_query("UPDATE device SET user_id = '$uid' WHERE device_id = '$did'");
            }
            $_SESSION['device_id'] = (int)$device['device_id'];
            return (int)$device['device_id'];
        }
        // Cookie exists but device row was deleted — fall through to recreate
    }

    // Create new device
    $cookie_id = generateHashCode(64);
    $device_id = register_device($cookie_id, $user_id);

    setcookie($cookie_name, $cookie_id, time() + $cookie_lifetime, '/', '', false, true);
    $_SESSION['device_id'] = $device_id;

    return $device_id;
}

/**
 * Update device last_used timestamp.
 */
function touch_device($device_id) {
    $device_id = (int) $device_id;
    db_query("UPDATE device SET last_used = NOW() WHERE device_id = '$device_id'");
}

/**
 * Get all devices for a user.
 */
function get_user_devices($user_id) {
    $uid = (int) $user_id;
    $r = db_query("SELECT d.*, ak.key_id AS api_key_id
                    FROM device d
                    LEFT JOIN api_key ak ON d.device_id = ak.device_id AND ak.remember_me = 'yes'
                    WHERE d.user_id = '$uid'
                    ORDER BY d.last_used DESC");
    $devices = [];
    while ($row = db_fetch($r)) { $devices[] = $row; }
    return $devices;
}

/**
 * Revoke a device session (delete the device and its API key).
 */
function revoke_device($device_id, $user_id) {
    $device_id = (int) $device_id;
    $uid       = (int) $user_id;

    // Only delete if the device belongs to this user
    db_query("DELETE FROM api_key WHERE device_id = '$device_id' AND user_id = '$uid'");
    db_query("DELETE FROM device WHERE device_id = '$device_id' AND user_id = '$uid'");
}

/**
 * Revoke all devices except the current one.
 */
function revoke_all_other_devices($user_id, $current_cookie_id = null) {
    $uid = (int) $user_id;

    if ($current_cookie_id) {
        $safe_cookie = sanitize($current_cookie_id, SQL);
        // Get current device_id to exclude
        $r = db_query("SELECT device_id FROM device WHERE cookie_id = '$safe_cookie' AND user_id = '$uid'");
        $current = db_fetch($r);
        $exclude = $current ? (int)$current['device_id'] : 0;

        db_query("DELETE FROM api_key WHERE user_id = '$uid' AND device_id != '$exclude'");
        db_query("DELETE FROM device WHERE user_id = '$uid' AND device_id != '$exclude'");
    } else {
        db_query("DELETE FROM api_key WHERE user_id = '$uid'");
        db_query("DELETE FROM device WHERE user_id = '$uid'");
    }
}

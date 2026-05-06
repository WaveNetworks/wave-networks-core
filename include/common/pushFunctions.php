<?php
/**
 * pushFunctions.php
 * Web Push wrapper around minishlink/web-push.
 * Handles VAPID key management, sending pushes, and expired subscription cleanup.
 */

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Get VAPID public key for client-side subscription.
 * @return string  Base64url-encoded VAPID public key, or empty string if not configured
 */
function get_vapid_public_key() {
    global $vapid_public_key;
    return $vapid_public_key ?? '';
}

/**
 * Create a configured WebPush instance.
 * @return WebPush|null  Null if VAPID keys are not configured
 */
function create_web_push_instance() {
    global $vapid_subject, $vapid_public_key, $vapid_private_key;

    if (empty($vapid_public_key) || empty($vapid_private_key)) {
        return null;
    }

    $auth = [
        'VAPID' => [
            'subject'    => $vapid_subject ?: 'mailto:admin@localhost',
            'publicKey'  => $vapid_public_key,
            'privateKey' => $vapid_private_key,
        ],
    ];

    try {
        return new WebPush($auth);
    } catch (\Exception $e) {
        error_log('WebPush init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send a push notification to all of a user's subscribed devices.
 * Automatically removes expired/invalid subscriptions.
 *
 * @param int    $user_id   Core user_id
 * @param string $shard_id  Shard name
 * @param string $title     Push title
 * @param string $body      Push body
 * @param array  $payload   Full payload data (title, body, action_url, tag, etc.)
 * @return int              Number of successful deliveries
 */
function send_push_to_user($user_id, $shard_id, $title, $body, $payload = []) {
    $webPush = create_web_push_instance();
    if (!$webPush) {
        return 0;
    }

    $user_id = (int)$user_id;
    prime_shard($shard_id);

    // Get all subscriptions for this user
    $r = db_query_shard($shard_id,
        "SELECT * FROM push_subscription WHERE user_id = '$user_id'");
    $subscriptions = db_fetch_all($r);

    if (empty($subscriptions)) {
        return 0;
    }

    $payload_json = json_encode(array_merge([
        'title' => $title,
        'body'  => $body,
    ], $payload));

    // Queue all subscriptions
    foreach ($subscriptions as $sub) {
        $subscription = Subscription::create([
            'endpoint'        => $sub['endpoint'],
            'publicKey'       => $sub['p256dh_key'],
            'authToken'       => $sub['auth_key'],
            'contentEncoding' => 'aesgcm',
        ]);

        $webPush->queueNotification($subscription, $payload_json);
    }

    // Flush and process results
    $success_count = 0;
    $sub_index = 0;

    foreach ($webPush->flush() as $report) {
        $sub = $subscriptions[$sub_index] ?? null;
        $sub_id = $sub ? (int)$sub['subscription_id'] : 0;

        if ($report->isSuccess()) {
            $success_count++;
            // Update last_used
            if ($sub_id) {
                db_query_shard($shard_id,
                    "UPDATE push_subscription SET last_used = NOW() WHERE subscription_id = '$sub_id'");
            }
        } else {
            $status_code = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;

            // 410 Gone or 404 = subscription expired, remove it
            if ($status_code === 410 || $status_code === 404) {
                if ($sub_id) {
                    db_query_shard($shard_id,
                        "DELETE FROM push_subscription WHERE subscription_id = '$sub_id'");
                }
            }

            error_log("Push failed for subscription $sub_id: " .
                $report->getReason() . " (HTTP $status_code)");
        }

        $sub_index++;
    }

    return $success_count;
}

/**
 * Save or update a push subscription for a user.
 *
 * @param int    $user_id    Core user_id
 * @param string $shard_id   Shard name
 * @param string $endpoint   Push endpoint URL
 * @param string $p256dh     P-256 Diffie-Hellman public key
 * @param string $auth       Authentication secret
 * @param string $user_agent Optional user agent string
 * @return bool
 */
function save_push_subscription($user_id, $shard_id, $endpoint, $p256dh, $auth, $user_agent = '') {
    $user_id = (int)$user_id;
    $s_endpoint = sanitize($endpoint, SQL);
    $s_p256dh   = sanitize($p256dh, SQL);
    $s_auth     = sanitize($auth, SQL);
    $s_ua       = sanitize($user_agent, SQL);

    prime_shard($shard_id);
    return (bool)db_query_shard($shard_id, "INSERT INTO push_subscription
        (user_id, endpoint, p256dh_key, auth_key, user_agent, created)
        VALUES ('$user_id', '$s_endpoint', '$s_p256dh', '$s_auth', '$s_ua', NOW())
        ON DUPLICATE KEY UPDATE
            user_id = '$user_id',
            p256dh_key = '$s_p256dh',
            auth_key = '$s_auth',
            user_agent = '$s_ua',
            last_used = NOW()");
}

/**
 * Remove a push subscription by endpoint.
 */
function remove_push_subscription($user_id, $shard_id, $endpoint) {
    $user_id    = (int)$user_id;
    $s_endpoint = sanitize($endpoint, SQL);

    prime_shard($shard_id);
    return (bool)db_query_shard($shard_id,
        "DELETE FROM push_subscription WHERE user_id = '$user_id' AND endpoint = '$s_endpoint'");
}

/**
 * Are the VAPID keys managed by the container environment (Docker)?
 * If so, the in-app Generate/Rotate flow is hidden — operators must
 * change the env vars on their container instead.
 */
function vapid_is_env_managed() {
    return getenv('VAPID_PUBLIC_KEY') !== false && getenv('VAPID_PUBLIC_KEY') !== '';
}

/**
 * Path to the writable notifications config partial.
 */
function vapid_config_path() {
    return __DIR__ . '/../../config/notifications_config.php';
}

/**
 * Is the directory holding notifications_config.php writable by PHP?
 */
function vapid_config_writable() {
    return is_writable(dirname(vapid_config_path()));
}

/**
 * Atomically write the notifications config partial.
 * Writes to a sibling .tmp file and renames into place so a half-flushed
 * file never gets included. Calls opcache_invalidate() (and falls back to
 * touch()) to bust opcache without bouncing PHP-FPM.
 *
 * @param string $subject     mailto: subject
 * @param string $public_key  base64url VAPID public key
 * @param string $private_key base64url VAPID private key
 * @return array              ['ok' => bool, 'error' => string|null]
 */
function write_vapid_config_atomically($subject, $public_key, $private_key) {
    $target = vapid_config_path();
    $dir    = dirname($target);

    if (!is_dir($dir) || !is_writable($dir)) {
        return ['ok' => false, 'error' => 'Config directory is not writable: ' . $dir];
    }

    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));

    $body  = "<?php\n";
    $body .= "/**\n * notifications_config.php\n";
    $body .= " * Auto-generated by the admin UI (Notifications → Push Setup).\n";
    $body .= " * Edit through the UI or replace by hand — see notifications_config.sample.php.\n";
    $body .= " * Last updated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
    $body .= " */\n\n";
    $body .= '$vapid_subject     = ' . var_export((string)$subject, true) . ";\n";
    $body .= '$vapid_public_key  = ' . var_export((string)$public_key, true) . ";\n";
    $body .= '$vapid_private_key = ' . var_export((string)$private_key, true) . ";\n";

    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Failed to write temp file in ' . $dir];
    }
    @chmod($tmp, 0600);

    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Failed to atomically rename into place'];
    }

    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($target, true);
    } else {
        @touch($target);
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Get all push subscriptions for a user.
 */
function get_user_push_subscriptions($user_id, $shard_id) {
    $user_id = (int)$user_id;
    prime_shard($shard_id);
    $r = db_query_shard($shard_id,
        "SELECT subscription_id, user_agent, created, last_used FROM push_subscription WHERE user_id = '$user_id' ORDER BY created DESC");
    return db_fetch_all($r);
}

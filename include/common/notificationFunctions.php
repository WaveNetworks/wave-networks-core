<?php
/**
 * notificationFunctions.php
 * Core notification helpers — send, broadcast, categories, preferences.
 * All per-user data (notifications, preferences, subscriptions) lives on shards.
 * Category definitions live on main DB.
 */

// ─── SEND NOTIFICATION ──────────────────────────────────────────────────────

/**
 * Send a notification to a single user.
 * Always creates an in-app notification. Sends push based on user preference.
 *
 * @param int    $user_id       Core user_id
 * @param string $shard_id      Shard name (e.g. 'shard1')
 * @param string $category_slug Category slug (e.g. 'security', 'admin_broadcast')
 * @param string $title         Notification title
 * @param string $body          Notification body text
 * @param array  $opts          Optional: action_url, action_label, source_app
 * @return int|false            notification_id on success, false on failure
 */
function send_notification($user_id, $shard_id, $category_slug, $title, $body, $opts = []) {
    $user_id  = (int)$user_id;
    $action_url   = $opts['action_url']   ?? null;
    $action_label = $opts['action_label'] ?? null;
    $source_app   = $opts['source_app']   ?? null;

    prime_shard($shard_id);

    // Check user preference for this category
    $pref = _get_user_pref($user_id, $shard_id, $category_slug);
    $frequency    = $pref['frequency']    ?? 'realtime';
    $push_enabled = $pref['push_enabled'] ?? 1;

    // Determine push_sent flag:
    // - 'off' or push disabled → mark as already sent (skip push entirely)
    // - 'daily'/'weekly' → leave as 0 (cron will batch later)
    // - 'realtime' → will be set to 1 after immediate push attempt
    $push_sent = ($frequency === 'off' || !$push_enabled) ? 1 : 0;

    $s_slug   = sanitize($category_slug, SQL);
    $s_title  = sanitize($title, SQL);
    $s_body   = sanitize($body ?? '', SQL);
    $s_url    = $action_url   ? "'" . sanitize($action_url, SQL) . "'"     : 'NULL';
    $s_label  = $action_label ? "'" . sanitize($action_label, SQL) . "'"   : 'NULL';
    $s_app    = $source_app   ? "'" . sanitize($source_app, SQL) . "'"     : 'NULL';

    $r = db_query_shard($shard_id, "INSERT INTO notification
        (user_id, category_slug, title, body, action_url, action_label, is_read, push_sent, source_app, created)
        VALUES ('$user_id', '$s_slug', '$s_title', '$s_body', $s_url, $s_label, 0, '$push_sent', $s_app, NOW())");

    if (!$r) {
        return false;
    }

    $notification_id = shard_insert_id($shard_id);

    // Realtime push: send immediately
    if ($frequency === 'realtime' && $push_enabled && function_exists('send_push_to_user')) {
        $payload = [
            'title'      => $title,
            'body'       => $body,
            'action_url' => $action_url ?: '',
            'tag'        => 'wn-' . $category_slug,
        ];
        send_push_to_user($user_id, $shard_id, $title, $body, $payload);

        // Mark push as sent
        db_query_shard($shard_id, "UPDATE notification SET push_sent = 1 WHERE notification_id = '$notification_id'");
    }

    return $notification_id;
}

// ─── BROADCAST ──────────────────────────────────────────────────────────────

/**
 * Send a notification to all users across all shards.
 *
 * @param string $category_slug Category slug
 * @param string $title         Notification title
 * @param string $body          Notification body
 * @param array  $opts          Optional: action_url, action_label, source_app
 * @return int                  Total notifications sent
 */
function broadcast_notification($category_slug, $title, $body, $opts = []) {
    global $db;
    $total = 0;

    // Get all users grouped by shard
    $r = $db->query("SELECT user_id, shard_id FROM user WHERE is_confirmed = 1");
    $users = $r->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $u) {
        $result = send_notification((int)$u['user_id'], $u['shard_id'], $category_slug, $title, $body, $opts);
        if ($result !== false) {
            $total++;
        }
    }

    return $total;
}

// ─── READ / MARK ────────────────────────────────────────────────────────────

/**
 * Get notifications for a user from their shard.
 */
function get_user_notifications($user_id, $shard_id, $limit = 20, $offset = 0) {
    $user_id = (int)$user_id;
    $limit   = (int)$limit;
    $offset  = (int)$offset;

    prime_shard($shard_id);
    $r = db_query_shard($shard_id,
        "SELECT * FROM notification WHERE user_id = '$user_id' ORDER BY created DESC LIMIT $offset, $limit");

    $notifications = db_fetch_all($r);

    // Enrich with category metadata
    $categories = get_notification_categories_indexed();
    foreach ($notifications as &$n) {
        $cat = $categories[$n['category_slug']] ?? null;
        $n['category_name'] = $cat ? $cat['name'] : ucfirst(str_replace('_', ' ', $n['category_slug']));
        $n['category_icon'] = $cat ? $cat['icon'] : 'bi-bell';
    }
    unset($n);

    return $notifications;
}

/**
 * Get unread notification count for a user.
 */
function get_unread_count($user_id, $shard_id) {
    $user_id = (int)$user_id;
    prime_shard($shard_id);
    $r = db_query_shard($shard_id, "SELECT COUNT(*) as cnt FROM notification WHERE user_id = '$user_id' AND is_read = 0");
    $row = db_fetch($r);
    return (int)($row['cnt'] ?? 0);
}

/**
 * Mark a single notification as read.
 */
function mark_notification_read($notification_id, $user_id, $shard_id) {
    $notification_id = (int)$notification_id;
    $user_id = (int)$user_id;
    prime_shard($shard_id);
    return db_query_shard($shard_id,
        "UPDATE notification SET is_read = 1 WHERE notification_id = '$notification_id' AND user_id = '$user_id'");
}

/**
 * Mark all notifications as read for a user.
 */
function mark_all_notifications_read($user_id, $shard_id) {
    $user_id = (int)$user_id;
    prime_shard($shard_id);
    return db_query_shard($shard_id,
        "UPDATE notification SET is_read = 1 WHERE user_id = '$user_id' AND is_read = 0");
}

// ─── CATEGORIES ─────────────────────────────────────────────────────────────

/**
 * Get all notification categories from main DB. Cached per request.
 */
function get_notification_categories() {
    static $cache = null;
    if ($cache !== null) return $cache;

    global $db;
    try {
        $r = $db->query("SELECT * FROM notification_category ORDER BY is_system DESC, name ASC");
        $cache = $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Get categories indexed by slug. Cached per request.
 */
function get_notification_categories_indexed() {
    static $indexed = null;
    if ($indexed !== null) return $indexed;

    $indexed = [];
    foreach (get_notification_categories() as $cat) {
        $indexed[$cat['slug']] = $cat;
    }
    return $indexed;
}

/**
 * Register a notification category (idempotent). Child apps call at bootstrap.
 */
function register_notification_category($slug, $name, $description = '', $opts = []) {
    global $db;
    $s_slug  = sanitize($slug, SQL);
    $s_name  = sanitize($name, SQL);
    $s_desc  = sanitize($description, SQL);
    $s_icon  = sanitize($opts['icon'] ?? 'bi-bell', SQL);
    $is_sys  = (int)($opts['is_system'] ?? 0);
    $allow   = (int)($opts['allow_frequency_override'] ?? 1);
    $s_freq  = sanitize($opts['default_frequency'] ?? 'realtime', SQL);
    $s_app   = isset($opts['created_by_app']) ? "'" . sanitize($opts['created_by_app'], SQL) . "'" : 'NULL';

    return $db->exec("INSERT INTO notification_category
        (slug, name, description, icon, is_system, allow_frequency_override, default_frequency, created_by_app)
        VALUES ('$s_slug', '$s_name', '$s_desc', '$s_icon', '$is_sys', '$allow', '$s_freq', $s_app)
        ON DUPLICATE KEY UPDATE name = '$s_name', description = '$s_desc', icon = '$s_icon'");
}

// ─── USER PREFERENCES ───────────────────────────────────────────────────────

/**
 * Get all categories with the user's preference settings.
 * Returns categories enriched with 'frequency' and 'push_enabled' per user.
 */
function get_user_notification_preferences($user_id, $shard_id) {
    $user_id = (int)$user_id;
    $categories = get_notification_categories();

    // Load user preferences from shard
    prime_shard($shard_id);
    $r = db_query_shard($shard_id,
        "SELECT * FROM notification_preference WHERE user_id = '$user_id'");
    $prefs = [];
    while ($row = db_fetch($r)) {
        $prefs[$row['category_slug']] = $row;
    }

    // Merge
    $result = [];
    foreach ($categories as $cat) {
        $p = $prefs[$cat['slug']] ?? null;
        $result[] = [
            'category_id'              => $cat['category_id'],
            'slug'                     => $cat['slug'],
            'name'                     => $cat['name'],
            'description'              => $cat['description'],
            'icon'                     => $cat['icon'],
            'is_system'                => (int)$cat['is_system'],
            'allow_frequency_override' => (int)$cat['allow_frequency_override'],
            'default_frequency'        => $cat['default_frequency'],
            'frequency'                => $p ? $p['frequency']     : $cat['default_frequency'],
            'push_enabled'             => $p ? (int)$p['push_enabled'] : 1,
        ];
    }

    return $result;
}

/**
 * Set a user's preference for a notification category.
 */
function set_notification_preference($user_id, $shard_id, $category_slug, $frequency, $push_enabled) {
    $user_id      = (int)$user_id;
    $push_enabled = (int)$push_enabled;
    $s_slug       = sanitize($category_slug, SQL);
    $s_freq       = sanitize($frequency, SQL);

    prime_shard($shard_id);
    return db_query_shard($shard_id, "INSERT INTO notification_preference
        (user_id, category_slug, frequency, push_enabled)
        VALUES ('$user_id', '$s_slug', '$s_freq', '$push_enabled')
        ON DUPLICATE KEY UPDATE frequency = '$s_freq', push_enabled = '$push_enabled'");
}

// ─── INTERNAL HELPERS ───────────────────────────────────────────────────────

/**
 * Get a user's preference for a specific category.
 * Falls back to category defaults if no preference row exists.
 */
function _get_user_pref($user_id, $shard_id, $category_slug) {
    $user_id = (int)$user_id;
    $s_slug  = sanitize($category_slug, SQL);

    prime_shard($shard_id);
    $r = db_query_shard($shard_id,
        "SELECT * FROM notification_preference WHERE user_id = '$user_id' AND category_slug = '$s_slug'");
    $pref = db_fetch($r);

    if ($pref) {
        return $pref;
    }

    // Fall back to category default
    $categories = get_notification_categories_indexed();
    $cat = $categories[$category_slug] ?? null;
    return [
        'frequency'    => $cat ? $cat['default_frequency'] : 'realtime',
        'push_enabled' => 1,
    ];
}

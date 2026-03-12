<?php
/**
 * Notification Actions (authenticated users)
 * Actions: getNotifications, markNotificationRead, markAllNotificationsRead,
 *          getNotificationPreferences, saveNotificationPreference,
 *          registerPushSubscription, unregisterPushSubscription, getVapidPublicKey
 */

// ─── GET NOTIFICATIONS ──────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'getNotifications') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        $limit  = max(1, min(50, (int)($_POST['limit'] ?? 20)));
        $offset = max(0, (int)($_POST['offset'] ?? 0));

        $data['notifications'] = get_user_notifications(
            $_SESSION['user_id'], $_SESSION['shard_id'], $limit, $offset
        );
        $data['unread_count'] = get_unread_count($_SESSION['user_id'], $_SESSION['shard_id']);
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── MARK NOTIFICATION READ ─────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'markNotificationRead') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $notification_id = (int)($_POST['notification_id'] ?? 0);
    if (!$notification_id) { $errs['id'] = 'Notification ID required.'; }

    if (count($errs) <= 0) {
        mark_notification_read($notification_id, $_SESSION['user_id'], $_SESSION['shard_id']);
        $_SESSION['success'] = 'Notification marked as read.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── MARK ALL READ ──────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'markAllNotificationsRead') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        mark_all_notifications_read($_SESSION['user_id'], $_SESSION['shard_id']);
        $_SESSION['success'] = 'All notifications marked as read.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── GET PREFERENCES ────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'getNotificationPreferences') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        $data['preferences'] = get_user_notification_preferences(
            $_SESSION['user_id'], $_SESSION['shard_id']
        );
        $data['push_subscriptions'] = function_exists('get_user_push_subscriptions')
            ? get_user_push_subscriptions($_SESSION['user_id'], $_SESSION['shard_id'])
            : [];
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── SAVE PREFERENCE ────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'saveNotificationPreference') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $category_slug = trim($_POST['category_slug'] ?? '');
    $frequency     = trim($_POST['frequency'] ?? 'realtime');
    $push_enabled  = (int)($_POST['push_enabled'] ?? 1);

    if (!$category_slug) { $errs['category'] = 'Category is required.'; }
    if (!in_array($frequency, ['realtime', 'daily', 'weekly', 'off'])) {
        $errs['frequency'] = 'Invalid frequency.';
    }

    // Check if user is allowed to override this category's frequency
    if (count($errs) <= 0) {
        $categories = get_notification_categories_indexed();
        $cat = $categories[$category_slug] ?? null;
        if (!$cat) {
            $errs['category'] = 'Category not found.';
        } elseif (!(int)$cat['allow_frequency_override']) {
            $errs['category'] = 'This category does not allow frequency changes.';
        }
    }

    if (count($errs) <= 0) {
        set_notification_preference(
            $_SESSION['user_id'], $_SESSION['shard_id'],
            $category_slug, $frequency, $push_enabled
        );
        $_SESSION['success'] = 'Notification preference saved.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── REGISTER PUSH SUBSCRIPTION ─────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'registerPushSubscription') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $endpoint = trim($_POST['endpoint'] ?? '');
    $p256dh   = trim($_POST['p256dh'] ?? '');
    $auth     = trim($_POST['auth'] ?? '');

    if (!$endpoint) { $errs['endpoint'] = 'Push endpoint is required.'; }
    if (!$p256dh)   { $errs['p256dh'] = 'Public key is required.'; }
    if (!$auth)     { $errs['auth_key'] = 'Auth key is required.'; }

    if (count($errs) <= 0) {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        save_push_subscription(
            $_SESSION['user_id'], $_SESSION['shard_id'],
            $endpoint, $p256dh, $auth, $user_agent
        );
        $_SESSION['success'] = 'Push subscription registered.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── UNREGISTER PUSH SUBSCRIPTION ───────────────────────────────────────────

if (($_POST['action'] ?? '') == 'unregisterPushSubscription') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $endpoint = trim($_POST['endpoint'] ?? '');
    if (!$endpoint) { $errs['endpoint'] = 'Push endpoint is required.'; }

    if (count($errs) <= 0) {
        remove_push_subscription($_SESSION['user_id'], $_SESSION['shard_id'], $endpoint);
        $_SESSION['success'] = 'Push subscription removed.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── GET VAPID PUBLIC KEY ───────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'getVapidPublicKey') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        $data['vapid_public_key'] = get_vapid_public_key();
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

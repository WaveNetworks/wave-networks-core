# Notification system

Main DB: notification_category (slug, name, icon, is_system, default_frequency)
Shard DB: notification (per-user), notification_preference, push_subscription

Key functions (available to child apps via common.php):
  send_notification($user_id, $shard_id, $category_slug, $title, $body, $opts)
  broadcast_notification($category_slug, $title, $body, $opts)
  register_notification_category($slug, $name, $desc, $opts)

Web Push: VAPID keys in config. Service worker at admin/sw.js.
  Realtime notifications send push immediately.
  Daily/weekly batched by cron/push_digest.php.

Helpers: include/common/notificationFunctions.php, pushFunctions.php
Actions: include/actions/memberActions/notificationActions.php,
         notificationAdminActions.php

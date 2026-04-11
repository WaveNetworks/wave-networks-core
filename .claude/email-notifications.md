# Email Queue, Notifications & Cron

## Email queue system
Main DB stores: email_settings (SMTP config, throttle limits, allowed senders)
                email_queue (pending/sent/failed messages)

Sending: queue_email($to, $subject, $body, $opts) inserts into email_queue.
         cron/cron.php processes the queue respecting throttle limits.
         Child apps call queue_email() after including common.php — no SMTP config needed.

Admin UI: views/email.php — SMTP settings, throttle config, allowed senders, queue log.
Helpers: include/common/emailFunctions.php, emailQueueFunctions.php
Actions: include/actions/memberActions/emailSettingsActions.php

Throttle limits (configurable in admin):
  per_minute, per_hour, per_day — checked before each send batch.

Allowed senders: email_allowed_senders table. Only listed addresses can appear
  in the From header. Default from/reply-to configured in email_settings.

## Notification system
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

## Cron jobs
All cron scripts: CLI-only, bootstrap via include/common_readonly.php.
common_readonly.php loads config + DB + helpers but NO actions, NO session guard.

Scripts:
  cron/cron.php                  — every minute: email queue + scheduled tasks
  cron/sync_users.php            — migration sync from external DB
  cron/push_digest.php           — daily push digest (weekly on Mondays)
  cron/cleanup_notifications.php — monthly: prune old notifications + stale subscriptions

Subdirectories (cron/minutes/, days/, weeks/, months/) hold task scripts
executed by cron.php on their respective intervals.

Daily (cron/days/1/):
  cleanup_error_log.php        — deletes error_log entries older than 30 days
  cleanup_expired_tokens.php   — deletes forgot tokens > 7 days, api_key > 90 days

Monthly (cron/months/1/):
  purge_deleted_users.php      — deletes read notifications > 90 days, orphaned devices

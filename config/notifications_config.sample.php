<?php
/**
 * notifications_config.sample.php
 *
 * Reference template for admin/config/notifications_config.php.
 *
 * The admin UI (Notifications → Push Setup) writes the real file at
 * admin/config/notifications_config.php (gitignored). It contains
 * notification-system settings that need to live on disk rather than the
 * DB — currently just the VAPID keys for Web Push.
 *
 * If you prefer to manage these by hand, copy this file to
 * notifications_config.php and fill in real values. The bootstrap loads
 * notifications_config.php after the main config block, so values here
 * override anything set in config.php / env vars.
 *
 * Generate VAPID keys (run from the admin/ directory):
 *
 *   php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
 *
 * Or use the Generate VAPID Keys button in Notifications → Push Setup.
 */

$vapid_subject     = 'mailto:admin@yourdomain.com';
$vapid_public_key  = '';
$vapid_private_key = '';

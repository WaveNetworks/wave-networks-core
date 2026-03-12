<?php
/**
 * cron/cleanup_notifications.php
 * Monthly cleanup of old notifications and stale push subscriptions.
 * CLI only. Run via crontab:
 *   0 3 1 * * php /path/to/admin/cron/cleanup_notifications.php
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

include(__DIR__ . '/../include/common_readonly.php');

$notification_days  = 90;  // Delete notifications older than this
$subscription_days  = 90;  // Delete push subscriptions not used in this many days

echo "[cleanup] Started at " . date('Y-m-d H:i:s') . "\n";

$total_notif_deleted = 0;
$total_sub_deleted   = 0;

foreach ($shardConfigs as $shard_id => $cfg) {
    prime_shard($shard_id);

    // Delete old notifications
    $r = db_query_shard($shard_id,
        "DELETE FROM notification WHERE created < DATE_SUB(NOW(), INTERVAL $notification_days DAY)");
    $notif_count = $r ? $r->rowCount() : 0;
    $total_notif_deleted += $notif_count;

    // Delete stale push subscriptions (never used or not used recently)
    $r2 = db_query_shard($shard_id,
        "DELETE FROM push_subscription
         WHERE (last_used IS NOT NULL AND last_used < DATE_SUB(NOW(), INTERVAL $subscription_days DAY))
            OR (last_used IS NULL AND created < DATE_SUB(NOW(), INTERVAL $subscription_days DAY))");
    $sub_count = $r2 ? $r2->rowCount() : 0;
    $total_sub_deleted += $sub_count;

    echo "  [$shard_id] Deleted $notif_count notifications, $sub_count stale subscriptions.\n";
}

echo "[cleanup] Totals: $total_notif_deleted notifications, $total_sub_deleted subscriptions deleted.\n";
echo "[cleanup] Finished at " . date('Y-m-d H:i:s') . "\n";

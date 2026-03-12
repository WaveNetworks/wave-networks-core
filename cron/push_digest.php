<?php
/**
 * cron/push_digest.php
 * Sends batched push notification digests for users with daily/weekly frequency.
 * CLI only. Run via crontab:
 *   0 8 * * * php /path/to/admin/cron/push_digest.php
 *
 * - Daily users: digest sent every run
 * - Weekly users: digest sent only on Mondays (configurable below)
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

include(__DIR__ . '/../include/common_readonly.php');

$weekly_day = 1; // 1 = Monday (ISO day of week)
$is_weekly_day = ((int)date('N') === $weekly_day);

$total_sent    = 0;
$total_users   = 0;
$total_errors  = 0;

echo "[push_digest] Started at " . date('Y-m-d H:i:s') . "\n";

// Iterate every shard
foreach ($shardConfigs as $shard_id => $cfg) {
    prime_shard($shard_id);

    // Find users with pending push notifications (push_sent = 0)
    $r = db_query_shard($shard_id,
        "SELECT DISTINCT user_id FROM notification WHERE push_sent = 0");

    $user_ids = [];
    while ($row = db_fetch($r)) {
        $user_ids[] = (int)$row['user_id'];
    }

    if (empty($user_ids)) {
        echo "  [$shard_id] No pending notifications.\n";
        continue;
    }

    echo "  [$shard_id] " . count($user_ids) . " user(s) with pending notifications.\n";

    foreach ($user_ids as $uid) {
        // Get all pending notifications for this user
        $r2 = db_query_shard($shard_id,
            "SELECT notification_id, category_slug, title FROM notification
             WHERE user_id = '$uid' AND push_sent = 0
             ORDER BY created ASC");

        $pending = [];
        while ($n = db_fetch($r2)) {
            $pending[] = $n;
        }

        if (empty($pending)) continue;

        // Group by category to check per-category frequency
        $categories = get_notification_categories_indexed();
        $daily_ids  = [];
        $weekly_ids = [];

        foreach ($pending as $n) {
            $pref = _get_user_pref($uid, $shard_id, $n['category_slug']);
            $freq = $pref['frequency'] ?? 'realtime';
            $push = $pref['push_enabled'] ?? 1;

            if (!$push || $freq === 'off') {
                // User disabled push or turned off — mark as sent (skip)
                $nid = (int)$n['notification_id'];
                db_query_shard($shard_id, "UPDATE notification SET push_sent = 1 WHERE notification_id = '$nid'");
                continue;
            }

            if ($freq === 'daily') {
                $daily_ids[] = (int)$n['notification_id'];
            } elseif ($freq === 'weekly') {
                $weekly_ids[] = (int)$n['notification_id'];
            }
            // 'realtime' with push_sent=0 shouldn't normally exist (sent immediately),
            // but if it does, include it in daily batch as a catch-up
            if ($freq === 'realtime') {
                $daily_ids[] = (int)$n['notification_id'];
            }
        }

        // Determine which IDs to send now
        $send_ids = $daily_ids;
        if ($is_weekly_day) {
            $send_ids = array_merge($send_ids, $weekly_ids);
        }

        if (empty($send_ids)) continue;

        $count = count($send_ids);
        $title = "You have $count new notification" . ($count > 1 ? 's' : '');
        $body  = "Check your notifications for updates.";

        // Send digest push
        if (function_exists('send_push_to_user')) {
            $payload = [
                'title'      => $title,
                'body'       => $body,
                'action_url' => '',
                'tag'        => 'wn-digest',
            ];
            $delivered = send_push_to_user($uid, $shard_id, $title, $body, $payload);

            if ($delivered > 0) {
                $total_sent++;
            } else {
                $total_errors++;
            }
        }

        // Mark all sent IDs as push_sent = 1
        $id_list = implode(',', $send_ids);
        db_query_shard($shard_id,
            "UPDATE notification SET push_sent = 1 WHERE notification_id IN ($id_list)");

        $total_users++;
    }
}

echo "[push_digest] Done. Users processed: $total_users, Pushes sent: $total_sent, Errors: $total_errors\n";
echo "[push_digest] Finished at " . date('Y-m-d H:i:s') . "\n";

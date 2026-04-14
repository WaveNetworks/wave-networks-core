<?php
/**
 * cron/days/1/cleanup_action_log.php
 * Daily cleanup: TTL deletes, per-shard summary roll-up, cross-shard metric aggregation.
 * Idempotent — safe to run multiple times for the same day.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!isset($db)) {
    include(__DIR__ . '/../../../include/common_readonly.php');
}

global $db, $shardConfigs;

$total_deleted   = 0;
$total_summarized = 0;
$shard_count     = 0;

$yesterday = date('Y-m-d', strtotime('-1 day'));

// ── 1. Per-shard TTL delete + roll-up ──
foreach ($shardConfigs as $shard_name => $cfg) {
    prime_shard($shard_name);
    $shard_count++;

    // TTL delete expired rows
    $stmt = db_query_shard_prepared($shard_name,
        "DELETE FROM user_action_log WHERE expires_at IS NOT NULL AND expires_at < NOW()", []);
    $total_deleted += $stmt->rowCount();

    // Roll up yesterday's activity into user_action_summary
    $stmt = db_query_shard_prepared($shard_name,
        "INSERT INTO user_action_summary
            (user_id, device_id, source_app, page, action, day,
             event_count, avg_duration_ms, first_seen, last_seen, terminal_action_count)
         SELECT user_id, device_id, source_app, page, action, DATE(created),
                COUNT(*), AVG(duration_ms), MIN(created), MAX(created),
                SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END)
         FROM user_action_log
         WHERE created >= ? AND created < ?
         GROUP BY user_id, device_id, source_app, page, action, DATE(created)
         ON DUPLICATE KEY UPDATE
            event_count = event_count + VALUES(event_count),
            last_seen = GREATEST(last_seen, VALUES(last_seen)),
            avg_duration_ms = (avg_duration_ms + VALUES(avg_duration_ms)) / 2,
            terminal_action_count = terminal_action_count + VALUES(terminal_action_count)",
        [$yesterday, date('Y-m-d')]);
    $total_summarized += $stmt->rowCount();
}

// ── 2. Main DB device_action_log TTL delete ──
$stmt = db_query_prepared(
    "DELETE FROM device_action_log WHERE expires_at IS NOT NULL AND expires_at < NOW()", []);
$device_deleted = $stmt->rowCount();
$total_deleted += $device_deleted;

// ── 3. Cross-shard feature_metric_daily aggregation ──
$metrics = []; // key: "source_app|page|action"

// 3a. Aggregate from each shard's user_action_summary
foreach ($shardConfigs as $shard_name => $cfg) {
    prime_shard($shard_name);
    $stmt = db_query_shard_prepared($shard_name,
        "SELECT source_app, page, action,
                COUNT(DISTINCT user_id) AS user_count,
                COUNT(DISTINCT device_id) AS device_count,
                SUM(event_count) AS event_count,
                AVG(avg_duration_ms) AS avg_duration_ms,
                SUM(terminal_action_count) AS terminal_action_count
         FROM user_action_summary
         WHERE day = ?
         GROUP BY source_app, page, action",
        [$yesterday]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['source_app'] . '|' . ($row['page'] ?? '') . '|' . ($row['action'] ?? '');
        if (!isset($metrics[$key])) {
            $metrics[$key] = [
                'source_app'  => $row['source_app'],
                'page'        => $row['page'],
                'action'      => $row['action'],
                'user_count'  => 0,
                'device_count' => 0,
                'event_count' => 0,
                'duration_sum' => 0,
                'duration_n'  => 0,
                'terminal_action_count' => 0,
            ];
        }
        $m = &$metrics[$key];
        $m['user_count']  += (int)$row['user_count'];
        $m['device_count'] += (int)$row['device_count'];
        $m['event_count'] += (int)$row['event_count'];
        if ($row['avg_duration_ms'] !== null) {
            $m['duration_sum'] += (float)$row['avg_duration_ms'] * (int)$row['event_count'];
            $m['duration_n']   += (int)$row['event_count'];
        }
        $m['terminal_action_count'] += (int)$row['terminal_action_count'];
        unset($m);
    }
}

// 3b. Include anonymous device_action_log from main DB
$stmt = db_query_prepared(
    "SELECT source_app, page, action,
            COUNT(DISTINCT device_id) AS device_count,
            COUNT(*) AS event_count,
            AVG(duration_ms) AS avg_duration_ms,
            SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END) AS terminal_action_count
     FROM device_action_log
     WHERE created >= ? AND created < ?
     GROUP BY source_app, page, action",
    [$yesterday, date('Y-m-d')]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $key = $row['source_app'] . '|' . ($row['page'] ?? '') . '|' . ($row['action'] ?? '');
    if (!isset($metrics[$key])) {
        $metrics[$key] = [
            'source_app'  => $row['source_app'],
            'page'        => $row['page'],
            'action'      => $row['action'],
            'user_count'  => 0,
            'device_count' => 0,
            'event_count' => 0,
            'duration_sum' => 0,
            'duration_n'  => 0,
            'terminal_action_count' => 0,
        ];
    }
    $m = &$metrics[$key];
    $m['device_count'] += (int)$row['device_count'];
    $m['event_count']  += (int)$row['event_count'];
    if ($row['avg_duration_ms'] !== null) {
        $m['duration_sum'] += (float)$row['avg_duration_ms'] * (int)$row['event_count'];
        $m['duration_n']   += (int)$row['event_count'];
    }
    $m['terminal_action_count'] += (int)$row['terminal_action_count'];
    unset($m);
}

// 3c. Upsert into feature_metric_daily
$metric_rows = 0;
foreach ($metrics as $m) {
    $avg_dur = $m['duration_n'] > 0 ? round($m['duration_sum'] / $m['duration_n']) : null;

    db_query_prepared(
        "INSERT INTO feature_metric_daily
            (source_app, page, action, day, user_count, device_count,
             event_count, avg_duration_ms, terminal_action_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            user_count = VALUES(user_count),
            device_count = VALUES(device_count),
            event_count = VALUES(event_count),
            avg_duration_ms = VALUES(avg_duration_ms),
            terminal_action_count = VALUES(terminal_action_count)",
        [
            $m['source_app'], $m['page'], $m['action'], $yesterday,
            $m['user_count'], $m['device_count'], $m['event_count'],
            $avg_dur, $m['terminal_action_count'],
        ]);
    $metric_rows++;
}

echo "    cleanup_action_log: shards=$shard_count, deleted=$total_deleted, summarized=$total_summarized, metric_rows=$metric_rows\n";

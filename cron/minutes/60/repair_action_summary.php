<?php
/**
 * cron/minutes/60/repair_action_summary.php — remove the duplicate user_action_summary rows.
 *
 * Until 2026-10-20 two bugs together filled this table with copies: the "daily" roll-up in
 * cron/days/1 ran on every minute of the 1st (cron.php read days/1 as "day 1 of the month"),
 * and a row with a NULL page or action never matched uq_user_action_day (NULLs never collide
 * in a unique key), so each run inserted the same day again. ContactSwipe's shards held 8.6M
 * rows for 321 facts. Both causes are fixed; this removes the copies already there.
 *
 * A copy is an EXACT duplicate (same user, device, app, page, action, day, counts and
 * first/last seen); the lowest summary_id of each is kept. Bounded to ~45s per run, so the
 * host never kills it midway; it resumes next hour and is a cheap no-op once clean. When a
 * shard finishes, the table is rebuilt once (OPTIMIZE) to give the disk back.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
if (!isset($db)) { include(__DIR__ . '/../../../include/common_readonly.php'); }

$budget_end = microtime(true) + 45;
$removed = 0;
foreach ((array) ($shardConfigs ?? []) as $shard_name => $cfg) {
    if (microtime(true) > $budget_end) break;
    prime_shard($shard_name);
    // Only rows that could have been copied: a NULL page or action (merged rows never repeat).
    $groups = db_query_shard_prepared($shard_name,
        "SELECT user_id, device_id, source_app, page, action, day, event_count, first_seen, last_seen,
                MIN(summary_id) AS keep_id, COUNT(*) AS n
           FROM user_action_summary
          WHERE page IS NULL OR action IS NULL
          GROUP BY user_id, device_id, source_app, page, action, day, event_count, first_seen, last_seen
         HAVING n > 1
          LIMIT 200", []);
    if (!$groups) continue;
    $rows = $groups->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        // Clean. Give the space back once, after the last copies went.
        $flag = rtrim((string) ($files_location ?? sys_get_temp_dir()), '/') . '/cron_state/summary_optimized_' . preg_replace('/\W/', '_', $shard_name);
        $had = db_query_shard_prepared($shard_name, "SELECT 1 FROM user_action_summary LIMIT 1", []);
        if ($had && !is_file($flag) && (is_dir(dirname($flag)) || @mkdir(dirname($flag), 0700, true))) {
            db_query_shard_prepared($shard_name, "OPTIMIZE TABLE user_action_summary", []);
            @touch($flag);
            echo "    summary repair: $shard_name clean, optimized\n";
        }
        continue;
    }
    foreach ($rows as $g) {
        do {
            if (microtime(true) > $budget_end) break 3;
            $st = db_query_shard_prepared($shard_name,
                "DELETE FROM user_action_summary
                  WHERE user_id = ? AND device_id <=> ? AND source_app = ? AND page <=> ? AND action <=> ?
                    AND day = ? AND event_count = ? AND first_seen = ? AND last_seen = ? AND summary_id <> ?
                  LIMIT 5000",
                [$g['user_id'], $g['device_id'], $g['source_app'], $g['page'], $g['action'], $g['day'],
                 $g['event_count'], $g['first_seen'], $g['last_seen'], $g['keep_id']]);
            $n = $st ? $st->rowCount() : 0;
            $removed += $n;
        } while ($n === 5000);
    }
}
if ($removed > 0) {
    echo "    summary repair: removed $removed duplicate rows\n";
    if (function_exists('log_cron')) { log_cron('repair_action_summary', "removed $removed duplicate rows"); }
}

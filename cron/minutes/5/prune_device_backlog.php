<?php
/**
 * cron/minutes/5/prune_device_backlog.php — keep `device`, `device_action_log` and `cron_log` bounded.
 *
 * Deletes, in batches of 5,000 and for at most ~40s per run:
 *   - anonymous devices (user_id NULL or 0) unused for DEVICE_CLEANUP_DAYS (default 30) that no
 *     api_key points at;
 *   - device_action_log rows past their expires_at (7 days);
 *   - cron_log rows older than CRON_LOG_KEEP_DAYS (default 30): every job writes one per run,
 *     a few thousand a day, and nothing ever removed them.
 *
 * This used to be one DELETE each in the daily jobs. On a host where cron had never run that
 * meant millions of rows in one statement (2026-11-07: pwt 6.4M + 6.4M, ElevateHER 1.1M + 1.2M),
 * which a shared host kills partway and rolls back, every day, so the backlog could never clear.
 * In batches it drains over a few runs and then costs one short pass a day: after a pass that
 * finds nothing, it sleeps until the next UTC day. When a backlog of 10,000+ rows has gone, both
 * tables are rebuilt once (OPTIMIZE) to give the disk back.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
if (!isset($db)) { include(__DIR__ . '/../../../include/common_readonly.php'); }

(function () {
    global $files_location;
    $state_dir = rtrim((string) ($files_location ?: sys_get_temp_dir()), '/') . '/cron_state/';
    if (!is_dir($state_dir)) { @mkdir($state_dir, 0700, true); }
    $state_file = $state_dir . 'device_prune.json';
    $state = is_file($state_file) ? (json_decode((string) @file_get_contents($state_file), true) ?: []) : [];
    $today = gmdate('Y-m-d');
    if (($state['clean_on'] ?? '') === $today) { return; }

    $days = defined('DEVICE_CLEANUP_DAYS') ? (int) DEVICE_CLEANUP_DAYS : 30;
    if ($days < 1) { $days = 30; }
    $cron_days = defined('CRON_LOG_KEEP_DAYS') ? max(7, (int) CRON_LOG_KEEP_DAYS) : 30;
    $batch = 5000;
    $budget_end = microtime(true) + 40;
    $jobs = [
        'device' => "DELETE FROM device
                      WHERE (user_id IS NULL OR user_id = 0)
                        AND COALESCE(last_used, created) < DATE_SUB(NOW(), INTERVAL $days DAY)
                        AND NOT EXISTS (SELECT 1 FROM api_key k WHERE k.device_id = device.device_id)
                      ORDER BY device_id LIMIT $batch",
        'device_action_log' => "DELETE FROM device_action_log
                                 WHERE expires_at IS NOT NULL AND expires_at < NOW() LIMIT $batch",
        'cron_log' => "DELETE FROM cron_log WHERE ran_at < DATE_SUB(NOW(), INTERVAL $cron_days DAY)
                        ORDER BY log_id LIMIT $batch",
    ];

    $removed = array_fill_keys(array_keys($jobs), 0);
    $done = [];
    foreach ($jobs as $table => $sql) {
        while (microtime(true) < $budget_end) {
            try {
                $st = db_query_prepared($sql, []);
            } catch (Throwable $e) {
                echo "    prune_device_backlog: $table: " . $e->getMessage() . "\n";
                $st = false;
            }
            if (!$st) { break; }
            $n = $st->rowCount();
            $removed[$table] += $n;
            if ($n < $batch) { $done[$table] = true; break; }
        }
    }

    foreach ($removed as $table => $n) {
        $state['pending_' . $table] = (int) ($state['pending_' . $table] ?? 0) + $n;
    }
    if (count($done) === count($jobs)) {
        // Caught up. Rebuild a table only after a real backlog went, and only count it done
        // when the rebuild ran: a failed one retries on the next run.
        $all_rebuilt = true;
        foreach (array_keys($jobs) as $table) {
            if ($state['pending_' . $table] < 10000 || microtime(true) > $budget_end + 15) {
                if ($state['pending_' . $table] >= 10000) { $all_rebuilt = false; }
                continue;
            }
            try {
                // OPTIMIZE reports failure as a result row, not an error: the last row says OK.
                $rows = db_query_prepared("OPTIMIZE TABLE `$table`", [])->fetchAll(PDO::FETCH_ASSOC);
                $last = $rows ? end($rows) : [];
                $ok = isset($last['Msg_text']) && strtoupper($last['Msg_text']) === 'OK';
            } catch (Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                echo "    prune_device_backlog: rebuilt $table after {$state['pending_' . $table]} rows\n";
                $state['pending_' . $table] = 0;
            } else {
                $all_rebuilt = false;
            }
        }
        if ($all_rebuilt) { $state['clean_on'] = $today; }
    }
    @file_put_contents($state_file, json_encode($state));

    if (array_sum($removed) > 0) {
        $msg = "removed {$removed['device']} devices, {$removed['device_action_log']} action log rows, "
             . "{$removed['cron_log']} cron log rows"
             . (count($done) === count($jobs) ? ', caught up' : ', more to go');
        echo "    prune_device_backlog: $msg\n";
        if (function_exists('log_cron')) { log_cron('prune_device_backlog', $msg); }
    }
})();

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
 *
 * Where a full batch takes over 15s (pwt's host: 5,000 rows per 40s run), device and
 * device_action_log are REBUILT instead: the rows worth keeping are copied into <table>__keep in
 * primary-key ranges over several runs, rows added meanwhile are carried over, and the two are
 * swapped with one atomic RENAME. An update to an already-copied row during the copy (a
 * last_used touch) is lost; that is telemetry, and the swap happens within a few runs.
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
    $slow = defined('DEVICE_PRUNE_SLOW_SECONDS') ? (float) DEVICE_PRUNE_SLOW_SECONDS : 15;
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

    // What a REBUILD keeps (the opposite of what the deletes remove). On a host where deleting is
    // slow (pwt 2026-11-07: one 5,000-row batch took the whole 40s, so 12.8M rows meant weeks),
    // copying the few rows worth keeping into a fresh table and swapping it in is far cheaper.
    $keep = [
        'device' => ['pk' => 'device_id', 'cond' => "NOT ((user_id IS NULL OR user_id = 0)
                         AND COALESCE(last_used, created) < DATE_SUB(NOW(), INTERVAL $days DAY))
                         OR device_id IN (SELECT device_id FROM api_key WHERE device_id IS NOT NULL)"],
        'device_action_log' => ['pk' => 'log_id', 'cond' => "expires_at IS NULL OR expires_at >= NOW()"],
    ];
    $one = function ($sql) {
        $st = db_query_prepared($sql, []);
        return $st ? $st->fetchColumn() : false;
    };

    $removed = array_fill_keys(array_keys($jobs), 0);
    $rebuilt = [];
    $done = [];
    foreach ($jobs as $table => $sql) {
        if (isset($state['rebuild'][$table])) {
            // Copy in primary-key ranges across runs, then swap. Each step is short and resumable;
            // nothing is removed until the RENAME, which is atomic.
            $rb = &$state['rebuild'][$table];
            $pk = $keep[$table]['pk'];
            $new = $table . '__keep';
            if (($rb['phase'] ?? 'start') === 'start') {
                db_query_prepared("DROP TABLE IF EXISTS `$new`", []);
                if (!db_query_prepared("CREATE TABLE `$new` LIKE `$table`", [])) {
                    unset($rb, $state['rebuild'][$table]);
                    continue;
                }
                $rb = ['phase' => 'copy', 'next' => 0, 'max' => (int) $one("SELECT COALESCE(MAX(`$pk`), 0) FROM `$table`")];
            }
            while ($rb['phase'] === 'copy' && microtime(true) < $budget_end) {
                $to = min($rb['next'] + 200000, $rb['max']);
                $st = db_query_prepared("INSERT INTO `$new` SELECT * FROM `$table`
                                          WHERE `$pk` > {$rb['next']} AND `$pk` <= $to AND ({$keep[$table]['cond']})", []);
                if (!$st) { break; }
                $rb['next'] = $to;
                if ($to >= $rb['max']) { $rb['phase'] = 'swap'; }
            }
            if ($rb['phase'] === 'swap' && microtime(true) < $budget_end) {
                // Rows added while copying, then carry the counter on so ids are never reused.
                $ok = db_query_prepared("INSERT INTO `$new` SELECT * FROM `$table` WHERE `$pk` > {$rb['max']}", []);
                $next_id = (int) $one("SELECT COALESCE(MAX(`$pk`), 0) + 1 FROM `$table`");
                $old_rows = (int) $one("SELECT COUNT(*) FROM `$table`");
                $kept = (int) $one("SELECT COUNT(*) FROM `$new`");
                if ($ok && $next_id > 1 && db_query_prepared("ALTER TABLE `$new` AUTO_INCREMENT = $next_id", [])
                    && db_query_prepared("RENAME TABLE `$table` TO `{$table}__old`, `$new` TO `$table`", [])) {
                    db_query_prepared("DROP TABLE IF EXISTS `{$table}__old`", []);
                    $rebuilt[$table] = max(0, $old_rows - $kept);
                    echo "    prune_device_backlog: rebuilt $table, kept $kept of $old_rows rows\n";
                    unset($state['rebuild'][$table]);
                    $done[$table] = true;
                }
            }
            unset($rb);
            continue;
        }
        while (microtime(true) < $budget_end) {
            $t0 = microtime(true);
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
            if (isset($keep[$table]) && microtime(true) - $t0 > $slow) {
                // A full batch this slow: deleting the backlog would take weeks. Rebuild instead.
                $state['rebuild'][$table] = ['phase' => 'start'];
                echo "    prune_device_backlog: $table deletes are slow, rebuilding it instead\n";
                break;
            }
        }
    }
    if (empty($state['rebuild'])) { unset($state['rebuild']); }

    foreach ($removed as $table => $n) {
        $state['pending_' . $table] = (int) ($state['pending_' . $table] ?? 0) + $n;
    }
    foreach ($rebuilt as $table => $n) {
        // A rebuilt table is already compact: nothing left to give back with OPTIMIZE.
        $state['pending_' . $table] = 0;
        $removed[$table] += $n;
    }
    if (count($done) === count($jobs) && empty($state['rebuild'])) {
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

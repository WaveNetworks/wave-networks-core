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

// ── 0. Acquisition funnel rollup (task #804) — MUST run BEFORE the TTL ──
// deletes below. This daily cron is the ONLY thing that prunes the raw logs
// (user_action_log 24h, device_action_log 7d), so at the top of the run
// yesterday's raw rows are all still present. We recompute BOTH the prior day
// and the current (partial) day every run; the upsert into the kept-forever
// acquisition_funnel_daily aggregate is idempotent, so re-running is safe and
// a late-arriving event or a stage first seen today is still captured. Window:
// once-daily + 24h user retention means yesterday's user-stage rows are fully
// captured only because nothing deletes them until step 1 runs immediately
// after this; never move this block below the deletes.
$funnel_rows = 0;
ensure_acquisition_tables();

$defs = db_query_prepared(
    "SELECT source_app, stage_key FROM acquisition_funnel_def", []
)->fetchAll(PDO::FETCH_ASSOC);

if ($defs) {
    $registered = [];   // "app|stage_key" => true (only count declared pairs)
    $stage_set  = [];   // distinct stage_key list for the IN clause
    $app_stages = [];   // source_app => [stage_key, ...]  (for experiment rollup)
    foreach ($defs as $d) {
        $registered[$d['source_app'] . '|' . $d['stage_key']] = true;
        $stage_set[$d['stage_key']] = true;
        $app_stages[$d['source_app']][] = $d['stage_key'];
    }
    $stage_keys = array_keys($stage_set);
    $keyPh = implode(',', array_fill(0, count($stage_keys), '?'));

    // Test-account exclusion: drop Playwright noise. Test users live on MAIN
    // (user.is_test_account); their anonymous device rows are excluded via the
    // device→user link in the device table.
    $test_user_ids = array_column(db_query_prepared(
        "SELECT user_id FROM user WHERE is_test_account = 1", []
    )->fetchAll(PDO::FETCH_ASSOC), 'user_id');
    $test_user_ids = array_map('intval', $test_user_ids);

    $test_device_ids = [];
    if ($test_user_ids) {
        $uPh = implode(',', array_fill(0, count($test_user_ids), '?'));
        $test_device_ids = array_column(db_query_prepared(
            "SELECT device_id FROM device WHERE user_id IN ($uPh)", $test_user_ids
        )->fetchAll(PDO::FETCH_ASSOC), 'device_id');
        $test_device_ids = array_map('intval', $test_device_ids);
    }

    // Recompute prior + current day each run (idempotent upsert).
    foreach ([$yesterday, date('Y-m-d')] as $day) {
        $start = $day . ' 00:00:00';
        $end   = date('Y-m-d 00:00:00', strtotime($day . ' +1 day'));

        // Accumulator keyed by "app|stage|segment". Device/user ID SETS dedup
        // across the anonymous (device_action_log) ↔ registered
        // (user_action_log) boundary by shared device_id.
        $acc = [];
        $bump = function ($app, $stage, $seg, $device_id, $user_id, $cnt) use (&$acc, $registered) {
            if (empty($registered[$app . '|' . $stage])) { return; }
            $seg = substr((string)$seg, 0, 50);
            $k = $app . '|' . $stage . '|' . $seg;
            if (!isset($acc[$k])) {
                $acc[$k] = ['app' => $app, 'stage' => $stage, 'seg' => $seg,
                            'devices' => [], 'users' => [], 'events' => 0];
            }
            if ($device_id !== null) { $acc[$k]['devices'][(int)$device_id] = 1; }
            if ($user_id   !== null) { $acc[$k]['users'][(int)$user_id]     = 1; }
            $acc[$k]['events'] += (int)$cnt;
        };

        // Anonymous / pre-register stages from MAIN device_action_log.
        $dParams = array_merge($stage_keys, [$start, $end]);
        $dSql = "SELECT source_app, action,
                        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(params_json, '\$.segment')), '') AS segment,
                        device_id, COUNT(*) AS cnt
                 FROM device_action_log
                 WHERE action IN ($keyPh) AND created >= ? AND created < ?";
        if ($test_device_ids) {
            $dSql .= " AND device_id NOT IN (" . implode(',', array_fill(0, count($test_device_ids), '?')) . ")";
            $dParams = array_merge($dParams, $test_device_ids);
        }
        $dSql .= " GROUP BY source_app, action, segment, device_id";
        foreach (db_query_prepared($dSql, $dParams)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bump($r['source_app'], $r['action'], $r['segment'], $r['device_id'], null, $r['cnt']);
        }

        // Registered stages from each SHARD's user_action_log.
        foreach ($shardConfigs as $shard_name => $cfg) {
            prime_shard($shard_name);
            $sParams = array_merge($stage_keys, [$start, $end]);
            $sSql = "SELECT source_app, action,
                            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(params_json, '\$.segment')), '') AS segment,
                            user_id, device_id, COUNT(*) AS cnt
                     FROM user_action_log
                     WHERE action IN ($keyPh) AND created >= ? AND created < ?";
            if ($test_user_ids) {
                $sSql .= " AND user_id NOT IN (" . implode(',', array_fill(0, count($test_user_ids), '?')) . ")";
                $sParams = array_merge($sParams, $test_user_ids);
            }
            $sSql .= " GROUP BY source_app, action, segment, user_id, device_id";
            foreach (db_query_shard_prepared($shard_name, $sSql, $sParams)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bump($r['source_app'], $r['action'], $r['segment'], $r['device_id'], $r['user_id'], $r['cnt']);
            }
        }

        // Idempotent upsert into the durable, kept-forever aggregate.
        foreach ($acc as $a) {
            db_query_prepared(
                "INSERT INTO acquisition_funnel_daily
                    (day, source_app, stage_key, segment, unique_devices, unique_users, event_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    unique_devices = VALUES(unique_devices),
                    unique_users   = VALUES(unique_users),
                    event_count    = VALUES(event_count)",
                [$day, $a['app'], $a['stage'], $a['seg'],
                 count($a['devices']), count($a['users']), $a['events']]);
            $funnel_rows++;
        }
    }
}

// ── 0b. Experiment funnel rollup (task #795) — ALSO before the TTL deletes ──
// For every ACTIVE experiment, split its app's funnel stages by the variant
// recorded in event_data._experiments.{slug}. Same dedup model as the
// acquisition rollup (device/user ID sets across the anonymous↔registered
// boundary). Idempotent upsert into the kept-forever experiment_funnel_daily.
$exp_funnel_rows = 0;
ensure_experiment_tables();

$active_experiments = db_query_prepared(
    "SELECT experiment_id, source_app, slug FROM experiment WHERE status = 'active'", []
)->fetchAll(PDO::FETCH_ASSOC);

if ($active_experiments) {
    // Test-account exclusion sets (recomputed here so this block is independent
    // of whether any acquisition funnel defs exist).
    $x_test_user_ids = array_map('intval', array_column(db_query_prepared(
        "SELECT user_id FROM user WHERE is_test_account = 1", []
    )->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
    $x_test_device_ids = [];
    if ($x_test_user_ids) {
        $uPh = implode(',', array_fill(0, count($x_test_user_ids), '?'));
        $x_test_device_ids = array_map('intval', array_column(db_query_prepared(
            "SELECT device_id FROM device WHERE user_id IN ($uPh)", $x_test_user_ids
        )->fetchAll(PDO::FETCH_ASSOC), 'device_id'));
    }

    foreach ($active_experiments as $exp) {
        $eid  = (int)$exp['experiment_id'];
        $app  = $exp['source_app'];
        $slug = $exp['slug'];
        // JSON path member is interpolated, not bindable — fail closed on odd slugs.
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $slug)) { continue; }
        $stages = $app_stages[$app] ?? null;
        if (!$stages) { continue; }
        $stages = array_values(array_unique($stages));
        $stagePh = implode(',', array_fill(0, count($stages), '?'));
        $jsonPath = "JSON_UNQUOTE(JSON_EXTRACT(params_json, '$._experiments.\"$slug\"'))";

        foreach ([$yesterday, date('Y-m-d')] as $day) {
            $start = $day . ' 00:00:00';
            $end   = date('Y-m-d 00:00:00', strtotime($day . ' +1 day'));

            // acc keyed by "variant|stage": device/user ID sets + event count.
            $acc = [];
            $bump = function ($variant, $stage, $device_id, $user_id, $cnt) use (&$acc) {
                if ($variant === null || $variant === '') { return; }
                $k = $variant . '|' . $stage;
                if (!isset($acc[$k])) {
                    $acc[$k] = ['variant' => $variant, 'stage' => $stage,
                                'devices' => [], 'users' => [], 'events' => 0];
                }
                if ($device_id !== null) { $acc[$k]['devices'][(int)$device_id] = 1; }
                if ($user_id   !== null) { $acc[$k]['users'][(int)$user_id]     = 1; }
                $acc[$k]['events'] += (int)$cnt;
            };

            // Anonymous stages from MAIN device_action_log.
            $dSql = "SELECT action, $jsonPath AS variant, device_id, COUNT(*) AS cnt
                     FROM device_action_log
                     WHERE source_app = ? AND action IN ($stagePh)
                       AND created >= ? AND created < ?
                       AND $jsonPath IS NOT NULL";
            $dParams = array_merge([$app], $stages, [$start, $end]);
            if ($x_test_device_ids) {
                $dSql .= " AND device_id NOT IN (" . implode(',', array_fill(0, count($x_test_device_ids), '?')) . ")";
                $dParams = array_merge($dParams, $x_test_device_ids);
            }
            $dSql .= " GROUP BY action, variant, device_id";
            foreach (db_query_prepared($dSql, $dParams)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bump($r['variant'], $r['action'], $r['device_id'], null, $r['cnt']);
            }

            // Registered stages from each SHARD's user_action_log.
            foreach ($shardConfigs as $shard_name => $cfg) {
                prime_shard($shard_name);
                $sSql = "SELECT action, $jsonPath AS variant, user_id, device_id, COUNT(*) AS cnt
                         FROM user_action_log
                         WHERE source_app = ? AND action IN ($stagePh)
                           AND created >= ? AND created < ?
                           AND $jsonPath IS NOT NULL";
                $sParams = array_merge([$app], $stages, [$start, $end]);
                if ($x_test_user_ids) {
                    $sSql .= " AND user_id NOT IN (" . implode(',', array_fill(0, count($x_test_user_ids), '?')) . ")";
                    $sParams = array_merge($sParams, $x_test_user_ids);
                }
                $sSql .= " GROUP BY action, variant, user_id, device_id";
                foreach (db_query_shard_prepared($shard_name, $sSql, $sParams)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $bump($r['variant'], $r['action'], $r['device_id'], $r['user_id'], $r['cnt']);
                }
            }

            foreach ($acc as $a) {
                db_query_prepared(
                    "INSERT INTO experiment_funnel_daily
                        (day, experiment_id, variant_key, stage_key, unique_devices, unique_users, event_count)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        unique_devices = VALUES(unique_devices),
                        unique_users   = VALUES(unique_users),
                        event_count    = VALUES(event_count)",
                    [$day, $eid, $a['variant'], $a['stage'],
                     count($a['devices']), count($a['users']), $a['events']]);
                $exp_funnel_rows++;
            }
        }
    }
}

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

echo "    cleanup_action_log: shards=$shard_count, deleted=$total_deleted, summarized=$total_summarized, metric_rows=$metric_rows, funnel_rows=$funnel_rows, exp_funnel_rows=$exp_funnel_rows\n";

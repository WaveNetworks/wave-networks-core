<?php
/**
 * dbSizeApiActions.php
 * Public API action, authenticated via service API key (Bearer token).
 * Exposes this host's database sizes so a central monitor (wave-networks-core
 * on the ops host) can poll every registered shard's admin API and aggregate.
 * Actions: apiGetDbSizes, apiGetDbTableSizes, apiGetDeviceStats (scope: system:read)
 *
 * apiGetDbTableSizes: optional schema, limit (1-100, default 15), count_table (a real COUNT(*)
 * of that table, with `schema` required so one call cannot scan every database). Table names,
 * sizes and counts only: never row contents or credentials.
 *
 * apiGetDeviceStats: why the device / device_action_log tables are growing. Counts (signed-in,
 * anonymous, past the 30-day cleanup), new rows per day for 14 days, the top user agents and
 * IPs of the last day's new devices, the action log's expiry state, and each cron job's last
 * run, so a host whose cron never fires shows up from nokemo without SSH. No cookies or keys.
 */

if (($action ?? null) == 'apiGetDbSizes') {
    if (require_api_scope('system:read')) {
        $rows = get_live_db_sizes();

        $total_mb = 0;
        $child_db_sizes = [];
        foreach ($rows as $r) {
            $total_mb += $r['size_mb'];
            $child_db_sizes[] = [
                'app_name' => $r['schema_name'],
                'size_mb'  => $r['size_mb'],
                'status'   => $r['status'],
            ];
        }

        $data['schemas']        = $rows;
        $data['child_db_sizes'] = $child_db_sizes;
        $data['total_mb']       = round($total_mb, 2);
        $data['limit_mb']       = DB_SIZE_LIMIT_MB;
        $data['warn_mb']        = DB_SIZE_WARN_MB;
        $data['critical_mb']    = DB_SIZE_CRITICAL_MB;
        // Whether this host's cron runs at all. Without it nothing is ever pruned (pwt and
        // ElevateHER grew to millions of rows with an empty cron_log). Newest row by primary key.
        $last = db_query_prepared("SELECT ran_at FROM cron_log ORDER BY log_id DESC LIMIT 1", []);
        $last = $last ? $last->fetchColumn() : false;
        $data['cron_last_ran'] = $last ?: null;
        $data['cron_minutes_ago'] = $last ? (int) floor((time() - strtotime($last . ' UTC')) / 60) : null;
        $_SESSION['success'] = 'OK';
    }
}

if (($action ?? null) == 'apiGetDbTableSizes') {
    if (require_api_scope('system:read')) {
        $schema = trim((string) ($_POST['schema'] ?? $_GET['schema'] ?? ''));
        $count  = trim((string) ($_POST['count_table'] ?? $_GET['count_table'] ?? ''));
        if ($count !== '' && $schema === '') {
            $_SESSION['error'] = 'count_table needs schema.';
        } else {
            $data['schemas'] = get_live_db_table_sizes($schema, $_POST['limit'] ?? $_GET['limit'] ?? 15, $count);
            $_SESSION['success'] = 'OK';
        }
    }
}

if (($action ?? null) == 'apiGetDeviceStats') {
    if (require_api_scope('system:read')) {
        $q = function ($sql) {
            try {
                $r = db_query_prepared($sql, []);
                return $r ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                return [['error' => $e->getMessage()]];
            }
        };
        $anon = '(user_id IS NULL OR user_id = 0)';
        // quick=1: only the newest 20,000 devices, by primary key, so a table of millions answers
        // inside a proxy timeout (pwt: 6.4M rows, the full counts took over 30s).
        if (!empty($_POST['quick'] ?? $_GET['quick'] ?? '')) {
            $tail = "device_id > (SELECT m FROM (SELECT COALESCE(MAX(device_id), 0) - 20000 AS m FROM device) x)";
            $data['tail'] = $q("SELECT COUNT(*) AS rows_, MIN(created) AS from_, MAX(created) AS to_, SUM($anon) AS anonymous,
                    MAX(device_id) AS max_id FROM device WHERE $tail")[0] ?? [];
            $data['tail_per_hour'] = $q("SELECT DATE_FORMAT(created, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS created
                FROM device WHERE $tail GROUP BY DATE_FORMAT(created, '%Y-%m-%d %H:00') ORDER BY hour DESC LIMIT 48");
            $data['tail_user_agents'] = $q("SELECT LEFT(COALESCE(user_agent, ''), 160) AS user_agent, COUNT(*) AS devices
                FROM device WHERE $tail GROUP BY LEFT(COALESCE(user_agent, ''), 160) ORDER BY devices DESC LIMIT 10");
            $data['tail_ips'] = $q("SELECT ip_address, COUNT(*) AS devices
                FROM device WHERE $tail GROUP BY ip_address ORDER BY devices DESC LIMIT 10");
            $data['tail_actions'] = $q("SELECT source_app, page, action, COUNT(*) AS n FROM device_action_log
                WHERE log_id > (SELECT m FROM (SELECT COALESCE(MAX(log_id), 0) - 20000 AS m FROM device_action_log) x)
                GROUP BY source_app, page, action ORDER BY n DESC LIMIT 10");
            $data['cron_recent'] = $q("SELECT job, ran_at, result FROM cron_log ORDER BY log_id DESC LIMIT 40");
            $_SESSION['success'] = 'OK';
            return;
        }
        $data['device'] = $q("SELECT COUNT(*) AS total, SUM($anon) AS anonymous,
                SUM($anon AND COALESCE(last_used, created) < UTC_TIMESTAMP() - INTERVAL 30 DAY) AS anonymous_past_30d,
                SUM(created >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS new_24h,
                SUM(created >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS new_7d,
                SUM(last_used > created + INTERVAL 1 MINUTE) AS came_back,
                MIN(created) AS oldest, MAX(created) AS newest
            FROM device")[0] ?? [];
        $data['device_per_day'] = $q("SELECT DATE(created) AS day, COUNT(*) AS created, SUM($anon) AS anonymous
            FROM device WHERE created >= UTC_DATE() - INTERVAL 14 DAY GROUP BY DATE(created) ORDER BY day");
        $data['top_user_agents_24h'] = $q("SELECT LEFT(COALESCE(user_agent, ''), 160) AS user_agent, COUNT(*) AS devices
            FROM device WHERE created >= UTC_TIMESTAMP() - INTERVAL 1 DAY
            GROUP BY LEFT(COALESCE(user_agent, ''), 160) ORDER BY devices DESC LIMIT 10");
        $data['top_ips_24h'] = $q("SELECT ip_address, COUNT(*) AS devices
            FROM device WHERE created >= UTC_TIMESTAMP() - INTERVAL 1 DAY
            GROUP BY ip_address ORDER BY devices DESC LIMIT 10");
        $data['device_action_log'] = $q("SELECT COUNT(*) AS total, SUM(expires_at IS NULL) AS never_expires,
                SUM(expires_at < UTC_TIMESTAMP()) AS expired_not_deleted, MIN(created) AS oldest
            FROM device_action_log")[0] ?? [];
        $data['top_actions_24h'] = $q("SELECT source_app, page, action, COUNT(*) AS n
            FROM device_action_log WHERE created >= UTC_TIMESTAMP() - INTERVAL 1 DAY
            GROUP BY source_app, page, action ORDER BY n DESC LIMIT 10");
        $data['cron_last_runs'] = $q("SELECT c.job, c.ran_at, c.result, t.runs_7d FROM cron_log c
            JOIN (SELECT job, MAX(ran_at) AS ran_at, SUM(ran_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS runs_7d
                  FROM cron_log GROUP BY job) t ON t.job = c.job AND t.ran_at = c.ran_at
            ORDER BY c.ran_at DESC LIMIT 60");
        $data['db_now'] = $q("SELECT UTC_TIMESTAMP() AS utc, NOW() AS now")[0] ?? [];
        $_SESSION['success'] = 'OK';
    }
}

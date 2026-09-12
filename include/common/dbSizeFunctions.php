<?php
/**
 * dbSizeFunctions.php
 * Shard / database size monitoring.
 *
 * On shared hosting (makershost.io) every admin DB, shard DB, and child-app DB
 * is a separate MySQL schema on the same server. A single information_schema
 * query therefore reports the size of every database this host can see. If any
 * one schema approaches the ~1GB shared-hosting ceiling the platform can break,
 * so operators need visibility + a pre-threshold alert.
 *
 * Thresholds (of the 1GB limit):
 *   < 700MB  -> OK        (green)
 *   700-900  -> WARNING   (yellow)  logged at WARNING level
 *   > 900MB  -> CRITICAL  (red)     logged FATAL to trigger the monitoring pipeline
 */

if (!defined('DB_SIZE_LIMIT_MB'))    define('DB_SIZE_LIMIT_MB', 1024);
if (!defined('DB_SIZE_WARN_MB'))     define('DB_SIZE_WARN_MB', 700);
if (!defined('DB_SIZE_CRITICAL_MB')) define('DB_SIZE_CRITICAL_MB', 900);

/**
 * Classify a size in MB into a status string.
 *
 * @param float $mb
 * @return string ok|warning|critical
 */
function db_size_status($mb) {
    if ($mb > DB_SIZE_CRITICAL_MB) return 'critical';
    if ($mb >= DB_SIZE_WARN_MB)    return 'warning';
    return 'ok';
}

/**
 * Ensure the cache table exists. Belt-and-suspenders for hosts where the
 * decimal migration runner drops DDL (MariaDB) — this runs in autocommit.
 */
function ensure_db_size_cache_table() {
    db_query("CREATE TABLE IF NOT EXISTS shard_db_size (
        schema_name  VARCHAR(128) NOT NULL,
        size_mb      DECIMAL(10,2) NOT NULL DEFAULT 0,
        table_count  INT UNSIGNED NOT NULL DEFAULT 0,
        status       VARCHAR(16) NOT NULL DEFAULT 'ok',
        last_checked DATETIME NULL,
        PRIMARY KEY (schema_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Query live sizes for every user schema visible to the admin DB connection.
 * System schemas are excluded.
 *
 * @return array list of ['schema_name'=>..,'size_mb'=>float,'table_count'=>int]
 */
function db_size_known_databases() {
    /**
     * Every database this deployment owns, with the credentials to reach it.
     *
     * A single connection cannot answer this. On shared hosting each database
     * is its own schema with its own grants, so information_schema.TABLES on
     * the admin connection returns ONLY the admin main DB — which is why this
     * page showed one row while child app shards, the databases most likely to
     * approach the 1GB ceiling, were invisible.
     */
    $out = [];

    // The connection we are already on.
    try {
        $own = db_fetch_all(db_query("SELECT DATABASE() AS n"));
        if (!empty($own[0]['n'])) { $out[$own[0]['n']] = null; }   // null = use $db
    } catch (Throwable $e) { /* non-fatal */ }

    // Shards merged into config by bootstrap (admin layer).
    foreach ((array) ($GLOBALS['shardConfigs'] ?? []) as $cfg) {
        if (!empty($cfg['name'])) {
            $out[$cfg['name']] = [
                'host' => $cfg['host'] ?: 'localhost',
                'name' => $cfg['name'],
                'user' => $cfg['user'] ?? '',
                'pass' => $cfg['pass'] ?? '',
            ];
        }
    }

    // Every registry row, ALL layers and apps — this is the part that brings in
    // child app shards. Disabled shards are skipped; pending ones are included
    // deliberately, because a shard filling up before it is activated is still
    // something you want to see.
    global $db;
    if ($db instanceof PDO && function_exists('wn_secret_decrypt')) {
        try {
            $st = $db->query(
                "SELECT layer, app_slug, shard_id, db_host, db_name, db_user, db_pass_enc
                   FROM shard_config WHERE status <> 'disabled'");
            foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                if (empty($r['db_name']) || isset($out[$r['db_name']])) { continue; }
                $pass = wn_secret_decrypt($r['db_pass_enc']);
                $out[$r['db_name']] = [
                    'host' => $r['db_host'] ?: 'localhost',
                    'name' => $r['db_name'],
                    'user' => $r['db_user'],
                    'pass' => $pass === false ? '' : $pass,
                ];
            }
        } catch (PDOException $e) { /* pre-4.8 host without shard_config */ }
    }
    return $out;
}

function db_size_measure_one($schema, $conn) {
    /**
     * Size one schema. $conn null means "the connection we are already on".
     * Opened connections are closed immediately — at 200 shards this runs
     * sequentially rather than holding them all open, which is the mistake that
     * exhausted max_connections on 2026-05-08.
     */
    $sql = "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                   COUNT(*) AS table_count
              FROM information_schema.TABLES WHERE table_schema = ?";
    try {
        if ($conn === null) {
            $rows = db_fetch_all(db_query_prepared($sql, [$schema]));
        } else {
            $pdo = new PDO(
                "mysql:host={$conn['host']};dbname={$conn['name']};charset=utf8mb4",
                $conn['user'], $conn['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $st = $pdo->prepare($sql);
            $st->execute([$schema]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $pdo  = null;
        }
    } catch (Throwable $e) {
        error_log("db_size_measure_one($schema): " . $e->getMessage());
        return null;
    }
    if (empty($rows) || $rows[0]['size_mb'] === null) { return null; }
    return [
        'size_mb'     => (float) $rows[0]['size_mb'],
        'table_count' => (int) $rows[0]['table_count'],
    ];
}

function get_live_db_sizes() {
    $out = [];

    // 1. Whatever this connection can see on its own. On a host where the user
    //    has grants across schemas this returns everything in one query, which
    //    is why it stays as the fast path.
    $sql = "SELECT table_schema AS schema_name,
                   ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                   COUNT(*) AS table_count
            FROM information_schema.TABLES
            WHERE table_schema NOT IN
                  ('information_schema','performance_schema','mysql','sys')
            GROUP BY table_schema
            ORDER BY size_mb DESC";
    foreach (db_fetch_all(db_query($sql)) as $r) {
        $out[$r['schema_name']] = [
            'schema_name' => $r['schema_name'],
            'size_mb'     => (float) $r['size_mb'],
            'table_count' => (int) $r['table_count'],
        ];
    }

    // 2. Anything the sweep could not see, measured on its own connection.
    foreach (db_size_known_databases() as $schema => $conn) {
        if (isset($out[$schema])) { continue; }
        $m = db_size_measure_one($schema, $conn);
        if ($m === null) { continue; }
        $out[$schema] = ['schema_name' => $schema] + $m;
    }

    $rows = array_values($out);
    foreach ($rows as &$r) {
        $r['status'] = db_size_status($r['size_mb']);
        $r['pct']    = min(100, round(($r['size_mb'] / DB_SIZE_LIMIT_MB) * 100, 1));
    }
    unset($r);
    usort($rows, function ($a, $b) { return $b['size_mb'] <=> $a['size_mb']; });
    return $rows;
}

/**
 * Read the cached shard sizes. If the cache is empty or older than
 * $max_age_minutes, refresh it live first.
 *
 * @param int $max_age_minutes
 * @return array
 */
function get_cached_db_sizes($max_age_minutes = 60) {
    ensure_db_size_cache_table();
    $rows = db_fetch_all(db_query(
        "SELECT * FROM shard_db_size ORDER BY size_mb DESC"
    ));

    $stale = empty($rows);
    if (!$stale) {
        foreach ($rows as $r) {
            if (empty($r['last_checked']) ||
                strtotime($r['last_checked']) < time() - ($max_age_minutes * 60)) {
                $stale = true;
                break;
            }
        }
    }

    if ($stale) {
        return refresh_db_size_cache();
    }

    foreach ($rows as &$r) {
        $r['size_mb'] = (float)$r['size_mb'];
        $r['pct']     = min(100, round(($r['size_mb'] / DB_SIZE_LIMIT_MB) * 100, 1));
    }
    return $rows;
}

/**
 * Refresh the cache from live sizes, log any newly-crossed threshold, and
 * return the fresh rows (with a 'pct' field for the UI).
 *
 * @return array
 */
function refresh_db_size_cache() {
    ensure_db_size_cache_table();

    // Previous statuses so we only alert on a transition to a worse state.
    $prev = [];
    foreach (db_fetch_all(db_query("SELECT schema_name, status FROM shard_db_size")) as $p) {
        $prev[$p['schema_name']] = $p['status'];
    }

    $live = get_live_db_sizes();
    foreach ($live as $r) {
        db_query_prepared(
            "INSERT INTO shard_db_size (schema_name, size_mb, table_count, status, last_checked)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE size_mb = VALUES(size_mb),
                                     table_count = VALUES(table_count),
                                     status = VALUES(status),
                                     last_checked = NOW()",
            [$r['schema_name'], $r['size_mb'], $r['table_count'], $r['status']]
        );
        check_db_size_alert($r, $prev[$r['schema_name']] ?? 'ok');
    }
    return $live;
}

/**
 * Severity rank for status transition comparison.
 */
function db_size_status_rank($status) {
    return ['ok' => 0, 'warning' => 1, 'critical' => 2][$status] ?? 0;
}

/**
 * Log an alert to the admin error log when a schema first crosses a threshold.
 * WARNING -> WARNING level; CRITICAL -> FATAL (feeds the error monitoring
 * pipeline). Only fires on an increase in severity to avoid hourly spam.
 *
 * @param array  $row  a row from get_live_db_sizes()
 * @param string $prevStatus  the schema's previously cached status
 */
function check_db_size_alert($row, $prevStatus) {
    if (db_size_status_rank($row['status']) <= db_size_status_rank($prevStatus)) {
        return; // no worsening -> nothing to log
    }

    $schema = $row['schema_name'];
    $mb     = $row['size_mb'];
    $pct    = round(($mb / DB_SIZE_LIMIT_MB) * 100, 1);

    if ($row['status'] === 'critical') {
        log_error_to_db(
            'FATAL',
            "Shard DB size CRITICAL: schema '$schema' is {$mb}MB ({$pct}% of "
            . DB_SIZE_LIMIT_MB . "MB limit). Provision a new shard.",
            __FILE__, __LINE__
        );
    } elseif ($row['status'] === 'warning') {
        log_error_to_db(
            'WARNING',
            "Shard DB size WARNING: schema '$schema' is {$mb}MB ({$pct}% of "
            . DB_SIZE_LIMIT_MB . "MB limit). Approaching the shard ceiling.",
            __FILE__, __LINE__
        );
    }
}

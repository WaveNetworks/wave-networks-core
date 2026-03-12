<?php
/**
 * cronLogFunctions.php
 * Cron job logging.
 */

/**
 * Log a cron job execution.
 *
 * @param string $job    Job name/path
 * @param string $result Result message
 */
function log_cron($job, $result = 'OK') {
    $job    = sanitize($job, SQL);
    $result = sanitize($result, SQL);
    db_query("INSERT INTO cron_log (job, ran_at, result) VALUES ('$job', NOW(), '$result')");
}

/**
 * Get recent cron log entries.
 *
 * @param int $limit
 * @return array
 */
function get_cron_logs($limit = 50) {
    $limit = (int)$limit;
    $r = db_query("SELECT * FROM cron_log ORDER BY ran_at DESC LIMIT $limit");
    return db_fetch_all($r);
}

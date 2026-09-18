<?php
/**
 * clockFunctions.php — ONE clock for the whole stack: UTC.
 *
 * The convention, and it is not negotiable per app or per table:
 *   • PHP runs in UTC (date_default_timezone_set('UTC')), so date('Y-m-d H:i:s') is UTC.
 *   • Every database connection runs in UTC (SET time_zone = '+00:00'), so NOW(),
 *     CURRENT_TIMESTAMP and every DEFAULT CURRENT_TIMESTAMP column are UTC.
 *   • A stored datetime is therefore always UTC, whoever wrote it, and a comparison
 *     between a value PHP computed and NOW() is meaningful.
 *   • Local time exists only at the edges: what a person is shown, and what a person
 *     typed (contactswipe's csTimezone.php converts those per user).
 *
 * Why (2026-09-18): PHP defaulted to UTC while MySQL used the server's local zone
 * (Europe/London, so UTC+1 in summer). Every column written by SQL was an hour ahead of
 * every value PHP wrote, in the same table — an error's resolved_at (NOW()) read an hour
 * after the app's own clock, queued email compared a PHP scheduled_at against NOW(), and
 * ContactSwipe's reminders, which are deliberately stored in UTC, were swept by
 * "due_at <= NOW()" and so fired up to an hour early all summer. Local time also jumps
 * twice a year: an hour repeats in October and an hour does not exist in March, which no
 * amount of care in application code can make safe to order or compare by.
 *
 * Rows written before this landed are in the old local zone; they are left alone (history
 * is not rewritten). Timestamps from the changeover until the next DST end read an hour
 * earlier than the rows above them.
 */

if (!defined('WN_CLOCK_TZ')) define('WN_CLOCK_TZ', 'UTC');
if (!defined('WN_CLOCK_SQL_OFFSET')) define('WN_CLOCK_SQL_OFFSET', '+00:00');

if (!function_exists('wn_clock_init_php')) {
    /** Put PHP on the one clock. Safe to call repeatedly; call it before anything logs a time. */
    function wn_clock_init_php() {
        if (date_default_timezone_get() !== WN_CLOCK_TZ) { date_default_timezone_set(WN_CLOCK_TZ); }
        return WN_CLOCK_TZ;
    }
}

if (!function_exists('wn_clock_init_pdo')) {
    /**
     * Put one connection on the same clock, so NOW() and CURRENT_TIMESTAMP agree with PHP.
     * Called right after every connection is opened — core's, each shard's, and each child
     * app's. Never fatal: a connection that refuses the statement keeps working, it just
     * keeps the server's zone, and wn_clock_pdo_is_utc() reports it.
     */
    function wn_clock_init_pdo($pdo) {
        if (!($pdo instanceof PDO)) { return false; }
        try {
            $pdo->exec("SET time_zone = '" . WN_CLOCK_SQL_OFFSET . "'");
            return true;
        } catch (Throwable $e) {
            error_log('wn_clock_init_pdo: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wn_clock_pdo_options')) {
    /** PDO options that set the zone as part of connecting (for connections opened elsewhere). */
    function wn_clock_pdo_options(array $options = []) {
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET time_zone = '" . WN_CLOCK_SQL_OFFSET . "'";
        }
        return $options;
    }
}

if (!function_exists('wn_clock_check')) {
    /**
     * What each side thinks the time is: ['php' => …, 'db' => …, 'skew_seconds' => n, 'ok' => bool].
     * A non-zero skew means a connection was opened without wn_clock_init_pdo(), or the host
     * clock itself drifted. Read-only; used by the clock probe and worth a health page.
     */
    function wn_clock_check($pdo = null) {
        global $db;
        $pdo = $pdo ?: $db;
        $out = ['php' => date('Y-m-d H:i:s'), 'php_tz' => date_default_timezone_get(),
                'db' => null, 'db_tz' => null, 'skew_seconds' => null, 'ok' => false];
        try {
            $r = $pdo->query("SELECT NOW() AS now_db, @@session.time_zone AS tz");
            $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
            if ($row) {
                $out['db'] = $row['now_db'];
                $out['db_tz'] = $row['tz'];
                $out['skew_seconds'] = strtotime($row['now_db']) - strtotime($out['php']);
                $out['ok'] = abs((int) $out['skew_seconds']) <= 5 && $out['php_tz'] === WN_CLOCK_TZ;
            }
        } catch (Throwable $e) {
            $out['error'] = 'could not read the database clock';
        }
        return $out;
    }
}

<?php
/**
 * cron/cron.php
 * Main cron entry point. Run via server crontab every minute:
 *   * * * * * php /path/to/admin/cron/cron.php
 *
 * Scans folder-based schedule directories and runs matching jobs:
 *   minutes/{N}/*.php  — every N minutes (1, 5, 10, 15, 30, etc.)
 *   days/{N}/*.php     — ONCE every N days (days/1 = once a day, the first run after 00:00 UTC)
 *   weeks/{dow}/*.php  — ONCE on that ISO day of week (1=Mon..7=Sun)
 *   months/{month}/*.php — ONCE on the 1st of that month (1-12)
 *
 * Until 2026-10-20 days/{N} meant "day N of the month" and ran on EVERY minute's run that
 * day: the "daily" cleanups in days/1 ran ~1440 times on the 1st and never otherwise, so
 * logs were trimmed once a month, and the action-log roll-up inserted the same day 1440
 * times (ContactSwipe: 8.6M summary rows holding 321 facts). Every job in days/1 was
 * written as daily, so days/{N} now means "every N days", like minutes/{N}, and each
 * period runs once (cron_once, a marker per schedule under $files_location/cron_state/).
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only.';
    exit;
}

// Bootstrap (readonly — no actions needed)
include(__DIR__ . '/../include/common_readonly.php');

$minute   = (int)date('i');   // Minute (0-59)
$today    = (int)date('j');   // Day of month (1-31)
$dow      = (int)date('N');   // ISO day of week (1=Mon..7=Sun)
$month    = (int)date('n');   // Month (1-12)
$cronDir  = __DIR__;

/**
 * True the first time it is asked for $key in $period (e.g. 'days-1', '2026-10-20'), false
 * after. Holds a lock while deciding, so two overlapping minute runs never both start a job.
 * No writable state dir = false (skip) rather than a run every minute.
 */
function cron_once($key, $period) {
    global $files_location;
    $dir = rtrim((string) ($files_location ?: sys_get_temp_dir()), '/') . '/cron_state/';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $fh = @fopen($dir . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key), 'c+');
    if (!$fh) { return false; }
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
    $last = trim((string) stream_get_contents($fh));
    $due = $last !== (string) $period;
    if ($due) { ftruncate($fh, 0); rewind($fh); fwrite($fh, (string) $period); fflush($fh); }
    flock($fh, LOCK_UN); fclose($fh);
    return $due;
}

echo "Cron started: " . date('Y-m-d H:i:s') . "\n";

/**
 * Run all job files in a directory, logging each result.
 *
 * @param string $dir     Full path to the directory containing job .php files
 * @param string $label   Label for log output (e.g. "minute/1", "day/15")
 */
function run_jobs_in_dir($dir, $label) {
    if (!is_dir($dir)) return;

    foreach (glob($dir . '*.php') as $job) {
        $name = $label . '/' . basename($job);
        echo "  Running $name\n";
        try {
            include($job);
            log_cron($name, 'OK');
        } catch (Exception $e) {
            log_cron($name, 'ERROR: ' . $e->getMessage());
            echo "    ERROR: " . $e->getMessage() . "\n";
        }
    }
}

// ── Minute jobs: cron/minutes/{N}/*.php ──
// Run if the current minute is evenly divisible by N.
// e.g. minutes/1/ = every minute, minutes/5/ = every 5 min, minutes/15/ = every 15 min
$minuteDirs = glob($cronDir . '/minutes/*', GLOB_ONLYDIR);
if ($minuteDirs) {
    foreach ($minuteDirs as $mDir) {
        $interval = (int)basename($mDir);
        if ($interval > 0 && $minute % $interval === 0) {
            run_jobs_in_dir($mDir . '/', 'minute/' . $interval);
        }
    }
}

// ── Day jobs: cron/days/{N}/*.php — once every N days ──
$epochDay = intdiv(time(), 86400);
foreach (glob($cronDir . '/days/*', GLOB_ONLYDIR) ?: [] as $dDir) {
    $every = (int) basename($dDir);
    if ($every > 0 && $epochDay % $every === 0 && cron_once('days-' . $every, date('Y-m-d'))) {
        run_jobs_in_dir($dDir . '/', 'day/' . $every);
    }
}

// ── Weekly jobs: cron/weeks/{day_of_week}/*.php — once on that weekday ──
// ISO day of week: 1=Monday, 7=Sunday
if (is_dir($cronDir . '/weeks/' . $dow) && cron_once('weeks-' . $dow, date('o-\WW'))) {
    run_jobs_in_dir($cronDir . '/weeks/' . $dow . '/', 'week/' . $dow);
}

// ── Monthly jobs: cron/months/{month}/*.php — once on the 1st of that month ──
if ($today === 1 && is_dir($cronDir . '/months/' . $month) && cron_once('months-' . $month, date('Y-m'))) {
    run_jobs_in_dir($cronDir . '/months/' . $month . '/', 'month/' . $month);
}

// ── Child app crons ──────────────────────────────────────────────────────
// Each child app can have its own cron/cron.php with the same folder structure.
// Scan for sibling child app cron runners and include them.
$childCrons = glob(__DIR__ . '/../../*/cron/cron.php');
foreach ($childCrons as $childCron) {
    $appName = basename(dirname(dirname($childCron)));
    if ($appName === 'admin') continue; // skip self
    echo "\nRunning child app cron: $appName\n";
    try {
        include($childCron);
    } catch (Exception $e) {
        echo "  ERROR in $appName cron: " . $e->getMessage() . "\n";
    }
}

// ── Sub-admin crons (docs/SubAdmins.md) ─────────────────────────────────
// Each mounted sub-admin (help-desk, ...) declares its jobs in subadmin.json "cron";
// they run here so no sub-admin needs a crontab line. Global scope, like child crons,
// because a job's bootstrap sets globals. A per-job lock skips a run while the last
// one is still going (an IMAP poll can outlast a minute).
// Their manifest "notification_categories" are registered first (idempotent upsert), so
// e.g. help-desk's 'support' shows in users' notification prefs once it is mounted.
if (function_exists('subadmin_register_notification_categories')) {
    try { subadmin_register_notification_categories(); }
    catch (Throwable $e) { echo "  ERROR registering sub-admin notification categories: " . $e->getMessage() . "\n"; }
}
if (function_exists('subadmin_cron_due')) {
    foreach (subadmin_cron_due((int)date('G') * 60 + $minute) as $subJob) {
        $subLock = @fopen(sys_get_temp_dir() . '/wn_subadmin_' . md5($subJob['file']) . '.lock', 'c');
        if ($subLock && !flock($subLock, LOCK_EX | LOCK_NB)) { echo "  Skipping sub/{$subJob['label']} (still running)\n"; fclose($subLock); continue; }
        echo "  Running sub/{$subJob['label']}\n";
        try {
            include($subJob['file']);
            log_cron('sub/' . $subJob['label'], 'OK');
        } catch (Throwable $e) {
            log_cron('sub/' . $subJob['label'], 'ERROR: ' . $e->getMessage());
            echo "    ERROR: " . $e->getMessage() . "\n";
        }
        if ($subLock) { flock($subLock, LOCK_UN); fclose($subLock); }
    }
}

echo "Cron finished: " . date('Y-m-d H:i:s') . "\n";

<?php
/**
 * cron/cron.php
 * Main cron entry point. Run via server crontab every minute:
 *   * * * * * php /path/to/admin/cron/cron.php
 *
 * Scans folder-based schedule directories and runs matching jobs:
 *   minutes/{N}/*.php  — every N minutes (1, 5, 10, 15, 30, etc.)
 *   days/{day}/*.php   — on that day of the month (1-31)
 *   weeks/{dow}/*.php  — on that ISO day of week (1=Mon..7=Sun)
 *   months/{month}/*.php — on the 1st of that month (1-12)
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

// ── Daily jobs: cron/days/{day_of_month}/*.php ──
run_jobs_in_dir($cronDir . '/days/' . $today . '/', 'day/' . $today);

// ── Weekly jobs: cron/weeks/{day_of_week}/*.php ──
// ISO day of week: 1=Monday, 7=Sunday
run_jobs_in_dir($cronDir . '/weeks/' . $dow . '/', 'week/' . $dow);

// ── Monthly jobs: cron/months/{month}/*.php ──
// Only run on the 1st of the month
if ($today === 1) {
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

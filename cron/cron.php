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

echo "Cron finished: " . date('Y-m-d H:i:s') . "\n";

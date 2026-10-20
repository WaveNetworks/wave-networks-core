<?php
/**
 * cron/minutes/60/backup.php — the nightly encrypted backup (include/common/backupFunctions.php).
 *
 * Off until a backup public key is configured. Once a day at $backup_hour_utc (default 10,
 * about 2-3am US Pacific), or at any hour when the newest backup is over 36h old (a missed
 * night catches up). The dump can take minutes, so it runs as its own process
 * (scripts/wn-backup.php run) and never holds up the jobs after it; the script's lock stops
 * two runs overlapping. If this PHP cannot start a process, it runs inline instead.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
if (!isset($db)) { include(__DIR__ . '/../../../include/common_readonly.php'); }

if (!function_exists('wn_backup_public_key') || !wn_backup_public_key()) { return; }

$latest = wn_backup_latest();
$age = $latest ? time() - (int) strtotime((string) ($latest['created'] ?? '')) : PHP_INT_MAX;
$hour = (int) ($backup_hour_utc ?? 10);
$due = ($age > 20 * 3600 && (int) gmdate('G') === $hour) || $age > 36 * 3600;
if (!$due) { return; }

$script = realpath(__DIR__ . '/../../../scripts/wn-backup.php');
$php = PHP_BINARY ?: 'php';
if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
    exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' run > /dev/null 2>&1 &');
    echo "    backup: started in the background\n";
} else {
    try {
        $m = wn_backup_run((int) ($backup_keep_local ?? 3));
        if (function_exists('log_cron')) { log_cron('backup', wn_backup_summary($m)); }
        echo "    backup: " . wn_backup_summary($m) . "\n";
    } catch (Throwable $e) {
        if (function_exists('log_error_to_db')) { log_error_to_db('ERROR', 'Backup failed: ' . $e->getMessage(), __FILE__, __LINE__, null); }
        if (function_exists('log_cron')) { log_cron('backup', 'FAILED: ' . $e->getMessage()); }
        echo "    backup: FAILED " . $e->getMessage() . "\n";
    }
}

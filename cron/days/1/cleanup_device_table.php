<?php
/**
 * cron/days/1/cleanup_device_table.php
 * Prune orphan anonymous device rows from the main DB `device` table.
 *
 * Structural gap fix (2026-05-31): admin core had NO retention for `device`.
 * Anonymous bot/crawler traffic accumulates 100K+ rows/month per deployment
 * with no bound (pwt hit 707MB/1024MB cap on 2.25M device rows).
 *
 * Conservative policy:
 *   - NEVER delete a row linked to a user_id (real returning sessions; needed
 *     by the device-revocation flow and login-history joins).
 *   - Only prune anonymous devices (user_id NULL or 0) whose last_used (falling
 *     back to created) is older than DEVICE_CLEANUP_DAYS (default 30).
 *
 * Also prunes login_history beyond LOGIN_HISTORY_CLEANUP_DAYS (default 365) —
 * the audit found it was the other unbounded main-DB table with no retention.
 * Kept at >=30d minimum for security auditing.
 *
 * Idempotent — safe to run multiple times for the same day.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!isset($db)) {
    include(__DIR__ . '/../../../include/common_readonly.php');
}

global $db;

// ── 1. Prune orphan anonymous device rows ──
$cutoff_days = defined('DEVICE_CLEANUP_DAYS') ? (int)DEVICE_CLEANUP_DAYS : 30;
if ($cutoff_days < 1) { $cutoff_days = 30; }

$stmt = db_query_prepared(
    "DELETE FROM device
       WHERE (user_id IS NULL OR user_id = 0)
         AND COALESCE(last_used, created) < DATE_SUB(NOW(), INTERVAL ? DAY)",
    [$cutoff_days]);
$device_deleted = $stmt->rowCount();

// Reclaim disk space — anonymous devices are the dominant bloat path. Only
// OPTIMIZE when the delete was significant (it briefly locks the table).
if ($device_deleted > 1000) {
    db_query("OPTIMIZE TABLE device");
}

// ── 2. Prune old login_history (audit fix — was unbounded) ──
$login_days = defined('LOGIN_HISTORY_CLEANUP_DAYS') ? (int)LOGIN_HISTORY_CLEANUP_DAYS : 365;
if ($login_days < 30) { $login_days = 30; }

$login_deleted = 0;
try {
    $stmt = db_query_prepared(
        "DELETE FROM login_history WHERE created < DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$login_days]);
    $login_deleted = $stmt->rowCount();
} catch (Exception $e) {
    // login_history is added by migration 2.6 — tolerate older schemas.
}

// ── 3. Surface rowcounts on cron stdout so ops can monitor whether cleanup
//        is keeping up with new growth. Deliberately NOT logged to error_log:
//        routine INFO rows get scraped by the monitoring poller and re-spawn
//        a Fix: task every day, drowning real errors in noise. ──
echo "    cleanup_device_table: device_deleted=$device_deleted (>$cutoff_days d), "
   . "login_history_deleted=$login_deleted (>$login_days d)\n";

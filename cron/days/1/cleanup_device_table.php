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
 *   - Anonymous devices (user_id NULL or 0): prune when last_used (falling
 *     back to created) is older than DEVICE_CLEANUP_DAYS (default 30).
 *   - Signed-in devices: prune when idle > SIGNED_IN_DEVICE_CLEANUP_DAYS
 *     (default 180) or beyond the newest DEVICE_MAX_PER_USER (default 100)
 *     for that user, together with their api_key rows (task #2422).
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

// ── 1b. Prune signed-in devices (task #2422) ──
// Step 1 never touches a user's devices, so a client that logs in fresh every
// time (authed-probe.py, a script, a browser that drops cookies) adds a row per
// login forever: the vivajee test user reached 25,115 rows and ?page=profile
// rendered all of them (28 MB). A device row is only a session if it is used,
// so: (a) drop signed-in devices idle longer than SIGNED_IN_DEVICE_CLEANUP_DAYS
// (default 180, never under 30 — remember-me cookies live 30 days), and
// (b) keep at most DEVICE_MAX_PER_USER (default 100) per user, newest first.
// Their api_key rows (remember-me / bearer tokens) go with them, so a pruned
// device is signed out rather than left with a key pointing at nothing.
$signed_days = defined('SIGNED_IN_DEVICE_CLEANUP_DAYS') ? (int)SIGNED_IN_DEVICE_CLEANUP_DAYS : 180;
if ($signed_days < 30) { $signed_days = 30; }
$max_per_user = defined('DEVICE_MAX_PER_USER') ? (int)DEVICE_MAX_PER_USER : 100;
if ($max_per_user < 10) { $max_per_user = 10; }

$signed_deleted = 0;
$prune_ids = function (array $ids) use (&$signed_deleted) {
    foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
        $in = implode(',', $chunk);
        db_query("DELETE FROM api_key WHERE device_id IN ($in)");
        $r = db_query_prepared("DELETE FROM device WHERE device_id IN ($in)", []);
        $signed_deleted += $r ? $r->rowCount() : 0;
    }
};

$ids = [];
$r = db_query_prepared(
    "SELECT device_id FROM device
      WHERE user_id > 0
        AND COALESCE(last_used, created) < DATE_SUB(NOW(), INTERVAL ? DAY)",
    [$signed_days]);
while ($row = db_fetch($r)) { $ids[] = $row['device_id']; }
$prune_ids($ids);

$over = db_query_prepared(
    "SELECT user_id FROM device WHERE user_id > 0
      GROUP BY user_id HAVING COUNT(*) > ?", [$max_per_user]);
$over_users = [];
while ($row = db_fetch($over)) { $over_users[] = (int)$row['user_id']; }
foreach ($over_users as $uid) {
    $ids = [];
    $r = db_query("SELECT device_id FROM device WHERE user_id = '$uid'
                    ORDER BY COALESCE(last_used, created) DESC, device_id DESC
                    LIMIT 18446744073709551615 OFFSET $max_per_user");
    while ($row = db_fetch($r)) { $ids[] = $row['device_id']; }
    $prune_ids($ids);
}

if ($signed_deleted > 1000 && $device_deleted <= 1000) {
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
   . "signed_in_deleted=$signed_deleted (>$signed_days d or >$max_per_user/user), "
   . "login_history_deleted=$login_deleted (>$login_days d)\n";

<?php
/**
 * auto_resolve_noise.php — Auto-resolve known noise errors every 5 minutes.
 *
 * Two passes:
 *   (1) URL-pattern noise: bot scanners, misrouted admin paths, favicon 404s.
 *       These are drive-by traffic, never a real bug. Tagged resolution_reason=noise.
 *   (2) Stale unresolved errors: last_seen_at is >14 days ago and occurrence_count
 *       hasn't advanced. The error stopped happening — probably a fix shipped
 *       upstream or the offending caller went away. Tagged resolution_reason=already_fixed.
 *       This is what catches cases like Error ID 37 (the apiPost ReferenceError
 *       that looped the builder for 8h on 2026-04-23 because nothing closed
 *       the source row after the fix deployed).
 *
 * Runs via admin cron on all servers — no child app bootstrap needed.
 * Requires wave-networks-core@3.5+ (resolution_reason column on error_log).
 */

if (!function_exists('db_query_prepared')) {
    echo "    Skipped — admin DB functions not available.\n";
    return;
}

global $db;
if (!$db) {
    echo "    Skipped — admin \$db not available.\n";
    return;
}

$noisePatterns = [
    '%/wp-admin%',
    '%/wp-login%',
    '%/xmlrpc%',
    '%/.env%',
    '%/admin/upload%',
    '%/admin/function%',
    '%/admin/uploads%',
    '%/admin/config%',
    '%favicon.ico%',
    '%favicon.svg%',
    '%/admin/api/index.php%',
    '%/nokemo/api/index.php%',
];

$noise_resolved = 0;
$stale_resolved = 0;

// ── Pass 1: URL-pattern noise ──
try {
    $conditions = [];
    $params = [];
    foreach ($noisePatterns as $pattern) {
        $conditions[] = "message LIKE ?";
        $params[] = $pattern;
    }

    if (!empty($conditions)) {
        $where = implode(' OR ', $conditions);
        $r = db_query_prepared(
            "SELECT error_id FROM error_log WHERE resolved_at IS NULL AND ($where) LIMIT 200",
            $params
        );
        $errors = $r ? db_fetch_all($r) : [];
        foreach ($errors as $error) {
            db_query_prepared(
                "UPDATE error_log
                 SET resolved_at = NOW(),
                     resolved_by = 'auto-noise-filter',
                     resolution_reason = 'noise',
                     resolution_notes = 'URL-pattern match: scanner/bot/drive-by traffic'
                 WHERE error_id = ? AND resolved_at IS NULL",
                [intval($error['error_id'])]
            );
            $noise_resolved++;
        }
    }
} catch (Exception $e) {
    echo "    Pass 1 error: " . $e->getMessage() . "\n";
}

// ── Pass 2: stale errors (not seen in >14 days) ──
// Only close if last_seen_at is populated and genuinely stale. Skips rows
// where last_seen_at is NULL (legacy pre-2.4 rows) so we don't false-close
// freshly-recorded errors whose first log hasn't updated that column yet.
try {
    $r = db_query_prepared(
        "SELECT error_id, source_app, occurrence_count, last_seen_at
         FROM error_log
         WHERE resolved_at IS NULL
           AND last_seen_at IS NOT NULL
           AND last_seen_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
         LIMIT 200",
        []
    );
    $stale = $r ? db_fetch_all($r) : [];
    foreach ($stale as $error) {
        $note = sprintf(
            'auto-closed stale: last_seen=%s, occurrences=%d, no activity for 14+ days',
            $error['last_seen_at'] ?? 'n/a',
            (int) ($error['occurrence_count'] ?? 1)
        );
        db_query_prepared(
            "UPDATE error_log
             SET resolved_at = NOW(),
                 resolved_by = 'auto-stale-filter',
                 resolution_reason = 'already_fixed',
                 resolution_notes = ?
             WHERE error_id = ? AND resolved_at IS NULL",
            [$note, intval($error['error_id'])]
        );
        $stale_resolved++;
    }
} catch (Exception $e) {
    echo "    Pass 2 error: " . $e->getMessage() . "\n";
}

if ($noise_resolved + $stale_resolved > 0) {
    echo "    Auto-resolved: $noise_resolved noise, $stale_resolved stale.\n";
} else {
    echo "    No errors to auto-resolve.\n";
}

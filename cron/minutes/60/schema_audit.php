<?php
/**
 * cron/minutes/60/schema_audit.php — does the live schema still match the migrations?
 *
 * Schema drift (a CREATE or ALTER that never landed while the ledger moved on — the
 * pre-5.0 runner did that silently) used to surface as a "table doesn't exist" line in a
 * log someone happened to read. This runs apiSchemaAudit's own engine on a schedule for
 * core main + shards and every child app on this deployment, and reports anything wrong
 * to the error log, where the admin Error Log and the monitoring pipeline already look.
 *
 * Once a day (the hourly slot with a day guard through cron_log), read-only, local
 * databases only — no network, nothing metered.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
if (!isset($db)) { include(__DIR__ . '/../../../include/common_readonly.php'); }

if (!function_exists('schema_audit_run')) {
    echo "    schema audit: unavailable, skipping.\n";
    return;
}

// Once a day, whichever hourly run gets there first. Measured as "a run in the last 20 hours",
// not "a row dated today": a calendar comparison breaks at a clock change (the UTC move on
// 2026-09-18 left a row stamped an hour in the future, which would have blocked a whole day)
// and at every midnight edge. A row stamped in the future never blocks.
$last = 0;
if (function_exists('get_cron_logs')) {
    foreach (get_cron_logs(300) as $row) {
        if (($row['job'] ?? '') !== 'schema_audit') continue;
        $t = strtotime((string) ($row['ran_at'] ?? ''));
        if ($t && $t <= time() && $t > $last) $last = $t;
    }
}
if ($last && (time() - $last) < 20 * 3600 && empty($GLOBALS['schema_audit_force'])) {
    echo "    schema audit: ran " . round((time() - $last) / 3600, 1) . "h ago, skipping.\n";
    return;
}

// The read-only bootstrap does not set the core version globals (it runs no migrations), and
// the audit reads them to know what each core database should be at. Take them from the one
// place that declares them rather than repeating the numbers here.
if (!isset($db_version) || !isset($shard_version)) {
    $decl = (string) @file_get_contents(__DIR__ . '/../../../include/bootstrap.php');
    if (preg_match('~\$db_version\s*=\s*\$db_version\s*\?\?\s*([0-9.]+)~', $decl, $m))       $db_version    = (float) $m[1];
    if (preg_match('~\$shard_version\s*=\s*\$shard_version\s*\?\?\s*([0-9.]+)~', $decl, $m)) $shard_version = (float) $m[1];
}

$audit = schema_audit_run('', false);
$s = $audit['summary'];
$line = sprintf('databases=%d ok=%d drift=%d uncertain=%d unreadable=%d migration_failures=%d',
    $s['databases'], $s['ok'], $s['drift'], $s['uncertain_only'], $s['errors'], $s['migration_failures'] ?? 0);

// Name the databases that are not ok, with the first few differences, so the error log
// entry is actionable without re-running anything.
$bad = [];
foreach ($audit['databases'] as $d) {
    if (($d['status'] ?? 'ok') === 'ok') continue;
    $items = [];
    foreach ((array) ($d['drift'] ?? []) as $kind => $rows) {
        foreach ((array) $rows as $r) {
            $items[] = $kind . ':' . (is_array($r) ? implode('.', array_filter([$r['table'] ?? null, $r['column'] ?? $r['index'] ?? null])) : (string) $r);
            if (count($items) >= 8) break 2;
        }
    }
    $bad[] = $d['label'] . ' [' . ($d['status'] ?? '?') . ' ledger ' . ($d['ledger_version'] ?? '?')
        . '/' . ($d['target_version'] ?? '?') . ($items ? ': ' . implode(', ', $items) : '')
        . (!empty($d['error']) ? ': ' . $d['error'] : '') . ']';
}

$failed = ($s['drift'] ?? 0) + ($s['errors'] ?? 0) + ($s['migration_failures'] ?? 0);
if ($failed > 0 && function_exists('log_error_to_db')) {
    log_error_to_db($s['drift'] > 0 ? 'ERROR' : 'WARNING',
        'Schema audit: ' . $line . ' — ' . implode('; ', $bad), __FILE__, __LINE__, null);
} elseif (($s['uncertain_only'] ?? 0) > 0 && function_exists('log_error_to_db')) {
    log_error_to_db('INFO', 'Schema audit: ' . $line . ' — ' . implode('; ', $bad), __FILE__, __LINE__, null);
}
if (function_exists('log_cron')) { log_cron('schema_audit', $line . ($bad ? ' | ' . implode('; ', $bad) : '')); }
echo "    schema audit: $line\n";

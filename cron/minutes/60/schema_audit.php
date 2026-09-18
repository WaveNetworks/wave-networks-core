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

// Day guard: one audit per calendar day, whichever hourly run gets there first.
$ran_today = false;
if (function_exists('get_cron_logs')) {
    foreach (get_cron_logs(200) as $row) {
        if (($row['job'] ?? '') === 'schema_audit' && substr((string) ($row['ran_at'] ?? ''), 0, 10) === date('Y-m-d')) {
            $ran_today = true;
            break;
        }
    }
}
if ($ran_today && empty($GLOBALS['schema_audit_force'])) {
    echo "    schema audit: already run today.\n";
    return;
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

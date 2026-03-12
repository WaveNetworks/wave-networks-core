<?php
/**
 * cron/sync_users.php
 * Recurring user migration sync. Run via cron:
 *   php /path/to/admin/cron/sync_users.php
 *
 * Can also be included by cron.php as a daily job.
 * Only runs if a migration source is configured and sync_enabled = 1.
 */

// CLI only (when run directly)
if (php_sapi_name() === 'cli' && !isset($db)) {
    include(__DIR__ . '/../include/common_readonly.php');
}

// Check if migration source exists and sync is enabled
$source = get_migration_source();
if (!$source) {
    echo "Migration sync: no source configured. Skipping.\n";
    return;
}
if (!$source['sync_enabled']) {
    echo "Migration sync: sync disabled. Skipping.\n";
    return;
}

echo "Migration sync started: " . date('Y-m-d H:i:s') . "\n";
echo "Source: {$source['source_name']} ({$source['db_host']}:{$source['db_port']}/{$source['db_name']})\n";

// Incremental: only sync users created/modified since last sync
$since = $source['last_sync_at'];
if ($since) {
    echo "Incremental sync since: $since\n";
} else {
    echo "Full sync (no previous sync recorded)\n";
}

// Run sync in batches
$batch_size  = 500;
$offset      = 0;
$max_batches = 200;
$total_stats = ['total' => 0, 'synced' => 0, 'conflicts' => 0, 'skipped' => 0, 'already' => 0];

for ($i = 0; $i < $max_batches; $i++) {
    $batch = sync_batch($source, $batch_size, $offset, $since);

    if (isset($batch['error'])) {
        echo "ERROR: {$batch['error']}\n";
        if (function_exists('log_cron')) {
            log_cron('sync_users.php', 'ERROR: ' . $batch['error']);
        }
        break;
    }

    $total_stats['total']     += $batch['total'];
    $total_stats['synced']    += $batch['synced'];
    $total_stats['conflicts'] += $batch['conflicts'];
    $total_stats['skipped']   += $batch['skipped'];
    $total_stats['already']   += $batch['already'];

    echo "  Batch " . ($i + 1) . ": {$batch['total']} processed, {$batch['synced']} synced, {$batch['conflicts']} conflicts\n";

    if ($batch['total'] < $batch_size) break; // Last batch
    $offset += $batch_size;
}

// Update last_sync_at
$sid = (int)$source['source_id'];
db_query("UPDATE migration_source SET last_sync_at = NOW() WHERE source_id = '$sid'");

$summary = "Sync complete: {$total_stats['synced']} synced, {$total_stats['conflicts']} conflicts, {$total_stats['skipped']} skipped, {$total_stats['already']} already mapped ({$total_stats['total']} total)";
echo "$summary\n";
echo "Migration sync finished: " . date('Y-m-d H:i:s') . "\n";

// Log result
if (function_exists('log_cron')) {
    log_cron('sync_users.php', 'OK: ' . $summary);
}

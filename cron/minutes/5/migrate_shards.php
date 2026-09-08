<?php
/**
 * cron/minutes/5/migrate_shards.php
 * Drain the shard migration backlog. Runs via cron.php (minute % 5 == 0).
 *
 * check_and_migrate_all_shards() is bounded per request (WN_SHARD_MIGRATE_BUDGET)
 * so a $shard_version bump can never make one unlucky page load open a
 * connection to every shard. That bound means user traffic alone would drain a
 * large backlog slowly, and a NEWLY provisioned shard would sit unmigrated —
 * and therefore unassignable — until enough requests happened to reach it.
 *
 * This job removes that wait: it runs the same routine with an unlimited budget,
 * off the request path, every five minutes.
 */

if (!function_exists('check_and_migrate_all_shards')) {
    echo "    Skipped — admin DB functions not available.\n";
    return;
}

global $shardConfigs, $shard_version;

if (empty($shardConfigs)) {
    echo "    No shards configured.\n";
    return;
}

check_and_migrate_all_shards(0);

$behind = 0;
foreach (array_keys($shardConfigs) as $sid) {
    if (!_wn_migration_flag_exists(_wn_shard_flag_scope($sid), $shard_version)) $behind++;
}

echo "    " . count($shardConfigs) . " shards at v$shard_version"
   . ($behind ? " — $behind still behind.\n" : " — all current.\n");

<?php
/**
 * cron/minutes/60/shard_capacity.php
 * Hourly: refresh the shard registry's cached size + user count, drain any shard
 * that has crossed the high-water mark, and alarm if there is nowhere left to
 * put a new user. Runs via cron.php (minute % 60 == 0), after check_db_sizes.php
 * has refreshed the schema size cache this reads.
 *
 * WHY THE DRAIN MATTERS: 20i caps every database at 1GB and a user's shard_id
 * never changes after registration. A user pinned to a shard that reaches the
 * ceiling has failing writes and CANNOT be rescued by provisioning more shards
 * afterwards. The only moment that helps is before the shard fills — so a shard
 * that crosses the high-water mark stops taking new users here, automatically,
 * while continuing to serve the users it already has.
 */

if (!function_exists('refresh_shard_config_sizes')) {
    echo "    Skipped — shard registry not available.\n";
    return;
}

if (!shard_config_rows('admin', '')) {
    echo "    No registry shards (config.php shards only) — nothing to track.\n";
    return;
}

refresh_shard_config_user_counts();
$res = refresh_shard_config_sizes('admin', '');

$assignable = shard_assignable_rows('admin', '');
$headroom   = 0.0;
foreach ($assignable as $row) {
    $headroom += max(0, shard_high_water_mb() - (float)$row['size_mb']);
}

echo "    Sized {$res['checked']} shards"
   . ($res['drained'] ? "; drained: " . implode(', ', $res['drained']) : '')
   . "; " . count($assignable) . " accepting new users, "
   . round($headroom / 1024, 1) . "GB headroom.\n";

<?php
/**
 * cron/minutes/60/check_db_sizes.php
 * Hourly: refresh the shard DB size cache and log WARNING/CRITICAL alerts for
 * any schema crossing a threshold. Runs via cron.php (minute % 60 == 0).
 */

$rows = refresh_db_size_cache();

$warn = 0; $crit = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'warning')  { $warn++; }
    if ($r['status'] === 'critical') { $crit++; }
}
echo "    Checked " . count($rows) . " schemas — $warn warning, $crit critical.\n";

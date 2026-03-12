<?php
/**
 * Purge old notifications and orphaned records.
 * Runs on January 1st via cron/cron.php.
 */

// Delete read notifications older than 90 days
$r = db_query("DELETE FROM notification WHERE is_read = 1 AND created < DATE_SUB(NOW(), INTERVAL 90 DAY)");
echo "  Purged old read notifications.\n";

// Delete orphaned devices (no API keys pointing to them)
$r = db_query("DELETE FROM device WHERE device_id NOT IN (SELECT DISTINCT device_id FROM api_key WHERE device_id IS NOT NULL)");
echo "  Purged orphaned devices.\n";

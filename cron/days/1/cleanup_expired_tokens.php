<?php
/**
 * Cleanup expired forgot tokens and old API keys.
 * Runs on the 1st of each month via cron/cron.php.
 */

// Delete forgot tokens older than 7 days
$r = db_query("DELETE FROM forgot WHERE created < DATE_SUB(NOW(), INTERVAL 7 DAY)");
echo "  Cleaned up expired forgot tokens.\n";

// Delete API keys older than 90 days
$r = db_query("DELETE FROM api_key WHERE key_born < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
echo "  Cleaned up old API keys.\n";

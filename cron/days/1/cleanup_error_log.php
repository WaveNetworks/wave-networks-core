<?php
/**
 * cron/days/1/cleanup_error_log.php
 * Purges error log entries older than 30 days.
 * Runs on the 1st of each month via cron.php.
 */

$deleted = clear_error_logs(30);
echo "    Cleaned up $deleted old error log entries.\n";

# Cron jobs

All cron scripts: CLI-only, bootstrap via include/common_readonly.php.
common_readonly.php loads config + DB + helpers but NO actions, NO session guard.

Scripts:
  cron/cron.php                  — every minute: email queue + scheduled tasks
  cron/sync_users.php            — migration sync from external DB
  cron/push_digest.php           — daily push digest (weekly on Mondays)
  cron/cleanup_notifications.php — monthly: prune old notifications + stale subscriptions

Subdirectories (cron/minutes/, days/, weeks/, months/) hold task scripts
executed by cron.php on their respective intervals.

Daily (cron/days/1/):
  cleanup_error_log.php        — deletes error_log entries older than 30 days
  cleanup_expired_tokens.php   — deletes forgot tokens > 7 days, api_key > 90 days

Monthly (cron/months/1/):
  purge_deleted_users.php      — deletes read notifications > 90 days, orphaned devices

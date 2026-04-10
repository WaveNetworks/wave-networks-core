# Email queue system

Main DB stores: email_settings (SMTP config, throttle limits, allowed senders)
                email_queue (pending/sent/failed messages)

Sending: queue_email($to, $subject, $body, $opts) inserts into email_queue.
         cron/cron.php processes the queue respecting throttle limits.
         Child apps call queue_email() after including common.php — no SMTP config needed.

Admin UI: views/email.php — SMTP settings, throttle config, allowed senders, queue log.
Helpers: include/common/emailFunctions.php, emailQueueFunctions.php
Actions: include/actions/memberActions/emailSettingsActions.php

Throttle limits (configurable in admin):
  per_minute, per_hour, per_day — checked before each send batch.

Allowed senders: email_allowed_senders table. Only listed addresses can appear
  in the From header. Default from/reply-to configured in email_settings.

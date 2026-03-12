<?php
/**
 * cron/minutes/1/process_email_queue.php
 * Processes queued emails respecting throttle limits.
 * Included by cron/cron.php every 1 minute — no standalone bootstrap needed.
 */

$settings   = get_email_settings();
$per_minute = $settings['throttle_per_minute'];
$per_hour   = $settings['throttle_per_hour'];

// Check hourly budget
$sent_hour = count_emails_sent_since(3600);
if ($sent_hour >= $per_hour) {
    echo "    Hourly limit reached ($sent_hour/$per_hour). Skipping.\n";
    return;
}

// Calculate batch size for this run
$batch_size = min($per_minute, $per_hour - $sent_hour);
if ($batch_size <= 0) {
    return;
}

// Fetch queued items ready to send
$r = db_query("SELECT * FROM email_queue
               WHERE status = 'queued'
                 AND scheduled_at <= NOW()
                 AND attempts < max_attempts
               ORDER BY scheduled_at ASC
               LIMIT $batch_size");

$items = db_fetch_all($r);
$sent_count   = 0;
$failed_count = 0;

foreach ($items as $item) {
    $qid = (int)$item['queue_id'];
    $attempts = (int)$item['attempts'] + 1;

    // Mark as sending
    db_query("UPDATE email_queue SET status = 'sending', attempts = '$attempts' WHERE queue_id = '$qid'");

    // Attempt send
    $ok = send_queued_email($item);

    if ($ok) {
        db_query("UPDATE email_queue SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE queue_id = '$qid'");
        $sent_count++;
    } else {
        $max = (int)$item['max_attempts'];
        $new_status = ($attempts >= $max) ? 'failed' : 'queued';
        $err_msg = sanitize('Send attempt ' . $attempts . ' failed', SQL);
        db_query("UPDATE email_queue SET status = '$new_status', error_message = '$err_msg' WHERE queue_id = '$qid'");
        $failed_count++;
    }
}

// Auto-purge old sent items (older than 30 days)
db_query("DELETE FROM email_queue WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

$total = count($items);
if ($total > 0) {
    echo "    Processed $total emails: $sent_count sent, $failed_count failed.\n";
}

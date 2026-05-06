<?php
/**
 * process_scheduled_emails.php
 * Drains pending scheduled_email rows whose scheduled_for has passed:
 *   1. Render the named template with context_json
 *   2. Look up the user's email + name
 *   3. Call queue_email() — respects existing throttle limits
 *   4. Mark the row sent + record queue_id
 *   5. If the row originated from a drip enrollment, advance the enrollment
 *      to schedule the next step.
 *
 * Bootstraps via common_readonly.php (CLI, no session). Runs every 5 minutes
 * via the admin cron runner.
 */

global $db;

// Throttle: cap rows per cron tick so a backlog can't drain the SMTP budget
// in a single pass. Tune via the BATCH_LIMIT constant if needed.
$batchLimit = 100;

try {
    $stmt = $db->query("SELECT scheduled_id, user_id, template_slug, context_json,
                               enrollment_id, drip_step_id
                          FROM scheduled_email
                         WHERE status = 'pending'
                           AND scheduled_for <= NOW()
                         ORDER BY scheduled_for ASC
                         LIMIT $batchLimit");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "    scheduled_email table not available: " . $e->getMessage() . "\n";
    return;
}

if (empty($rows)) {
    echo "    No pending scheduled emails.\n";
    return;
}

echo "    Processing " . count($rows) . " scheduled email(s)...\n";

foreach ($rows as $row) {
    $sid     = (int)$row['scheduled_id'];
    $uid     = (int)$row['user_id'];
    $slug    = $row['template_slug'];
    $context = json_decode($row['context_json'] ?? '{}', true) ?: [];

    // Resolve the recipient
    $recipient = null;
    try {
        $stmt = $db->prepare("SELECT user_id, email, shard_id FROM user WHERE user_id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    if (!$recipient || empty($recipient['email'])) {
        $err = sanitize('Recipient user not found or missing email', SQL);
        db_query("UPDATE scheduled_email
                     SET status = 'failed', error_message = '$err'
                   WHERE scheduled_id = '$sid'");
        echo "    [#$sid] FAILED — recipient missing\n";
        continue;
    }

    // Pull profile from shard for personalization vars (best effort)
    $profile = null;
    if (function_exists('get_user_profile') && !empty($recipient['shard_id'])) {
        try {
            $profile = get_user_profile($uid, $recipient['shard_id']);
        } catch (Exception $e) {}
    }

    // Merge auto-vars on top of stored context — context wins
    $vars = array_merge([
        'first_name' => $profile['first_name'] ?? '',
        'last_name'  => $profile['last_name']  ?? '',
        'email'      => $recipient['email'],
        'user_id'    => $uid,
    ], $context);

    $rendered = render_email_template($slug, $vars);
    if (!$rendered) {
        $err = sanitize("Template '$slug' not found", SQL);
        db_query("UPDATE scheduled_email
                     SET status = 'failed', error_message = '$err'
                   WHERE scheduled_id = '$sid'");
        echo "    [#$sid] FAILED — template missing: $slug\n";
        continue;
    }

    $body_html = $rendered['body_format'] === 'markdown'
        ? _scheduled_email_markdown_to_html($rendered['body'])
        : $rendered['body'];

    $to_name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));

    $queue_id = queue_email(
        $recipient['email'],
        $to_name,
        $rendered['subject'],
        $body_html,
        ['source_app' => 'core_scheduled']
    );

    if (!$queue_id) {
        $err = sanitize('queue_email() failed', SQL);
        db_query("UPDATE scheduled_email
                     SET status = 'failed', error_message = '$err'
                   WHERE scheduled_id = '$sid'");
        echo "    [#$sid] FAILED — queue_email rejected\n";
        continue;
    }

    db_query("UPDATE scheduled_email
                 SET status = 'sent', sent_at = NOW(), queue_id = '$queue_id'
               WHERE scheduled_id = '$sid'");
    echo "    [#$sid] sent — template='$slug' queue_id=$queue_id\n";

    // Advance the drip enrollment if this was a campaign step
    $eid = (int)($row['enrollment_id'] ?? 0);
    if ($eid > 0 && function_exists('advance_drip_enrollment')) {
        advance_drip_enrollment($eid);
    }
}

/**
 * Tiny markdown shim — admin core does not bundle a parser. We only need to
 * cover the basics that template authors write by hand: paragraph breaks,
 * bold, italic, links, and inline code. Anything more advanced should use
 * body_format='html'.
 *
 * @param string $md
 * @return string
 */
function _scheduled_email_markdown_to_html($md) {
    if ($md === '' || $md === null) return '';
    $html = htmlspecialchars($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
        return '<a href="' . $m[2] . '">' . $m[1] . '</a>';
    }, $html);
    // Paragraphs from blank lines
    $paragraphs = preg_split('/\n\s*\n/', $html);
    $paragraphs = array_map(function($p) {
        return '<p>' . nl2br(trim($p)) . '</p>';
    }, $paragraphs);
    return implode("\n", $paragraphs);
}

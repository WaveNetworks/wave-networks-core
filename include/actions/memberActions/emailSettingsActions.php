<?php
/**
 * Email Settings Actions (admin only)
 * Actions: saveEmailSettings, testEmailSettings, addAllowedSender,
 *          deleteAllowedSender, setDefaultSender, retryFailedEmail,
 *          deleteQueuedEmail, getQueueItems
 */

// ── Save SMTP + throttle + default sender settings ──
if (($_POST['action'] ?? '') == 'saveEmailSettings') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $smtp_host       = trim($_POST['smtp_host'] ?? '');
    $smtp_port       = (int)($_POST['smtp_port'] ?? 587);
    $smtp_user       = trim($_POST['smtp_user'] ?? '');
    $smtp_pass_input = $_POST['smtp_pass'] ?? '';
    $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
    $from_email      = trim($_POST['default_from_email'] ?? '');
    $from_name       = trim($_POST['default_from_name'] ?? '');
    $reply_to        = trim($_POST['default_reply_to'] ?? '');
    $per_minute      = (int)($_POST['throttle_per_minute'] ?? 10);
    $per_hour        = (int)($_POST['throttle_per_hour'] ?? 200);
    $max_attempts    = (int)($_POST['max_attempts'] ?? 3);

    if (!in_array($smtp_encryption, ['tls', 'ssl', 'none'])) {
        $errs['encryption'] = 'Invalid encryption type.';
    }
    if ($from_email && !valid_email($from_email)) {
        $errs['from_email'] = 'Invalid default from email.';
    }
    if ($reply_to && !valid_email($reply_to)) {
        $errs['reply_to'] = 'Invalid reply-to email.';
    }
    if ($per_minute < 1)   { $per_minute = 1; }
    if ($per_hour < 1)     { $per_hour = 1; }
    if ($max_attempts < 1) { $max_attempts = 1; }

    if (count($errs) <= 0) {
        $s_host       = sanitize($smtp_host, SQL);
        $s_port       = $smtp_port;
        $s_user       = sanitize($smtp_user, SQL);
        $s_encryption = sanitize($smtp_encryption, SQL);
        $s_from_email = sanitize($from_email, SQL);
        $s_from_name  = sanitize($from_name, SQL);
        $s_reply_to   = sanitize($reply_to, SQL);

        // Build SET clause — only update password if a new one was provided
        $set = "smtp_host = '$s_host',
                smtp_port = '$s_port',
                smtp_user = '$s_user',
                smtp_encryption = '$s_encryption',
                default_from_email = '$s_from_email',
                default_from_name = '$s_from_name',
                default_reply_to = '$s_reply_to',
                throttle_per_minute = '$per_minute',
                throttle_per_hour = '$per_hour',
                max_attempts = '$max_attempts',
                updated = NOW()";

        if (!empty($smtp_pass_input)) {
            $s_pass = sanitize($smtp_pass_input, SQL);
            $set .= ", smtp_pass = '$s_pass'";
        }

        $r = db_query("UPDATE email_settings SET $set WHERE setting_id = 1");
        if ($r) {
            clear_email_settings_cache();
            $_SESSION['success'] = 'Email settings saved.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Send test email ──
if (($_POST['action'] ?? '') == 'testEmailSettings') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        // Get admin's email from session
        $uid = (int)$_SESSION['user_id'];
        $user = db_fetch(db_query("SELECT email FROM user WHERE user_id = '$uid'"));
        $admin_email = $user['email'] ?? '';

        if (empty($admin_email)) {
            $_SESSION['error'] = 'Could not determine your email address.';
        } else {
            $ok = send_email($admin_email, '', 'Test Email', '<h2>Test Email</h2><p>This is a test email from your admin panel. If you received this, your SMTP settings are working correctly.</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p>');
            if ($ok) {
                $_SESSION['success'] = 'Test email sent to ' . h($admin_email) . '.';
            } else {
                $_SESSION['error'] = 'Test email failed. Check your SMTP settings and server error logs.';
            }
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Add allowed sender ──
if (($_POST['action'] ?? '') == 'addAllowedSender') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $email = trim($_POST['email_address'] ?? '');
    $name  = trim($_POST['display_name'] ?? '');
    $is_default = (int)(!empty($_POST['is_default']));

    if (!valid_email($email)) { $errs['email'] = 'Valid email address required.'; }

    if (count($errs) <= 0) {
        $s_email = sanitize($email, SQL);
        $s_name  = sanitize($name, SQL);

        // If setting as default, unset all others first
        if ($is_default) {
            db_query("UPDATE email_allowed_sender SET is_default = 0");
        }

        $r = db_query("INSERT INTO email_allowed_sender (email_address, display_name, is_default, created)
                        VALUES ('$s_email', '$s_name', '$is_default', NOW())");
        if ($r) {
            $_SESSION['success'] = 'Allowed sender added.';
        } else {
            $_SESSION['error'] = 'Sender already exists or database error.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Delete allowed sender ──
if (($_POST['action'] ?? '') == 'deleteAllowedSender') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $sender_id = (int)($_POST['sender_id'] ?? 0);
    if ($sender_id <= 0) { $errs['id'] = 'Invalid sender ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("DELETE FROM email_allowed_sender WHERE sender_id = '$sender_id'");
        if ($r) {
            $_SESSION['success'] = 'Sender removed.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Set default sender ──
if (($_POST['action'] ?? '') == 'setDefaultSender') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $sender_id = (int)($_POST['sender_id'] ?? 0);
    if ($sender_id <= 0) { $errs['id'] = 'Invalid sender ID.'; }

    if (count($errs) <= 0) {
        db_query("UPDATE email_allowed_sender SET is_default = 0");
        $r = db_query("UPDATE email_allowed_sender SET is_default = 1 WHERE sender_id = '$sender_id'");
        if ($r) {
            $_SESSION['success'] = 'Default sender updated.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Retry failed email ──
if (($_POST['action'] ?? '') == 'retryFailedEmail') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $queue_id = (int)($_POST['queue_id'] ?? 0);
    if ($queue_id <= 0) { $errs['id'] = 'Invalid queue ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("UPDATE email_queue SET status = 'queued', attempts = 0, error_message = NULL
                        WHERE queue_id = '$queue_id' AND status = 'failed'");
        if ($r) {
            $_SESSION['success'] = 'Email re-queued for retry.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Delete queued/failed email ──
if (($_POST['action'] ?? '') == 'deleteQueuedEmail') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $queue_id = (int)($_POST['queue_id'] ?? 0);
    if ($queue_id <= 0) { $errs['id'] = 'Invalid queue ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("DELETE FROM email_queue WHERE queue_id = '$queue_id' AND status IN ('queued', 'failed')");
        if ($r) {
            $_SESSION['success'] = 'Queue item deleted.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get queue items (paginated, AJAX) ──
if (($_POST['action'] ?? '') == 'getQueueItems') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $status_filter = $_POST['status'] ?? '';
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = min(100, max(10, (int)($_POST['per_page'] ?? 50)));
        $offset   = ($page - 1) * $per_page;

        $where = "1=1";
        if (in_array($status_filter, ['queued', 'sending', 'sent', 'failed'])) {
            $where = "status = '" . sanitize($status_filter, SQL) . "'";
        }

        // Total count
        $r = db_query("SELECT COUNT(*) as cnt FROM email_queue WHERE $where");
        $total = (int)db_fetch($r)['cnt'];

        // Items
        $r2 = db_query("SELECT queue_id, from_email, to_email, to_name, subject, status, attempts,
                                max_attempts, error_message, source_app, created, scheduled_at, sent_at
                         FROM email_queue WHERE $where
                         ORDER BY created DESC LIMIT $offset, $per_page");
        $items = db_fetch_all($r2);

        $data['items'] = $items;
        $data['total'] = $total;
        $data['page']  = $page;
        $data['per_page'] = $per_page;
        $_SESSION['success'] = '';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

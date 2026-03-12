<?php
/**
 * emailQueueFunctions.php
 * Email queue, throttling, allowed senders, and DNS deliverability helpers.
 * Auto-included by glob in common.php — available to all apps.
 */

/**
 * Get email settings from DB, falling back to config.php globals.
 * Static-cached per request.
 *
 * @return array
 */
function get_email_settings() {
    static $cache = null;
    if ($cache !== null) return $cache;

    global $db, $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $mail_from, $mail_from_name;

    $settings = [];
    try {
        $r = $db->query("SELECT * FROM email_settings WHERE setting_id = 1");
        $settings = $r->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        // Table may not exist yet (pre-migration)
    }

    // Fall back to config.php globals for any empty DB fields
    $cache = [
        'smtp_host'          => !empty($settings['smtp_host'])          ? $settings['smtp_host']          : ($smtp_host ?? ''),
        'smtp_port'          => !empty($settings['smtp_port'])          ? (int)$settings['smtp_port']     : (int)($smtp_port ?? 587),
        'smtp_user'          => !empty($settings['smtp_user'])          ? $settings['smtp_user']          : ($smtp_user ?? ''),
        'smtp_pass'          => !empty($settings['smtp_pass'])          ? $settings['smtp_pass']          : ($smtp_pass ?? ''),
        'smtp_encryption'    => !empty($settings['smtp_encryption'])    ? $settings['smtp_encryption']    : 'tls',
        'default_from_email' => !empty($settings['default_from_email']) ? $settings['default_from_email'] : ($mail_from ?? ''),
        'default_from_name'  => !empty($settings['default_from_name'])  ? $settings['default_from_name']  : ($mail_from_name ?? ''),
        'default_reply_to'   => $settings['default_reply_to'] ?? '',
        'throttle_per_minute' => (int)($settings['throttle_per_minute'] ?? 10),
        'throttle_per_hour'   => (int)($settings['throttle_per_hour']   ?? 200),
        'max_attempts'        => (int)($settings['max_attempts']        ?? 3),
    ];

    return $cache;
}

/**
 * Invalidate the cached email settings (call after saving new settings).
 */
function clear_email_settings_cache() {
    // Re-declare the static by calling get_email_settings — we need a different approach
    // Instead, we use a global flag
    global $_email_settings_dirty;
    $_email_settings_dirty = true;
}

/**
 * Get email settings, respecting the dirty flag.
 * Internal wrapper that handles cache invalidation.
 *
 * @return array
 */
function _get_email_settings_fresh() {
    global $_email_settings_dirty;
    if (!empty($_email_settings_dirty)) {
        $_email_settings_dirty = false;
        // Force re-fetch by bypassing the static cache
        // We do this by querying directly
        global $db, $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $mail_from, $mail_from_name;
        $settings = [];
        try {
            $r = $db->query("SELECT * FROM email_settings WHERE setting_id = 1");
            $settings = $r->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {}

        return [
            'smtp_host'          => !empty($settings['smtp_host'])          ? $settings['smtp_host']          : ($smtp_host ?? ''),
            'smtp_port'          => !empty($settings['smtp_port'])          ? (int)$settings['smtp_port']     : (int)($smtp_port ?? 587),
            'smtp_user'          => !empty($settings['smtp_user'])          ? $settings['smtp_user']          : ($smtp_user ?? ''),
            'smtp_pass'          => !empty($settings['smtp_pass'])          ? $settings['smtp_pass']          : ($smtp_pass ?? ''),
            'smtp_encryption'    => !empty($settings['smtp_encryption'])    ? $settings['smtp_encryption']    : 'tls',
            'default_from_email' => !empty($settings['default_from_email']) ? $settings['default_from_email'] : ($mail_from ?? ''),
            'default_from_name'  => !empty($settings['default_from_name'])  ? $settings['default_from_name']  : ($mail_from_name ?? ''),
            'default_reply_to'   => $settings['default_reply_to'] ?? '',
            'throttle_per_minute' => (int)($settings['throttle_per_minute'] ?? 10),
            'throttle_per_hour'   => (int)($settings['throttle_per_hour']   ?? 200),
            'max_attempts'        => (int)($settings['max_attempts']        ?? 3),
        ];
    }
    return get_email_settings();
}

/**
 * Get the default allowed sender. Falls back to email_settings defaults.
 *
 * @return array|false  ['email_address' => ..., 'display_name' => ...] or false
 */
function get_default_sender() {
    global $db;

    try {
        $r = $db->query("SELECT email_address, display_name FROM email_allowed_sender WHERE is_default = 1 LIMIT 1");
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (PDOException $e) {}

    // Fall back to email_settings defaults
    $settings = get_email_settings();
    if (!empty($settings['default_from_email'])) {
        return [
            'email_address' => $settings['default_from_email'],
            'display_name'  => $settings['default_from_name'],
        ];
    }

    return false;
}

/**
 * Check if a from_email is in the allowed senders list.
 * If no senders are configured, allows anything (backward compat).
 *
 * @param string $email
 * @return bool
 */
function is_allowed_sender($email) {
    global $db;

    try {
        // Check if any senders are configured
        $r = $db->query("SELECT COUNT(*) as cnt FROM email_allowed_sender");
        $count = (int)$r->fetch(PDO::FETCH_ASSOC)['cnt'];

        if ($count === 0) return true; // No whitelist = allow all

        $s_email = sanitize(strtolower(trim($email)), SQL);
        $r2 = $db->query("SELECT sender_id FROM email_allowed_sender WHERE LOWER(email_address) = '$s_email' LIMIT 1");
        return (bool)$r2->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return true; // Table may not exist yet
    }
}

/**
 * Get all allowed senders.
 *
 * @return array
 */
function get_allowed_senders() {
    global $db;

    try {
        $r = $db->query("SELECT * FROM email_allowed_sender ORDER BY is_default DESC, email_address ASC");
        return $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Queue an email for delivery. Primary function for child apps.
 *
 * @param string $to_email
 * @param string $to_name
 * @param string $subject
 * @param string $body_html
 * @param array  $opts  Optional keys:
 *   'from_email'    => string (must be in allowed_senders; uses default if omitted)
 *   'from_name'     => string
 *   'reply_to'      => string
 *   'body_text'     => string (auto-stripped from HTML if omitted)
 *   'source_app'    => string (e.g. 'coaching'; defaults to 'core')
 *   'scheduled_at'  => string datetime (defaults to NOW)
 * @return int|false  queue_id on success, false on validation failure
 */
function queue_email($to_email, $to_name, $subject, $body_html, $opts = []) {
    global $db;

    // Validate recipient
    if (!valid_email($to_email)) {
        error_log("queue_email: invalid to_email '$to_email'");
        return false;
    }

    if (empty($subject)) {
        error_log("queue_email: empty subject");
        return false;
    }

    $settings = get_email_settings();

    // Resolve from address
    $from_email = $opts['from_email'] ?? '';
    $from_name  = $opts['from_name']  ?? '';

    if (empty($from_email)) {
        $default = get_default_sender();
        if ($default) {
            $from_email = $default['email_address'];
            $from_name  = $from_name ?: $default['display_name'];
        } else {
            $from_email = $settings['default_from_email'];
            $from_name  = $from_name ?: $settings['default_from_name'];
        }
    }

    if (empty($from_email)) {
        error_log("queue_email: no from_email and no default sender configured");
        return false;
    }

    // Check allowed senders
    if (!is_allowed_sender($from_email)) {
        error_log("queue_email: from_email '$from_email' is not an allowed sender");
        return false;
    }

    // Resolve reply-to
    $reply_to = $opts['reply_to'] ?? '';
    if (empty($reply_to) && !empty($settings['default_reply_to'])) {
        $reply_to = $settings['default_reply_to'];
    }

    // Body text fallback
    $body_text = $opts['body_text'] ?? strip_tags($body_html);

    // Source app
    $source_app = $opts['source_app'] ?? 'core';

    // Scheduled time
    $scheduled_at = $opts['scheduled_at'] ?? date('Y-m-d H:i:s');

    // Max attempts from settings
    $max_attempts = $settings['max_attempts'];

    // Sanitize and insert
    $s_from_email   = sanitize($from_email, SQL);
    $s_from_name    = sanitize($from_name, SQL);
    $s_reply_to     = sanitize($reply_to, SQL);
    $s_to_email     = sanitize($to_email, SQL);
    $s_to_name      = sanitize($to_name, SQL);
    $s_subject      = sanitize($subject, SQL);
    $s_body_html    = sanitize($body_html, SQL);
    $s_body_text    = sanitize($body_text, SQL);
    $s_source_app   = sanitize($source_app, SQL);
    $s_scheduled_at = sanitize($scheduled_at, SQL);

    $sql = "INSERT INTO email_queue
            (from_email, from_name, reply_to, to_email, to_name, subject, body_html, body_text,
             status, attempts, max_attempts, source_app, created, scheduled_at)
            VALUES
            ('$s_from_email', '$s_from_name', '$s_reply_to', '$s_to_email', '$s_to_name', '$s_subject',
             '$s_body_html', '$s_body_text', 'queued', 0, '$max_attempts', '$s_source_app', NOW(), '$s_scheduled_at')";

    $r = db_query($sql);
    if ($r) {
        return (int)db_insert_id();
    }

    return false;
}

/**
 * Send an email immediately and record a datapoint in the queue.
 * Used for time-sensitive emails (auth confirmation, password reset)
 * that must not wait for the cron queue but should still count toward throttle.
 *
 * @param string $to_email
 * @param string $to_name
 * @param string $subject
 * @param string $body_html
 * @param array  $opts  Same as queue_email() plus 'alt_body'
 * @return bool
 */
function send_email_now($to_email, $to_name, $subject, $body_html, $opts = []) {
    global $db;

    $settings = get_email_settings();

    // Resolve from address
    $from_email = $opts['from_email'] ?? '';
    $from_name  = $opts['from_name']  ?? '';

    if (empty($from_email)) {
        $default = get_default_sender();
        if ($default) {
            $from_email = $default['email_address'];
            $from_name  = $from_name ?: $default['display_name'];
        } else {
            $from_email = $settings['default_from_email'];
            $from_name  = $from_name ?: $settings['default_from_name'];
        }
    }

    // Resolve reply-to
    $reply_to = $opts['reply_to'] ?? '';
    if (empty($reply_to) && !empty($settings['default_reply_to'])) {
        $reply_to = $settings['default_reply_to'];
    }

    $alt_body   = $opts['alt_body']   ?? strip_tags($body_html);
    $source_app = $opts['source_app'] ?? 'core';

    // Send immediately via the low-level send_email()
    $ok = send_email($to_email, $to_name, $subject, $body_html, $alt_body);

    // Record a datapoint in the queue regardless of success/failure
    $s_from_email = sanitize($from_email ?: $settings['default_from_email'], SQL);
    $s_from_name  = sanitize($from_name ?: $settings['default_from_name'], SQL);
    $s_reply_to   = sanitize($reply_to, SQL);
    $s_to_email   = sanitize($to_email, SQL);
    $s_to_name    = sanitize($to_name, SQL);
    $s_subject    = sanitize($subject, SQL);
    $s_body_html  = sanitize($body_html, SQL);
    $s_body_text  = sanitize($alt_body, SQL);
    $s_source_app = sanitize($source_app, SQL);
    $status       = $ok ? 'sent' : 'failed';
    $sent_at      = $ok ? "NOW()" : "NULL";
    $error        = $ok ? '' : 'Immediate send failed';
    $s_error      = sanitize($error, SQL);

    try {
        $db->exec("INSERT INTO email_queue
            (from_email, from_name, reply_to, to_email, to_name, subject, body_html, body_text,
             status, attempts, max_attempts, error_message, source_app, created, scheduled_at, sent_at)
            VALUES
            ('$s_from_email', '$s_from_name', '$s_reply_to', '$s_to_email', '$s_to_name', '$s_subject',
             '$s_body_html', '$s_body_text', '$status', 1, 1, '$s_error', '$s_source_app', NOW(), NOW(), $sent_at)");
    } catch (PDOException $e) {
        // Don't fail the send just because the datapoint logging failed
        error_log("send_email_now: failed to log datapoint: " . $e->getMessage());
    }

    return $ok;
}

/**
 * Send a single email from a queue row. Used by the cron processor.
 *
 * @param array $row  A row from email_queue
 * @return bool
 */
function send_queued_email($row) {
    $settings = get_email_settings();

    if (empty($settings['smtp_host'])) {
        error_log("send_queued_email: SMTP not configured");
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'];
        $mail->Port = $settings['smtp_port'];

        if (!empty($settings['smtp_user'])) {
            $mail->SMTPAuth   = true;
            $mail->Username   = $settings['smtp_user'];
            $mail->Password   = $settings['smtp_pass'];

            switch ($settings['smtp_encryption']) {
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'tls':
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    break;
                case 'none':
                    $mail->SMTPSecure = false;
                    $mail->SMTPAutoTLS = false;
                    break;
            }
        } else {
            $mail->SMTPAuth   = false;
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($row['from_email'], $row['from_name'] ?? '');

        if (!empty($row['reply_to'])) {
            $mail->addReplyTo($row['reply_to']);
        }

        $mail->addAddress($row['to_email'], $row['to_name'] ?? '');

        $mail->isHTML(true);
        $mail->Subject = $row['subject'];
        $mail->Body    = $row['body_html'];
        $mail->AltBody = $row['body_text'] ?: strip_tags($row['body_html']);

        $mail->send();
        return true;

    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log("send_queued_email: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Count emails sent within the last N seconds (for throttle checks).
 *
 * @param int $seconds
 * @return int
 */
function count_emails_sent_since($seconds) {
    global $db;

    $seconds = (int)$seconds;
    try {
        $r = $db->query("SELECT COUNT(*) as cnt FROM email_queue
                         WHERE status = 'sent' AND sent_at > DATE_SUB(NOW(), INTERVAL $seconds SECOND)");
        return (int)$r->fetch(PDO::FETCH_ASSOC)['cnt'];
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get email queue statistics for the admin dashboard.
 *
 * @return array
 */
function get_email_queue_stats() {
    global $db;

    $stats = [
        'queued'     => 0,
        'sending'    => 0,
        'sent'       => 0,
        'failed'     => 0,
        'sent_today' => 0,
        'sent_hour'  => 0,
    ];

    try {
        // Counts by status
        $r = $db->query("SELECT status, COUNT(*) as cnt FROM email_queue GROUP BY status");
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['status']] = (int)$row['cnt'];
        }

        // Sent today
        $r2 = $db->query("SELECT COUNT(*) as cnt FROM email_queue
                          WHERE status = 'sent' AND sent_at > CURDATE()");
        $stats['sent_today'] = (int)$r2->fetch(PDO::FETCH_ASSOC)['cnt'];

        // Sent this hour
        $stats['sent_hour'] = count_emails_sent_since(3600);

    } catch (PDOException $e) {}

    return $stats;
}

/**
 * Look up DNS TXT records for a domain and extract SPF and DKIM entries.
 *
 * @param string $domain
 * @return array ['spf' => string|null, 'dkim' => array, 'domain' => string]
 */
function get_email_dns_info($domain) {
    $result = [
        'domain' => $domain,
        'spf'    => null,
        'dkim'   => [],
    ];

    if (empty($domain)) return $result;

    // SPF: look for v=spf1 in TXT records
    try {
        $txt_records = @dns_get_record($domain, DNS_TXT);
        if ($txt_records) {
            foreach ($txt_records as $rec) {
                $txt = $rec['txt'] ?? '';
                if (stripos($txt, 'v=spf1') === 0) {
                    $result['spf'] = $txt;
                    break;
                }
            }
        }
    } catch (\Exception $e) {}

    // DKIM: try common selectors
    $selectors = ['default', 'google', 'selector1', 'selector2', 'mail', 'dkim', 'k1'];
    foreach ($selectors as $selector) {
        $dkim_domain = $selector . '._domainkey.' . $domain;
        try {
            $dkim_records = @dns_get_record($dkim_domain, DNS_TXT);
            if ($dkim_records) {
                foreach ($dkim_records as $rec) {
                    $txt = $rec['txt'] ?? '';
                    if (!empty($txt)) {
                        $result['dkim'][] = [
                            'selector' => $selector,
                            'record'   => $txt,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {}
    }

    // Also try CNAME for DKIM (some providers use CNAME instead of TXT)
    foreach ($selectors as $selector) {
        $dkim_domain = $selector . '._domainkey.' . $domain;
        try {
            $cname_records = @dns_get_record($dkim_domain, DNS_CNAME);
            if ($cname_records) {
                foreach ($cname_records as $rec) {
                    $target = $rec['target'] ?? '';
                    if (!empty($target)) {
                        // Check we haven't already found this selector via TXT
                        $already = false;
                        foreach ($result['dkim'] as $d) {
                            if ($d['selector'] === $selector) { $already = true; break; }
                        }
                        if (!$already) {
                            $result['dkim'][] = [
                                'selector' => $selector,
                                'record'   => 'CNAME → ' . $target,
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {}
    }

    return $result;
}

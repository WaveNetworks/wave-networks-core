<?php
/**
 * emailFunctions.php
 * Low-level email sending via PHPMailer.
 * Reads SMTP settings from DB (via get_email_settings()) with config.php fallback.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email using the configured SMTP settings.
 * Reads from DB first, falls back to config.php globals.
 *
 * @param string $to_email
 * @param string $to_name
 * @param string $subject
 * @param string $body       HTML body
 * @param string $alt_body   Plain-text fallback (optional)
 * @return bool
 */
function send_email($to_email, $to_name, $subject, $body, $alt_body = '') {
    // Use DB settings with config.php fallback
    $cfg = function_exists('get_email_settings') ? get_email_settings() : [];

    // If get_email_settings isn't available yet (pre-migration), use globals
    if (empty($cfg['smtp_host'])) {
        global $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $mail_from, $mail_from_name;
        $cfg = [
            'smtp_host'       => $smtp_host ?? '',
            'smtp_port'       => (int)($smtp_port ?? 587),
            'smtp_user'       => $smtp_user ?? '',
            'smtp_pass'       => $smtp_pass ?? '',
            'smtp_encryption' => 'tls',
            'default_from_email' => $mail_from ?? '',
            'default_from_name'  => $mail_from_name ?? '',
        ];
    }

    if (empty($cfg['smtp_host'])) {
        error_log("Email not sent — SMTP not configured. To: $to_email Subject: $subject");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $cfg['smtp_host'];
        $mail->Port = $cfg['smtp_port'];

        if (!empty($cfg['smtp_user'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['smtp_user'];
            $mail->Password = $cfg['smtp_pass'];

            switch ($cfg['smtp_encryption'] ?? 'tls') {
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'tls':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
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

        $from_email = $cfg['default_from_email'] ?? '';
        $from_name  = $cfg['default_from_name']  ?? '';
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $alt_body ?: strip_tags($body);

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Email send failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send a confirmation email to a newly registered user.
 * Uses send_email_now() to record a throttle datapoint.
 *
 * @param string $email
 * @param string $confirm_hash
 * @return bool
 */
function send_confirmation_email($email, $confirm_hash) {
    $base_url = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
          . $base_url . '/auth/confirm.php?hash=' . urlencode($confirm_hash);

    $body = '<h2>Confirm Your Account</h2>'
          . '<p>Click the link below to confirm your email address:</p>'
          . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
          . '<p>If you did not create an account, you can ignore this email.</p>';

    // Use send_email_now() if available (records throttle datapoint)
    if (function_exists('send_email_now')) {
        return send_email_now($email, '', 'Confirm Your Account', $body, ['source_app' => 'core']);
    }
    return send_email($email, '', 'Confirm Your Account', $body);
}

/**
 * Send a password reset email.
 * Uses send_email_now() to record a throttle datapoint.
 *
 * @param string $email
 * @param string $reset_token
 * @return bool
 */
function send_reset_email($email, $reset_token) {
    $base_url = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
          . $base_url . '/auth/reset.php?token=' . urlencode($reset_token);

    $body = '<h2>Password Reset</h2>'
          . '<p>Click the link below to reset your password:</p>'
          . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
          . '<p>This link expires in 24 hours.</p>'
          . '<p>If you did not request a password reset, you can ignore this email.</p>';

    // Use send_email_now() if available (records throttle datapoint)
    if (function_exists('send_email_now')) {
        return send_email_now($email, '', 'Password Reset Request', $body, ['source_app' => 'core']);
    }
    return send_email($email, '', 'Password Reset Request', $body);
}

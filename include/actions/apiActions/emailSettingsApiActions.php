<?php
/**
 * emailSettingsApiActions.php — the admin outbound mail account over the service API (task #2809).
 *
 * nokemo's provisioning runner creates support@<domain> as a real mailbox on new sites and hands
 * it to the app here, so outbound mail and the help desk both send as support@ with no owner
 * click. Same effect as Admin › Email settings (saveEmailSettings) for the fields it carries.
 *
 *   apiGetEmailSettings (email:admin) — host, port, user, encryption, From, has_password. Never the password.
 *   apiSetEmailSettings (email:admin) — smtp_host, smtp_port, smtp_user, smtp_pass (empty = keep),
 *                                       smtp_encryption tls|ssl|none, from_email, from_name, reply_to.
 *                                       from_email is added as an allowed sender and made the default.
 * The password is sealed at rest (wn_secret_seal) exactly as the settings form does, and is
 * never echoed back.
 */

if (!function_exists('email_settings_public')) {
    function email_settings_public() {
        $r = db_query("SELECT smtp_host, smtp_port, smtp_user, smtp_encryption, default_from_email,
                              default_from_name, default_reply_to, (smtp_pass IS NOT NULL AND smtp_pass <> '') AS has_password
                         FROM email_settings WHERE setting_id = 1");
        $row = $r ? db_fetch($r) : null;
        if (!$row) { return null; }
        $row['smtp_port']    = (int) $row['smtp_port'];
        $row['has_password'] = (bool) $row['has_password'];
        return $row;
    }
}

if (($action ?? null) == 'apiGetEmailSettings') {
    if (require_api_scope('email:admin')) {
        $data['email_settings'] = email_settings_public();
        $_SESSION['success'] = 'OK';
    }
}

if (($action ?? null) == 'apiSetEmailSettings') {
    if (require_api_scope('email:admin')) {
        $errs  = [];
        $host  = trim((string) ($_POST['smtp_host'] ?? ''));
        $port  = (int) ($_POST['smtp_port'] ?? 587);
        $user  = trim((string) ($_POST['smtp_user'] ?? ''));
        $pass  = (string) ($_POST['smtp_pass'] ?? '');
        $enc   = (string) ($_POST['smtp_encryption'] ?? 'tls');
        $from  = strtolower(trim((string) ($_POST['from_email'] ?? '')));
        $name  = mb_substr(trim((string) ($_POST['from_name'] ?? '')), 0, 120);
        $reply = strtolower(trim((string) ($_POST['reply_to'] ?? '')));

        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) { $errs[] = 'smtp_host must be a host name.'; }
        if ($port < 1 || $port > 65535) { $errs[] = 'smtp_port must be 1-65535.'; }
        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) { $errs[] = 'smtp_encryption must be tls, ssl or none.'; }
        if ($from !== '' && !valid_email($from)) { $errs[] = 'from_email is not a valid address.'; }
        if ($reply !== '' && !valid_email($reply)) { $errs[] = 'reply_to is not a valid address.'; }
        $sealed = null;
        if ($pass !== '') {
            $sealed = wn_secret_seal($pass);
            if ($sealed === false) { $errs[] = 'Could not seal the password on this host.'; }
        }
        if (!db_fetch(db_query("SELECT setting_id FROM email_settings WHERE setting_id = 1"))) {
            $errs[] = 'email_settings row 1 is missing (core migrations not run).';
        }

        if ($errs) {
            $_SESSION['error'] = implode(' ', $errs);
        } else {
            $set = "smtp_host = '" . sanitize($host, SQL) . "', smtp_port = '" . $port . "',
                    smtp_user = '" . sanitize($user, SQL) . "', smtp_encryption = '" . sanitize($enc, SQL) . "',
                    updated = NOW()";
            if ($from !== '')  { $set .= ", default_from_email = '" . sanitize($from, SQL) . "'"; }
            if ($name !== '')  { $set .= ", default_from_name = '" . sanitize($name, SQL) . "'"; }
            if ($reply !== '') { $set .= ", default_reply_to = '" . sanitize($reply, SQL) . "'"; }
            if ($sealed !== null) { $set .= ", smtp_pass = '" . sanitize($sealed, SQL) . "'"; }
            if (!db_query("UPDATE email_settings SET $set WHERE setting_id = 1")) {
                $_SESSION['error'] = 'Could not save the email settings.';
            } else {
                if ($from !== '') {
                    $s_from = sanitize($from, SQL);
                    $s_name = sanitize($name, SQL);
                    db_query("UPDATE email_allowed_sender SET is_default = 0");
                    $have = db_fetch(db_query("SELECT sender_id FROM email_allowed_sender WHERE LOWER(email_address) = '$s_from' LIMIT 1"));
                    if ($have) {
                        db_query("UPDATE email_allowed_sender SET is_default = 1" . ($name !== '' ? ", display_name = '$s_name'" : '')
                               . " WHERE sender_id = '" . (int) $have['sender_id'] . "'");
                    } else {
                        db_query("INSERT INTO email_allowed_sender (email_address, display_name, is_default, created)
                                  VALUES ('$s_from', '$s_name', 1, NOW())");
                    }
                }
                if (function_exists('clear_email_settings_cache')) { clear_email_settings_cache(); }
                $data['email_settings'] = email_settings_public();
                $_SESSION['success'] = 'Email settings saved.';
            }
        }
    }
}

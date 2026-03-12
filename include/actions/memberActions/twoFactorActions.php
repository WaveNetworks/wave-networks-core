<?php
/**
 * Two-Factor Authentication Actions
 * Actions: enable2FA, disable2FA, verify2FASetup
 */

// ─── ENABLE 2FA (generate secret) ───────────────────────────────────────────

if (($action ?? null) == 'enable2FA') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        $secret = totp_generate_secret();
        $user_id = (int)$_SESSION['user_id'];

        db_query("UPDATE user SET totp_secret = '$secret' WHERE user_id = '$user_id'");

        $qr = totp_qr_code($_SESSION['email'], $secret);

        $_SESSION['success'] = '2FA secret generated. Scan the QR code with your authenticator app.';
        $data['secret'] = $secret;
        $data['qr']     = $qr;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── VERIFY 2FA SETUP (confirm code, then enable) ───────────────────────────

if (($action ?? null) == 'verify2FASetup') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $code = trim($_POST['totp_code'] ?? '');
    if (!$code) { $errs['code'] = 'Authentication code required.'; }

    if (count($errs) <= 0) {
        $user_id = (int)$_SESSION['user_id'];
        $user = get_user($user_id);

        if (!$user || !$user['totp_secret']) {
            $errs['setup'] = '2FA secret not found. Please start setup again.';
        } elseif (!totp_verify($user['totp_secret'], $code)) {
            $errs['code'] = 'Invalid code. Please try again.';
        }
    }

    if (count($errs) <= 0) {
        db_query("UPDATE user SET totp_enabled = 1 WHERE user_id = '$user_id'");
        $_SESSION['success'] = 'Two-factor authentication enabled successfully.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── DISABLE 2FA ─────────────────────────────────────────────────────────────

if (($action ?? null) == 'disable2FA') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $password = $_POST['password'] ?? '';
    if (!$password) { $errs['password'] = 'Enter your password to confirm.'; }

    if (count($errs) <= 0) {
        $user_id = (int)$_SESSION['user_id'];
        $user = get_user($user_id);

        if (!$user || !verify_password($password, $user['password'])) {
            $errs['password'] = 'Incorrect password.';
        }
    }

    if (count($errs) <= 0) {
        db_query("UPDATE user SET totp_enabled = 0, totp_secret = NULL WHERE user_id = '$user_id'");
        $_SESSION['success'] = 'Two-factor authentication disabled.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

<?php
/**
 * mobileAuthActions.php
 * Device-token login/logout for bundled mobile clients (child-app spec 05).
 *
 * These are the ONLY actions a mobile client can call before it is authenticated.
 * Everything after login goes through the app's normal actions and views, because a
 * device token produces an ordinary session (see mobileAuthFunctions.php).
 *
 * The checks below deliberately mirror loginActions.php exactly — password verify,
 * legacy rehash, confirmed-account, 2FA, login history. A weaker mobile login would be
 * a hole in every app on the host, so the rule is: if the web login rejects it, this
 * rejects it too.
 */

// ─── DEVICE LOGIN ────────────────────────────────────────────────────────────
// Exchanges credentials for a long-lived device token. The token IS an api_key row —
// core's remember-me credential — so it appears in the user's device list and is
// revoked by the same "sign out this device" button as any other session.

if (($_POST['action'] ?? '') == 'deviceLogin') {
    $errs = array();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $totp     = trim($_POST['totp'] ?? '');

    if (!$email)    { $errs['email']    = 'Email is required.'; }
    if (!$password) { $errs['password'] = 'Password is required.'; }

    $user = false;
    if (count($errs) <= 0) {
        $user = get_user_by_email($email);

        if (!$user) {
            $errs['auth'] = 'Invalid email or password.';
        } elseif (!verify_password($password, $user['password'] ?? '')) {
            // Same legacy-password path the web login takes, so migrated users can
            // sign in on mobile without being told their password is wrong.
            if (empty($user['password']) && function_exists('attempt_legacy_login')) {
                $legacy_user = attempt_legacy_login($email, $password);
                if ($legacy_user) {
                    $user = $legacy_user;
                } else {
                    $errs['auth'] = 'Invalid email or password.';
                }
            } else {
                $errs['auth'] = 'Invalid email or password.';
            }
        }
    }

    if (count($errs) <= 0 && !empty($user['password']) && password_needs_upgrade($user['password'])) {
        $new_hash = hash_password($password);
        $uid_h    = (int)$user['user_id'];
        db_query("UPDATE user SET password = '" . sanitize($new_hash, SQL) . "' WHERE user_id = '$uid_h'");
    }

    if (count($errs) <= 0 && !$user['is_confirmed']) {
        $errs['auth'] = 'Please confirm your email address before logging in.';
    }

    // 2FA. The web flow parks this in a session and redirects to a page; a bundled
    // client has neither, so the code comes in on the same request. When it is absent
    // we say so explicitly — that is the signal for the app to show its 2FA field,
    // and it is NOT an error the user did anything wrong.
    if (count($errs) <= 0 && !empty($user['totp_enabled'])) {
        if ($totp === '') {
            if (function_exists('record_login')) { record_login($user['user_id'], '2fa', 'failed'); }
            $data['totp_required'] = true;
            $_SESSION['error'] = 'Enter your authentication code.';
        } elseif (!totp_verify($user['totp_secret'], $totp)) {
            if (function_exists('record_login')) { record_login($user['user_id'], '2fa', 'failed'); }
            $errs['auth'] = 'Invalid authentication code.';
        }
    }

    if (count($errs) <= 0 && empty($data['totp_required'])) {
        $token = wn_issue_device_token($user['user_id']);

        if (function_exists('record_login')) {
            record_login($user['user_id'], !empty($user['totp_enabled']) ? '2fa' : 'password', 'success');
        }

        // Authenticate THIS request too, so the client can read its own profile from
        // the same response rather than making a second round trip on a cold start.
        load_user_session($user);

        $data['token']   = $token;
        $data['user_id'] = (int)$user['user_id'];
        $data['email']   = $user['email'];
        $data['name']    = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

        // Re-consent is a real gate, not a warning: the app must show it before the
        // user goes any further, exactly as the web redirect does.
        if (function_exists('check_reconsent_needed')) {
            $reconsent = check_reconsent_needed($user['user_id']);
            if (!empty($reconsent)) { $data['reconsent_needed'] = $reconsent; }
        }

        $_SESSION['success'] = 'Signed in.';
    } elseif (count($errs) > 0) {
        if (function_exists('record_login') && !empty($user['user_id'])) {
            record_login($user['user_id'], 'password', 'failed');
        }
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── DEVICE LOGOUT ───────────────────────────────────────────────────────────
// Revokes the token this request arrived with. Deleting the api_key row is precisely
// what "sign out this device" does in the profile UI — one revocation path, not two.

if (($_POST['action'] ?? '') == 'deviceLogout') {
    $token = function_exists('wn_device_token') ? wn_device_token() : '';

    if ($token === '') {
        $_SESSION['error'] = 'Login required.';
    } else {
        wn_revoke_device_token($token);
        $_SESSION['success'] = 'Signed out.';
    }
}

// ─── WHO AM I ────────────────────────────────────────────────────────────────
// Lets a cold-starting app decide between its dashboard and its login screen without
// guessing from a cached token that may have been revoked from another device.

if (($_POST['action'] ?? '') == 'deviceMe') {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['error'] = 'Login required.';
    } else {
        $data['user_id'] = (int)$_SESSION['user_id'];
        $data['email']   = $_SESSION['email'] ?? '';
        $data['name']    = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $data['is_admin'] = !empty($_SESSION['is_admin']);
    }
}

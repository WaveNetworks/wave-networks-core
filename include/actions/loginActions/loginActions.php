<?php
/**
 * Login Actions
 * Actions: login, logout, register, forgotPassword, resetPassword, confirmAccount, registerInvite
 */

// ─── LOGIN ───────────────────────────────────────────────────────────────────

if (($action ?? null) == 'login') {
    $errs = array();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = $_POST['remember_me'] ?? '';

    if (!$email)    { $errs['email'] = 'Email is required.'; }
    if (!$password) { $errs['password'] = 'Password is required.'; }

    if (count($errs) <= 0) {
        // reCAPTCHA check
        if (recaptcha_enabled() && !recaptcha_verify($_POST['g-recaptcha-response'] ?? '')) {
            $errs['captcha'] = 'Please complete the reCAPTCHA.';
        }
    }

    if (count($errs) <= 0) {
        $user = get_user_by_email($email);

        if (!$user) {
            $errs['auth'] = 'Invalid email or password.';
        } elseif (!verify_password($password, $user['password'] ?? '')) {
            // Password check failed — try legacy password rehash for migrated users
            if (empty($user['password']) && function_exists('attempt_legacy_login')) {
                $legacy_user = attempt_legacy_login($email, $password);
                if ($legacy_user) {
                    // Legacy password verified and rehashed — reload user with new hash
                    $user = $legacy_user;
                } else {
                    $errs['auth'] = 'Invalid email or password.';
                }
            } else {
                $errs['auth'] = 'Invalid email or password.';
            }
        }

        // Rehash if PHP defaults have changed (cost upgrade, algorithm switch)
        if (count($errs) <= 0 && !empty($user['password']) && password_needs_upgrade($user['password'])) {
            $new_hash = hash_password($password);
            $uid = (int)$user['user_id'];
            db_query("UPDATE user SET password = '" . sanitize($new_hash, SQL) . "' WHERE user_id = '$uid'");
        }

        if (count($errs) <= 0 && !$user['is_confirmed']) {
            $errs['auth'] = 'Please confirm your email address before logging in.';
        }
    }

    if (count($errs) <= 0) {
        // Check for 2FA
        if ($user['totp_enabled']) {
            $_SESSION['2fa_pending'] = true;
            $_SESSION['2fa_user_id'] = $user['user_id'];
            header('Location: 2fa.php');
            exit;
        }

        // Login successful
        load_user_session($user);

        // Associate device with this user (device was created on page load)
        if (function_exists('get_or_create_device')) {
            get_or_create_device();
        }

        // Record login history
        if (function_exists('record_login')) {
            record_login($user['user_id'], 'password', 'success');
        }

        // Remember me � reuse existing device from tracking cookie
        if ($remember === 'yes') {
            $device_id = $_SESSION['device_id'] ?? null;
            if (!$device_id) {
                $cookie_id = generateHashCode(64);
                $device_id = register_device($cookie_id, $user['user_id']);
            }
            $api_key = create_api_key($user['user_id'], $device_id, 'yes');
            setcookie('wn_auto_login', $api_key, time() + (86400 * 30), '/', '', false, true);
        }

        // Check if user needs to re-consent to updated policies
        if (function_exists('check_reconsent_needed')) {
            $reconsent = check_reconsent_needed($user['user_id']);
            if (!empty($reconsent)) {
                $_SESSION['reconsent_needed'] = $reconsent;
                header('Location: consent.php');
                exit;
            }
        }

        $_SESSION['success'] = 'Welcome back!';
        header('Location: ../app/');
        exit;
    } else {
        // Record failed login attempt
        if (function_exists('record_login') && !empty($user['user_id'])) {
            record_login($user['user_id'], 'password', 'failed');
        }
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── LOGOUT ──────────────────────────────────────────────────────────────────

if (($action ?? null) == 'logout') {
    logout();
    header('Location: ../auth/login.php');
    exit;
}

// ─── REGISTER ────────────────────────────────────────────────────────────────

if (($action ?? null) == 'register') {
    $errs = array();

    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');

    // Check registration mode
    $settings = db_fetch(db_query("SELECT registration_mode FROM auth_settings WHERE setting_id = 1"));
    $mode = $settings['registration_mode'] ?? 'open';

    if ($mode === 'closed') {
        $errs['mode'] = 'Registration is currently closed.';
    }

    if (!valid_email($email))       { $errs['email'] = 'Valid email is required.'; }
    if (!valid_password($password))  { $errs['password'] = 'Password must be at least 8 characters.'; }
    if ($password !== $confirm)      { $errs['confirm'] = 'Passwords do not match.'; }
    if (empty($_POST['agree_terms'])) { $errs['terms'] = 'You must agree to the Terms of Service and Privacy Policy.'; }

    if (count($errs) <= 0) {
        if (recaptcha_enabled() && !recaptcha_verify($_POST['g-recaptcha-response'] ?? '')) {
            $errs['captcha'] = 'Please complete the reCAPTCHA.';
        }
    }

    if (count($errs) <= 0) {
        $existing = get_user_by_email($email);
        if ($existing) {
            $errs['email'] = 'An account with this email already exists.';
        }
    }

    if (count($errs) <= 0) {
        $hashed   = hash_password($password);
        $shard_id = get_least_loaded_shard();
        $confirmHash = generateHashCode(100);

        $needs_confirm = ($mode === 'confirm') ? 0 : 1;

        $r = db_query("INSERT INTO user (email, password, shard_id, is_confirmed, confirm_hash, created_date)
                        VALUES ('" . sanitize($email, SQL) . "', '$hashed', '$shard_id', '$needs_confirm', '$confirmHash', NOW())");

        if ($r) {
            $new_id = db_insert_id();

            // Create profile on shard
            prime_shard($shard_id);
            db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                            VALUES ('$new_id', '" . sanitize($first_name, SQL) . "', '" . sanitize($last_name, SQL) . "', NOW())");

            // Create homedir
            $_SESSION['shard_id'] = $shard_id;
            create_home_dir_id($new_id);
            unset($_SESSION['shard_id']);

            // Record consent for Terms of Service and Privacy Policy
            if (function_exists('record_consent')) {
                $tos_ver = get_latest_consent_version('terms_of_service');
                $pp_ver  = get_latest_consent_version('privacy_policy');
                record_consent($new_id, 'terms_of_service', 'granted', $tos_ver ? (int)$tos_ver['version_id'] : null);
                record_consent($new_id, 'privacy_policy', 'granted', $pp_ver ? (int)$pp_ver['version_id'] : null);
            }

            // Send confirmation email if needed
            if ($mode === 'confirm') {
                send_confirmation_email($email, $confirmHash);
                $_SESSION['success'] = 'Account created! Please check your email to confirm.';
            } else {
                $_SESSION['success'] = 'Account created! You can now log in.';
            }

            header('Location: login.php');
            exit;
        } else {
            $errs['db'] = db_error();
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── FORGOT PASSWORD ────────────────────────────────────────────────────────

if (($action ?? null) == 'forgotPassword') {
    $errs = array();
    $email = trim($_POST['email'] ?? '');

    if (!valid_email($email)) { $errs['email'] = 'Valid email is required.'; }

    if (count($errs) <= 0) {
        $user = get_user_by_email($email);

        // Always show success message (prevent email enumeration)
        if ($user) {
            $token = generateHashCode(100);
            db_query("INSERT INTO forgot (user_id, forgot_token, created) VALUES ('{$user['user_id']}', '$token', NOW())");
            send_reset_email($email, $token);
        }

        $_SESSION['success'] = 'If an account exists with that email, a reset link has been sent.';
        header('Location: login.php');
        exit;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── RESET PASSWORD ─────────────────────────────────────────────────────────

if (($action ?? null) == 'resetPassword') {
    $errs = array();

    $token    = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$token)                     { $errs['token'] = 'Invalid reset token.'; }
    if (!valid_password($password))  { $errs['password'] = 'Password must be at least 8 characters.'; }
    if ($password !== $confirm)      { $errs['confirm'] = 'Passwords do not match.'; }

    if (count($errs) <= 0) {
        $safe_token = sanitize($token, SQL);
        $r = db_query("SELECT * FROM forgot WHERE forgot_token = '$safe_token' AND used = 0 AND created > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $forgot = db_fetch($r);

        if (!$forgot) {
            $errs['token'] = 'Invalid or expired reset token.';
        }
    }

    if (count($errs) <= 0) {
        $hashed = hash_password($password);
        db_query("UPDATE user SET password = '$hashed' WHERE user_id = '{$forgot['user_id']}'");
        db_query("UPDATE forgot SET used = 1 WHERE forgot_id = '{$forgot['forgot_id']}'");

        $_SESSION['success'] = 'Password reset successfully. You can now log in.';
        header('Location: login.php');
        exit;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── CONFIRM ACCOUNT ─────────────────────────────────────────────────────────

if (($action ?? null) == 'confirmAccount') {
    $hash = $_GET['hash'] ?? $_POST['hash'] ?? '';

    if ($hash) {
        $safe_hash = sanitize($hash, SQL);
        $r = db_query("SELECT user_id FROM user WHERE confirm_hash = '$safe_hash' AND is_confirmed = 0");
        $user = db_fetch($r);

        if ($user) {
            db_query("UPDATE user SET is_confirmed = 1, confirm_hash = NULL WHERE user_id = '{$user['user_id']}'");
            $_SESSION['success'] = 'Email confirmed! You can now log in.';
        } else {
            $_SESSION['error'] = 'Invalid or already used confirmation link.';
        }
    } else {
        $_SESSION['error'] = 'Missing confirmation token.';
    }
}

// ─── REGISTER VIA INVITE ────────────────────────────────────────────────────

if (($action ?? null) == 'registerInvite') {
    $errs = array();

    $token      = $_POST['invite_token'] ?? '';
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');

    if (!$token) { $errs['token'] = 'Invalid invite token.'; }
    if (!valid_email($email))       { $errs['email'] = 'Valid email is required.'; }
    if (!valid_password($password))  { $errs['password'] = 'Password must be at least 8 characters.'; }
    if ($password !== $confirm)      { $errs['confirm'] = 'Passwords do not match.'; }

    if (count($errs) <= 0) {
        $safe_token = sanitize($token, SQL);
        $r = db_query("SELECT * FROM invite WHERE invite_token = '$safe_token' AND used = 0");
        $invite = db_fetch($r);

        if (!$invite) {
            $errs['token'] = 'Invalid or already used invite token.';
        } elseif ($invite['email'] && strtolower($invite['email']) !== strtolower($email)) {
            $errs['email'] = 'Email does not match the invite.';
        }
    }

    if (count($errs) <= 0) {
        $existing = get_user_by_email($email);
        if ($existing) {
            $errs['email'] = 'An account with this email already exists.';
        }
    }

    if (count($errs) <= 0) {
        $hashed   = hash_password($password);
        $shard_id = get_least_loaded_shard();

        $r = db_query("INSERT INTO user (email, password, shard_id, is_confirmed, created_date)
                        VALUES ('" . sanitize($email, SQL) . "', '$hashed', '$shard_id', 1, NOW())");

        if ($r) {
            $new_id = db_insert_id();

            prime_shard($shard_id);
            db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                            VALUES ('$new_id', '" . sanitize($first_name, SQL) . "', '" . sanitize($last_name, SQL) . "', NOW())");

            $_SESSION['shard_id'] = $shard_id;
            create_home_dir_id($new_id);
            unset($_SESSION['shard_id']);

            // Mark invite as used
            db_query("UPDATE invite SET used = 1 WHERE invite_id = '{$invite['invite_id']}'");

            $_SESSION['success'] = 'Account created! You can now log in.';
            header('Location: login.php');
            exit;
        } else {
            $errs['db'] = db_error();
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── VERIFY 2FA ──────────────────────────────────────────────────────────────

if (($action ?? null) == 'verify2FA') {
    $errs = array();
    $code = trim($_POST['totp_code'] ?? '');

    if (!$_SESSION['2fa_pending'] || !$_SESSION['2fa_user_id']) {
        $errs['auth'] = 'No pending 2FA verification.';
    }

    if (!$code) { $errs['code'] = 'Please enter your authentication code.'; }

    if (count($errs) <= 0) {
        $user = get_user($_SESSION['2fa_user_id']);
        if (!$user || !totp_verify($user['totp_secret'], $code)) {
            $errs['code'] = 'Invalid authentication code.';
        }
    }

    if (count($errs) <= 0) {
        unset($_SESSION['2fa_pending'], $_SESSION['2fa_user_id']);
        load_user_session($user);
        // Record login history
        if (function_exists('record_login')) {
            record_login($user['user_id'], '2fa', 'success');
        }

        // Check re-consent
        if (function_exists('check_reconsent_needed')) {
            $reconsent = check_reconsent_needed($user['user_id']);
            if (!empty($reconsent)) {
                $_SESSION['reconsent_needed'] = $reconsent;
                header('Location: consent.php');
                exit;
            }
        }
        $_SESSION['success'] = 'Welcome back!';
        header('Location: ../app/');
        exit;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

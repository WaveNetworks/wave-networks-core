<?php
/**
 * User Compliance Actions (admin-only)
 * Actions: adminResetPassword, adminRevokeSession, adminRevokeAllSessions, adminCancelDeletion
 */

// ─── ADMIN RESET PASSWORD ───────────────────────────────────────────────────

if (($action ?? null) == 'adminResetPassword') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id      = (int)($_POST['user_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    $notify_user  = !empty($_POST['notify_user']);

    if (!$user_id)                   { $errs['user_id'] = 'User ID required.'; }
    if (!valid_password($new_password)) { $errs['password'] = 'Password must be at least 8 characters.'; }

    if (count($errs) <= 0) {
        $user = get_user($user_id);
        if (!$user) { $errs['user_id'] = 'User not found.'; }
    }

    if (count($errs) <= 0) {
        $hashed = hash_password($new_password);
        $r = db_query("UPDATE user SET password = '" . sanitize($hashed, SQL) . "' WHERE user_id = '$user_id'");

        if ($r) {
            // Send notification email if requested
            if ($notify_user && function_exists('queue_email')) {
                $profile = get_user_profile($user_id, $user['shard_id']);
                $name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: 'User';

                $body = "Hello $name,\n\n";
                $body .= "Your password has been reset by an administrator.\n\n";
                $body .= "If you did not request this change, please contact support immediately.\n\n";
                $body .= "For security, we recommend changing your password after logging in.\n\n";
                $body .= "Regards,\nThe Admin Team";

                queue_email($user['email'], 'Your password has been reset', $body);
            }

            $_SESSION['success'] = 'Password reset successfully.' . ($notify_user ? ' Notification email queued.' : '');
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── ADMIN REVOKE SESSION ───────────────────────────────────────────────────

if (($action ?? null) == 'adminRevokeSession') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id   = (int)($_POST['user_id'] ?? 0);
    $device_id = (int)($_POST['device_id'] ?? 0);

    if (!$user_id)   { $errs['user_id'] = 'User ID required.'; }
    if (!$device_id) { $errs['device_id'] = 'Device ID required.'; }

    if (count($errs) <= 0) {
        if (function_exists('revoke_device')) {
            revoke_device($device_id, $user_id);
            $_SESSION['success'] = 'Session revoked.';
        } else {
            $errs['func'] = 'Device management not available.';
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── ADMIN REVOKE ALL SESSIONS ──────────────────────────────────────────────

if (($action ?? null) == 'adminRevokeAllSessions') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) { $errs['user_id'] = 'User ID required.'; }

    if (count($errs) <= 0) {
        if (function_exists('revoke_all_other_devices')) {
            // Revoke ALL devices for this user (pass null so none are excluded)
            revoke_all_other_devices($user_id, null);
            $_SESSION['success'] = 'All sessions revoked for this user.';
        } else {
            $errs['func'] = 'Device management not available.';
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── ADMIN CANCEL DELETION REQUEST ──────────────────────────────────────────

if (($action ?? null) == 'adminCancelDeletion') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) { $errs['user_id'] = 'User ID required.'; }

    if (count($errs) <= 0) {
        if (function_exists('cancel_account_deletion')) {
            cancel_account_deletion($user_id);
            $_SESSION['success'] = 'Account deletion request cancelled.';
        } else {
            $errs['func'] = 'GDPR functions not available.';
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

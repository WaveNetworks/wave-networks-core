<?php
/**
 * Account Security Actions
 * Actions: changeOwnPassword
 */

// ─── CHANGE OWN PASSWORD ────────────────────────────────────────────────────

if (($action ?? null) == 'changeOwnPassword') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $current_password  = $_POST['current_password'] ?? '';
    $new_password      = $_POST['new_password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';

    if (!$current_password) { $errs['current_password'] = 'Current password is required.'; }
    if (!$new_password)     { $errs['new_password'] = 'New password is required.'; }
    if (strlen($new_password) < 8) { $errs['new_password'] = 'New password must be at least 8 characters.'; }
    if ($new_password !== $confirm_password) { $errs['confirm_password'] = 'Passwords do not match.'; }

    if (count($errs) <= 0) {
        $user_id = (int)$_SESSION['user_id'];
        $user = get_user($user_id);

        if (!$user || !verify_password($current_password, $user['password'])) {
            $errs['current_password'] = 'Current password is incorrect.';
        }
    }

    if (count($errs) <= 0) {
        $hashed = hash_password($new_password);
        $s_hashed = sanitize($hashed, SQL);
        db_query("UPDATE user SET password = '$s_hashed' WHERE user_id = '$user_id'");
        $_SESSION['success'] = 'Password updated successfully.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

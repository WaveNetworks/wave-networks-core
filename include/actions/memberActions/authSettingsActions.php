<?php
/**
 * Auth Settings Actions
 * Actions: setRegistrationMode
 */

if (($action ?? null) == 'setRegistrationMode') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $mode = $_POST['registration_mode'] ?? '';
    if (!in_set($mode, ['open', 'closed', 'confirm', 'invite'])) {
        $errs['mode'] = 'Invalid registration mode.';
    }

    if (count($errs) <= 0) {
        $safe_mode = sanitize($mode, SQL);
        db_query("UPDATE auth_settings SET registration_mode = '$safe_mode' WHERE setting_id = 1");
        $_SESSION['success'] = 'Registration mode updated to: ' . h($mode) . '.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

<?php
/**
 * updateCheckActions.php
 * AJAX action to check for Wave Networks updates.
 */

if ($_POST['action'] == 'checkForUpdates') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $updateInfo = check_for_updates(true); // force fresh check
        if ($updateInfo) {
            $data['updates'] = $updateInfo;
            $_SESSION['success'] = 'Update check complete.';
        } else {
            $_SESSION['warning'] = 'Could not reach the update server. Try again later.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

<?php
/**
 * Passkey Actions (task #2661; rules in include/common/passkeyFunctions.php)
 * Actions: passkeyEnrolOptions, passkeyEnrolFinish, passkeyRename, passkeyRemove
 * The two enrol steps are AJAX (apiPost from assets/js/passkey.js): the device ceremony sits
 * between them. Rename and remove are plain form posts on the account security page.
 */

if (($action ?? null) == 'passkeyEnrolOptions') {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (count($errs) <= 0) {
        $r = wn_passkey_enrol_options(get_user((int)$_SESSION['user_id']));
        if (!empty($r['error'])) { $errs['passkey'] = $r['error']; }
    }
    if (count($errs) <= 0) {
        $data['options'] = $r['options'];
        $data['default_name'] = wn_passkey_default_name();
        $_SESSION['success'] = 'Confirm on your device.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($action ?? null) == 'passkeyEnrolFinish') {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['client_data']) || empty($_POST['attestation'])) { $errs['passkey'] = 'Your device sent no passkey. Try again.'; }
    if (count($errs) <= 0) {
        $r = wn_passkey_enrol_finish(get_user((int)$_SESSION['user_id']), $_POST['client_data'], $_POST['attestation'], $_POST['name'] ?? '');
        if (!empty($r['error'])) { $errs['passkey'] = $r['error']; }
    }
    if (count($errs) <= 0) {
        $data['passkey_id'] = $r['ok'];
        $_SESSION['success'] = 'Passkey added. Next time, sign in with it instead of your password.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($action ?? null) == 'passkeyRename') {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['passkey_id'])) { $errs['passkey'] = 'Which passkey?'; }
    if (trim($_POST['name'] ?? '') === '') { $errs['name'] = 'Give the passkey a name.'; }
    if (count($errs) <= 0 && !wn_passkey_rename((int)$_SESSION['user_id'], (int)$_POST['passkey_id'], $_POST['name'])) {
        $errs['passkey'] = 'That passkey was not found, or the name did not change.';
    }
    if (count($errs) <= 0) {
        $_SESSION['success'] = 'Passkey renamed.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($action ?? null) == 'passkeyRemove') {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['passkey_id'])) { $errs['passkey'] = 'Which passkey?'; }
    if (count($errs) <= 0) {
        // Same proof as turning off the authenticator app: the password, when the account has one.
        $user = get_user((int)$_SESSION['user_id']);
        if (!empty($user['password']) && !verify_password($_POST['password'] ?? '', $user['password'])) {
            $errs['password'] = 'Incorrect password.';
        }
    }
    if (count($errs) <= 0) {
        $name = wn_passkey_remove((int)$_SESSION['user_id'], (int)$_POST['passkey_id']);
        if ($name === '') { $errs['passkey'] = 'That passkey was not found.'; }
    }
    if (count($errs) <= 0) {
        $_SESSION['success'] = 'Passkey "' . $name . '" removed. It no longer signs in to this account.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

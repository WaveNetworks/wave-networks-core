<?php
/**
 * 2FA push approve — the browser half (task #2662). On auth/2fa.php after a password:
 *   start2FAPush — push "Signing in on <browser>?" to the account's phones and show the
 *                  number to pick there. snippets/twofa_push.php waits and collects.
 * Rules: include/common/twofaPushFunctions.php.
 */

if (($_POST['action'] ?? '') == 'start2FAPush' && function_exists('wn_2fa_push_enabled') && wn_2fa_push_enabled()) {
    $errs = array();
    if (empty($_SESSION['2fa_pending']) || empty($_SESSION['2fa_user_id'])) {
        $errs['session'] = 'Your sign-in expired. Please sign in again.';
    }
    if (count($errs) <= 0) {
        $r = wn_2fa_push_start(get_user($_SESSION['2fa_user_id']));
        if (!empty($r['error'])) { $errs['push'] = $r['error']; }
    }
    if (count($errs) <= 0) {
        $_SESSION['success'] = 'We sent a sign-in request to your phone.';
        header('Location: 2fa.php?push=1');
        exit;
    }
    $_SESSION['error'] = implode('<br>', $errs);
}

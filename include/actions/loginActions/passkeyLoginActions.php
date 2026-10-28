<?php
/**
 * Passkey sign-in (task #2661; rules in include/common/passkeyFunctions.php)
 * Actions: passkeySignIn — a plain form post from snippets/passkey_signin.php.
 *   On auth/2fa.php (a password already checked): the second step, login_method '2fa_passkey'.
 *   On the sign-in page: password-less, login_method 'passkey'. User verification is required,
 *   so the passkey is both factors and the authenticator code is not asked for too.
 */

if (($action ?? null) == 'passkeySignIn') {
    $errs = array();
    $second = !empty($_SESSION['2fa_pending']) && !empty($_SESSION['2fa_user_id']);
    $r = wn_passkey_signin_verify($_POST, $second ? (int)$_SESSION['2fa_user_id'] : 0);
    if (!empty($r['error'])) { $errs['passkey'] = $r['error']; }

    if (count($errs) <= 0) {
        $user = get_user($r['user_id']);
        if (!$user) { $errs['passkey'] = 'This passkey is not saved on any account here.'; }
    }

    if (count($errs) <= 0) {
        if ($second) {
            wn_2fa_finish($user, '2fa_passkey');
        }
        wn_login_finish($user, 'passkey', false);
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

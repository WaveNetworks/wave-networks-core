<?php
/**
 * Support reset of a user's sign-in factors (admin-only, task #2663, part 5 of #2658)
 * Actions: adminResetFactor — factor=totp (turn off the authenticator app) | passkey (remove one)
 * Shown on views/user_edit.php › Sign-in factors. A reason is required; every reset is logged
 * (user_action_log: user_id/factor/passkey_id allowlisted in actionLogPolicy.php) and the
 * person gets a notice email + security notification naming it as a support reset.
 */

if (($action ?? null) == 'adminResetFactor') {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))           { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $factor  = (string)($_POST['factor'] ?? '');
    $reason  = trim((string)($_POST['reason'] ?? ''));
    if (!$user_id) { $errs['user_id'] = 'User ID required.'; }
    if (!in_array($factor, ['totp', 'passkey'], true)) { $errs['factor'] = 'Unknown sign-in factor.'; }
    if (mb_strlen($reason) < 8) { $errs['reason'] = 'Say why (8+ characters), e.g. "lost phone, identity checked by video call".'; }

    $user = null;
    if (count($errs) <= 0) {
        $user = get_user($user_id);
        if (!$user) { $errs['user_id'] = 'User not found.'; }
    }

    $what = '';
    if (count($errs) <= 0 && $factor === 'totp') {
        if (empty($user['totp_enabled']) && empty($user['totp_secret'])) {
            $errs['factor'] = 'This user has no authenticator app set up.';
        } else {
            db_query("UPDATE user SET totp_enabled = 0, totp_secret = NULL WHERE user_id = '$user_id'");
            $what = 'authenticator app';
        }
    }
    if (count($errs) <= 0 && $factor === 'passkey') {
        $name = function_exists('wn_passkey_remove') ? wn_passkey_remove($user_id, (int)($_POST['passkey_id'] ?? 0), 'support') : '';
        if ($name === '') { $errs['factor'] = 'That passkey was not found on this user.'; }
        else { $what = 'passkey "' . $name . '"'; }
    }

    if (count($errs) <= 0) {
        if ($factor === 'totp') { wn_factor_support_notice($user, $what); }   // wn_passkey_remove() sends its own
        error_log('adminResetFactor: admin ' . (int)$_SESSION['user_id'] . ' reset ' . $what . ' for user ' . $user_id . ' — ' . $reason);
        $_SESSION['success'] = 'Reset the ' . $what . ' for ' . $user['email'] . '. They were told by email and notification.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

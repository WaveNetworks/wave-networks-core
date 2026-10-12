<?php
/**
 * Login Approve Actions — the phone app's half of "Sign in with your phone" (task #2391)
 * Actions: loginApproveGet, loginApproveDecide
 * The approver is always the signed-in account of the calling session (the app's
 * device token), never a parameter. Logic: include/common/loginApproveFunctions.php.
 */

if (($_POST['action'] ?? '') == 'loginApproveGet' && wn_login_approve_enabled()) {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        $row = wn_login_approve_by_token($_POST['token'] ?? '');
        if (!$row) {
            $errs['token'] = 'This sign-in request has expired or was already answered. Start again on the computer.';
        } else {
            $d = wn_login_approve_describe($row);
            $data['request'] = $d + [
                'expires_in' => max(0, strtotime($row['expires'] . ' UTC') - time()),
                'email'      => $_SESSION['email'] ?? '',
            ];
            $_SESSION['success'] = 'ok';
        }
    }
    if (count($errs) > 0) { $_SESSION['error'] = implode('<br>', $errs); }
}

if (($_POST['action'] ?? '') == 'loginApproveDecide' && wn_login_approve_enabled()) {
    $errs = array();
    $decision = $_POST['decision'] ?? '';
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (!in_array($decision, ['approve', 'deny'], true)) { $errs['decision'] = 'Choose Approve or Not me.'; }

    if (count($errs) <= 0) {
        $err = wn_login_approve_decide($_POST['token'] ?? '', (int)$_SESSION['user_id'], $decision === 'approve');
        if ($err) { $errs['token'] = $err; }
    }

    if (count($errs) <= 0) {
        $data['decision'] = $decision;
        $_SESSION['success'] = $decision === 'approve'
            ? 'Approved. The computer is signing in now.'
            : 'Cancelled. That computer was not signed in.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

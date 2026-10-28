<?php
/**
 * 2FA push approve — the phone half (task #2662).
 *   twofaPushPending — the newest live request for the signed-in account (the app asks on
 *                      every open and when the push is tapped; push_id narrows it)
 *   twofaPushDecide  — push_id, decision approve|deny, number (approve needs the right one)
 * The approver is always the session's account, never a parameter.
 * Rules: include/common/twofaPushFunctions.php.
 */

if (($_POST['action'] ?? '') == 'twofaPushPending' && function_exists('wn_2fa_push_enabled') && wn_2fa_push_enabled()) {
    $errs = array();
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (count($errs) <= 0) {
        $row = wn_2fa_push_pending((int)$_SESSION['user_id'], (int)($_POST['push_id'] ?? 0));
        $data['request'] = $row ? wn_2fa_push_view($row) : null;
        $_SESSION['success'] = 'ok';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'twofaPushDecide' && function_exists('wn_2fa_push_enabled') && wn_2fa_push_enabled()) {
    $errs = array();
    $decision = $_POST['decision'] ?? '';
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (!in_array($decision, ['approve', 'deny'], true)) { $errs['decision'] = 'Pick the number or tap Not me.'; }
    if (empty($_POST['push_id'])) { $errs['push_id'] = 'Which request?'; }

    if (count($errs) <= 0) {
        $r = wn_2fa_push_decide((int)$_POST['push_id'], (int)$_SESSION['user_id'], $decision, (int)($_POST['number'] ?? 0));
        if (!empty($r['error'])) { $errs['push'] = $r['error']; }
    }
    if (count($errs) <= 0) {
        $data['status'] = $r['status'];
        if ($r['status'] === 'approved') {
            $_SESSION['success'] = 'Approved. The computer is signing in now.';
        } elseif ($decision === 'approve') {
            $_SESSION['warning'] = 'That was not the number on the screen, so the sign-in was blocked.';
            $_SESSION['success'] = 'Blocked.';
        } else {
            $_SESSION['success'] = 'Blocked. That sign-in did not happen. If it was not you, change your password.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

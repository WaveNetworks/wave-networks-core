<?php
/**
 * Session Actions
 * Actions: checkSession
 */

// ─── CHECK SESSION ──────────────────────────────────────────────────────────
// Lightweight heartbeat action — returns success if session is alive,
// or "Login required" if expired. Used by JS session heartbeat in bs-init.js.

if (($_POST['action'] ?? '') == 'checkSession') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        $_SESSION['success'] = 'ok';
        $data['session_valid'] = true;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

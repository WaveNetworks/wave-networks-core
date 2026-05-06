<?php
/**
 * Email Schedule Actions (admin only)
 * Actions: cancelScheduledEmail, cancelDripEnrollment
 *
 * Used by views/user_edit.php "Email schedule" support card and any other
 * place that needs to abort a queued onboarding send (unsubscribe links,
 * support tickets, etc.).
 */

if (($_POST['action'] ?? '') == 'cancelScheduledEmail') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $scheduled_id = (int)($_POST['scheduled_id'] ?? 0);
    if ($scheduled_id <= 0) { $errs['id'] = 'Invalid scheduled email ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("UPDATE scheduled_email
                         SET status = 'cancelled'
                       WHERE scheduled_id = '$scheduled_id'
                         AND status = 'pending'");
        if ($r) {
            $_SESSION['success'] = 'Scheduled email cancelled.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'cancelDripEnrollment') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $slug    = trim($_POST['campaign_slug'] ?? '');

    if ($user_id <= 0)  { $errs['user']     = 'User required.'; }
    if (empty($slug))   { $errs['campaign'] = 'Campaign slug required.'; }

    if (count($errs) <= 0) {
        $ok = cancel_drip_enrollment($user_id, $slug);
        if ($ok) {
            $_SESSION['success'] = 'Enrollment cancelled and pending sends aborted.';
        } else {
            $_SESSION['error'] = 'Could not cancel enrollment (not found?).';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

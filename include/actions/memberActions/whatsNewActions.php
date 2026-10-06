<?php
/**
 * What's new (authenticated users) — nokemo task #2316
 * Action: getWhatsNew -> entries[] (this app's published, user-facing changelog; newest first)
 * Read by assets/js/notifications.js, which every app's template already loads.
 */

if (($_POST['action'] ?? '') == 'getWhatsNew') {
    $errs = array();

    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        $data['entries'] = whats_new_entries(90, 30);
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

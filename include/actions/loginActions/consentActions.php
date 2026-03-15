<?php
/**
 * Consent Actions
 * Actions: acceptReconsent
 */

if (($action ?? null) == 'acceptReconsent') {
    $errs = array();
    $uid = $_SESSION['user_id'] ?? null;

    if (!$uid) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['accept_updated'])) { $errs['accept'] = 'You must accept the updated policies to continue.'; }

    if (count($errs) <= 0 && !empty($_SESSION['reconsent_needed'])) {
        foreach ($_SESSION['reconsent_needed'] as $type => $version) {
            record_consent($uid, $type, 'granted', (int)$version['version_id']);
        }

        unset($_SESSION['reconsent_needed']);
        $_SESSION['success'] = 'Thank you for accepting the updated policies.';
        header('Location: ../app/');
        exit;
    } else {
        if (empty($errs)) { $errs['general'] = 'No policies to accept.'; }
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

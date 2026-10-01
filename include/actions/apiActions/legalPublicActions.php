<?php
/**
 * apiActions/legalPublicActions.php — what a sign-up form shows before an account exists.
 *
 *   getLegalVersions — the Terms of Service and Privacy Policy versions in force for this
 *                      deployment's app: id, number, title and public URL. No key, no
 *                      session: published documents are public. The phone app's sign-up
 *                      (assets/mobile/js/login.js) shows them and posts the ids back as
 *                      legal_version_ids, so the account records exactly what was shown.
 * Helpers: include/common/legalFunctions.php.
 */

if (($_POST['action'] ?? '') == 'getLegalVersions') {
    $data['versions'] = function_exists('wn_legal_signup_versions') ? wn_legal_signup_versions() : [];
}

<?php
/**
 * credentialsApiActions.php — service-API surface for pasteable credentials.
 *
 * nokemo (monitoring paste-in) and the provisioning wizard use these:
 *   apiGetCredentialStatus (credentials:read)  — what the app needs + what's
 *                                                 still missing. NEVER values.
 *   apiSetCredential       (credentials:write) — paste one value in; validated
 *                                                 against the manifest, stored
 *                                                 outside the webroot.
 * See include/common/credentialsFunctions.php + docs/credentials-manifest.md.
 */

if (($action ?? null) == 'apiGetCredentialStatus') {
    if (require_api_scope('credentials:read')) {
        $st = credential_status();
        $data['credentials']   = $st['credentials'];
        $data['missing']       = $st['missing'];
        $data['missing_count'] = $st['missing_count'];
        $data['has_manifest']  = $st['has_manifest'];
        $_SESSION['success'] = 'OK';
    }
}

if (($action ?? null) == 'apiSetCredential') {
    if (require_api_scope('credentials:write')) {
        $key   = isset($_POST['key'])   ? trim((string)$_POST['key']) : '';
        $value = isset($_POST['value']) ? (string)$_POST['value']     : '';
        if ($key === '') {
            $_SESSION['error'] = 'key is required.';
        } else {
            list($ok, $err, $satisfied) = credential_set($key, $value);
            if ($ok) {
                credential_audit_log($key);
                $data['key']       = $key;
                $data['satisfied'] = $satisfied;
                $_SESSION['success'] = 'Credential saved.';
            } else {
                $_SESSION['error'] = $err;
            }
        }
    }
}

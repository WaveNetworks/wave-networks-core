<?php
/**
 * serviceApiKeyActions.php
 * Admin actions for managing service API keys.
 * Actions: createServiceApiKey, revokeServiceApiKey, getServiceApiKeys
 */

if (($_POST['action'] ?? '') == 'createServiceApiKey') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }
    if (empty($_POST['key_name'])) { $errs['name'] = 'Key name is required.'; }

    // Validate scopes
    $scopes = $_POST['scopes'] ?? [];
    if (!is_array($scopes)) { $scopes = []; }
    if (empty($scopes)) { $errs['scopes'] = 'At least one scope is required.'; }

    // Check scopes are valid
    $available = array_keys(get_available_scopes());
    foreach ($scopes as $s) {
        if (!in_array($s, $available)) {
            $errs['scopes'] = "Invalid scope: $s";
            break;
        }
    }

    if (count($errs) <= 0) {
        $result = create_service_api_key(
            $_POST['key_name'],
            $scopes,
            $_SESSION['user_id']
        );

        if ($result) {
            $_SESSION['success'] = 'API key created. Copy it now — it will not be shown again.';
            $data['full_key']       = $result['full_key'];
            $data['prefix']         = $result['prefix'];
            $data['service_key_id'] = $result['service_key_id'];
        } else {
            $_SESSION['error'] = 'Failed to create API key. ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'revokeServiceApiKey') {
    $errs = array();
    if (!$_SESSION['user_id'])          { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))             { $errs['role'] = 'Admin access required.'; }
    if (empty($_POST['service_key_id'])) { $errs['id'] = 'Key ID required.'; }

    if (count($errs) <= 0) {
        $r = revoke_service_api_key((int)$_POST['service_key_id'], $_SESSION['user_id']);
        if ($r) {
            $_SESSION['success'] = 'API key revoked.';
        } else {
            $_SESSION['error'] = 'Failed to revoke key (already revoked or not found).';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'getServiceApiKeys') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $data['keys']   = get_service_api_keys();
        $data['scopes'] = get_available_scopes();
        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

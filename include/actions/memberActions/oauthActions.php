<?php
/**
 * OAuth Provider Actions
 * Actions: saveOAuthProvider, toggleOAuthProvider
 */

// ─── SAVE OAUTH PROVIDER ────────────────────────────────────────────────────

if (($action ?? null) == 'saveOAuthProvider') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $provider_id   = (int)($_POST['provider_id'] ?? 0);
    $provider_name = trim($_POST['provider_name'] ?? '');
    $client_id     = trim($_POST['client_id'] ?? '');
    $client_secret = trim($_POST['client_secret'] ?? '');

    if (!$provider_name) { $errs['name'] = 'Provider name required.'; }
    if (!$client_id)     { $errs['client_id'] = 'Client ID required.'; }

    if (count($errs) <= 0) {
        $safe_name   = sanitize($provider_name, SQL);
        $safe_cid    = sanitize($client_id, SQL);
        $safe_secret = sanitize($client_secret, SQL);

        if ($provider_id) {
            db_query("UPDATE oauth_provider SET
                provider_name = '$safe_name',
                client_id = '$safe_cid',
                client_secret = '$safe_secret'
                WHERE provider_id = '$provider_id'");
        } else {
            db_query("INSERT INTO oauth_provider (provider_name, client_id, client_secret, is_enabled)
                      VALUES ('$safe_name', '$safe_cid', '$safe_secret', 0)");
        }

        $_SESSION['success'] = 'OAuth provider saved.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── TOGGLE OAUTH PROVIDER ──────────────────────────────────────────────────

if (($action ?? null) == 'toggleOAuthProvider') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $provider_id = (int)($_POST['provider_id'] ?? 0);
    if (!$provider_id) { $errs['id'] = 'Provider ID required.'; }

    if (count($errs) <= 0) {
        $r = db_query("SELECT is_enabled FROM oauth_provider WHERE provider_id = '$provider_id'");
        $row = db_fetch($r);

        if ($row) {
            $new_state = $row['is_enabled'] ? 0 : 1;
            db_query("UPDATE oauth_provider SET is_enabled = '$new_state' WHERE provider_id = '$provider_id'");
            $_SESSION['success'] = 'OAuth provider ' . ($new_state ? 'enabled' : 'disabled') . '.';
        } else {
            $_SESSION['error'] = 'Provider not found.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

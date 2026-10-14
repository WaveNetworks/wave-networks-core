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

    // Apple (task #2462): the secret is the Team ID + Key ID + .p8 key, kept together as sealed JSON.
    // Editing a saved Apple row with all three left blank keeps the stored key (write-only, like Client Secret).
    $apple_keep = $provider_name === 'apple' && $provider_id
        && trim($_POST['apple_team_id'] ?? '') === '' && trim($_POST['apple_key_id'] ?? '') === '' && trim($_POST['apple_private_key'] ?? '') === '';
    if ($provider_name === 'apple' && $apple_keep) {
        $client_secret = '';
    } elseif ($provider_name === 'apple') {
        $team = strtoupper(trim($_POST['apple_team_id'] ?? ''));
        $kid  = strtoupper(trim($_POST['apple_key_id'] ?? ''));
        $p8   = trim($_POST['apple_private_key'] ?? '');
        if (!preg_match('/^[A-Z0-9]{10}$/', $team)) { $errs['team'] = 'Apple Team ID is the 10-character id at the top right of developer.apple.com.'; }
        if (!preg_match('/^[A-Z0-9]{10}$/', $kid))  { $errs['kid']  = 'Apple Key ID is the 10-character id of the Sign in with Apple key.'; }
        if (strpos($p8, 'BEGIN PRIVATE KEY') === false || !openssl_pkey_get_private($p8)) {
            $errs['p8'] = 'Paste the whole .p8 file, including the BEGIN/END PRIVATE KEY lines.';
        }
        $client_secret = json_encode(['team_id' => $team, 'key_id' => $kid, 'private_key' => $p8]);
    }

    if (count($errs) <= 0) {
        $safe_name   = sanitize($provider_name, SQL);
        $safe_cid    = sanitize($client_id, SQL);
        // Sealed at rest (secretFunctions.php, task #2397). Write-only: blank = unchanged.
        $safe_secret = sanitize(wn_secret_seal($client_secret), SQL);

        if ($provider_id) {
            $secret_set = $client_secret !== '' ? ", client_secret = '$safe_secret'" : '';
            db_query("UPDATE oauth_provider SET
                provider_name = '$safe_name',
                client_id = '$safe_cid'
                $secret_set
                WHERE provider_id = '$provider_id'");
        } else {
            db_query("INSERT INTO oauth_provider (provider_name, client_id, client_secret, is_enabled)
                      VALUES ('$safe_name', '$safe_cid', '$safe_secret', 0)");
        }

        $_SESSION['success'] = 'OAuth provider saved.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
        // Never discard what was typed: the form refills from this after the redirect.
        $_SESSION['oauth_form'] = array_intersect_key($_POST, array_flip(array(
            'provider_id', 'provider_name', 'client_id', 'client_secret', 'apple_team_id', 'apple_key_id', 'apple_private_key')));
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

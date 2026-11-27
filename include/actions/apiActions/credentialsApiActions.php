<?php
/**
 * credentialsApiActions.php — service-API surface for pasteable credentials.
 *
 * The control plane (monitoring paste-in) and the provisioning wizard use these:
 *   apiGetCredentialStatus (credentials:read)  — what the app needs + what's
 *                                                 still missing. NEVER values.
 *   apiSetCredential       (credentials:write) — paste one value in; validated
 *                                                 against the manifest, stored
 *                                                 outside the webroot.
 *   apiSetOAuthProvider    (credentials:write) — add/update a sign-in provider
 *                                                 (oauth_provider row; secret sealed),
 *                                                 the API twin of admin › OAuth providers.
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

// Sign-in providers (Google, Apple, …) without the admin page: the same row admin › OAuth providers
// writes (oauthActions.php), secret sealed at rest. Upsert by provider name. Apple's client_secret is
// the JSON {"team_id","key_id","private_key"} that oauth_apple_config() reads. Blank secret = keep.
//   POST action=apiSetOAuthProvider provider=google|apple|github|facebook client_id=… [client_secret=…] [enabled=1|0]
if (($action ?? null) == 'apiSetOAuthProvider') {
    if (require_api_scope('credentials:write')) {
        $name   = strtolower(trim((string)($_POST['provider'] ?? '')));
        $cid    = trim((string)($_POST['client_id'] ?? ''));
        $secret = (string)($_POST['client_secret'] ?? '');
        $enable = isset($_POST['enabled']) ? ((int)$_POST['enabled'] ? 1 : 0) : 1;
        if (!in_array($name, ['google', 'apple', 'github', 'facebook'], true)) {
            $_SESSION['error'] = 'provider must be google, apple, github or facebook.';
        } elseif ($cid === '' || strlen($cid) > 255) {
            $_SESSION['error'] = 'client_id is required.';
        } elseif ($name === 'apple' && $secret !== '' && !(($j = json_decode($secret, true)) && !empty($j['team_id']) && !empty($j['key_id']) && strpos((string)($j['private_key'] ?? ''), 'BEGIN PRIVATE KEY') !== false)) {
            $_SESSION['error'] = 'Apple client_secret must be JSON with team_id, key_id and private_key.';
        } else {
            $row = db_fetch(db_query_prepared("SELECT provider_id FROM oauth_provider WHERE provider_name = ? ORDER BY provider_id DESC LIMIT 1", [$name]));
            if ($row) {
                if ($secret !== '') {
                    db_query_prepared("UPDATE oauth_provider SET client_id = ?, client_secret = ?, is_enabled = ? WHERE provider_id = ?",
                        [$cid, wn_secret_seal($secret), $enable, (int)$row['provider_id']]);
                } else {
                    db_query_prepared("UPDATE oauth_provider SET client_id = ?, is_enabled = ? WHERE provider_id = ?",
                        [$cid, $enable, (int)$row['provider_id']]);
                }
            } elseif ($secret === '') {
                $_SESSION['error'] = 'client_secret is required for a new provider.';
            } else {
                db_query_prepared("INSERT INTO oauth_provider (provider_name, client_id, client_secret, is_enabled) VALUES (?, ?, ?, ?)",
                    [$name, $cid, wn_secret_seal($secret), $enable]);
            }
            if (empty($_SESSION['error'])) {
                if (function_exists('credential_audit_log')) { credential_audit_log('oauth:' . $name); }
                $data['provider'] = $name;
                $data['enabled']  = function_exists('oauth_enabled_providers') ? oauth_enabled_providers() : [];
                $_SESSION['success'] = 'OAuth provider saved.';
            }
        }
    }
}

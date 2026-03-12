<?php
/**
 * saml_callback.php
 * SAML 2.0 SP endpoint — login initiation, ACS (Assertion Consumer Service), and SLS.
 *
 * Routes:
 *   ?login=<slug>  — Redirect user to IdP for authentication
 *   ?acs=<slug>    — Receive POST from IdP after authentication (ACS)
 *   ?sls=<slug>    — Single Logout Service callback
 */
include(__DIR__ . '/../include/common_auth.php');

use OneLogin\Saml2\Auth as SamlAuth;

// ─── INITIATE LOGIN (redirect to IdP) ─────────────────────────────────────

if (isset($_GET['login'])) {
    $slug = $_GET['login'];
    $provider = get_saml_provider_by_slug($slug);

    if (!$provider || !$provider['is_enabled']) {
        $_SESSION['error'] = 'SAML provider not found or disabled.';
        header('Location: login.php');
        exit;
    }

    $settings = build_saml_settings($provider);
    $auth = new SamlAuth($settings);
    $auth->login(); // Redirects to IdP — does not return
    exit;
}

// ─── ACS (receive POST from IdP) ──────────────────────────────────────────

if (isset($_GET['acs']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = $_GET['acs'];
    $provider = get_saml_provider_by_slug($slug);

    if (!$provider || !$provider['is_enabled']) {
        $_SESSION['error'] = 'SAML provider not found or disabled.';
        header('Location: login.php');
        exit;
    }

    $settings = build_saml_settings($provider);
    $auth = new SamlAuth($settings);

    $auth->processResponse();
    $errors = $auth->getErrors();

    if (!empty($errors) || !$auth->isAuthenticated()) {
        $errorMsg = implode(', ', $errors);
        $reason   = $auth->getLastErrorReason();
        error_log("SAML ACS error (provider=$slug): $errorMsg — $reason");
        $_SESSION['error'] = 'SAML authentication failed. Please try again.';
        header('Location: login.php');
        exit;
    }

    // Extract user data from SAML assertion
    $userData = extract_saml_user_attributes($auth, $provider);

    if (empty($userData['email'])) {
        $_SESSION['error'] = 'Could not retrieve your email from the identity provider.';
        header('Location: login.php');
        exit;
    }

    // ─── USER MATCHING / REGISTRATION (mirrors oauth_callback.php) ─────────

    $user = get_user_by_email($userData['email']);

    if ($user) {
        // Existing user — link SAML if not already linked
        if (!$user['oauth_provider']) {
            $safe_provider = sanitize('saml:' . $slug, SQL);
            $safe_id       = sanitize($userData['name_id'], SQL);
            db_query("UPDATE user SET oauth_provider = '$safe_provider', oauth_id = '$safe_id'
                      WHERE user_id = '{$user['user_id']}'");
        }

        // Check 2FA
        if ($user['totp_enabled']) {
            $_SESSION['2fa_pending'] = true;
            $_SESSION['2fa_user_id'] = $user['user_id'];
            header('Location: 2fa.php');
            exit;
        }

        load_user_session($user);

        // Store SAML session data for potential SLO
        $_SESSION['saml_session_index'] = $userData['session_index'] ?? null;
        $_SESSION['saml_name_id']       = $userData['name_id'] ?? null;
        $_SESSION['saml_provider_slug'] = $slug;

        // Link migration map if this SAML provider is configured as migration source
        if (function_exists('link_saml_migration_map')) {
            link_saml_migration_map($user['user_id'], $userData['email'], $slug);
        }

        $_SESSION['success'] = 'Welcome back!';
        header('Location: ../app/');
        exit;

    } else {
        // New user — auto-register
        $settings_row = db_fetch(db_query("SELECT registration_mode FROM auth_settings WHERE setting_id = 1"));
        $mode = $settings_row['registration_mode'] ?? 'open';

        if ($mode === 'closed') {
            $_SESSION['error'] = 'Registration is currently closed.';
            header('Location: login.php');
            exit;
        }

        $shard_id      = get_least_loaded_shard();
        $safe_email    = sanitize($userData['email'], SQL);
        $safe_provider = sanitize('saml:' . $slug, SQL);
        $safe_oid      = sanitize($userData['name_id'], SQL);

        $r = db_query("INSERT INTO user (email, shard_id, is_confirmed, oauth_provider, oauth_id, created_date)
                        VALUES ('$safe_email', '$shard_id', 1, '$safe_provider', '$safe_oid', NOW())");

        if ($r) {
            $new_id = db_insert_id();

            prime_shard($shard_id);
            db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                            VALUES ('$new_id', '" . sanitize($userData['first_name'] ?? '', SQL) . "',
                                    '" . sanitize($userData['last_name'] ?? '', SQL) . "', NOW())");

            $_SESSION['shard_id'] = $shard_id;
            create_home_dir_id($new_id);

            $user = get_user($new_id);
            load_user_session($user);

            $_SESSION['saml_session_index'] = $userData['session_index'] ?? null;
            $_SESSION['saml_name_id']       = $userData['name_id'] ?? null;
            $_SESSION['saml_provider_slug'] = $slug;

            // Link migration map for newly created SAML user
            if (function_exists('link_saml_migration_map')) {
                link_saml_migration_map($new_id, $userData['email'], $slug);
            }

            $_SESSION['success'] = 'Account created! Welcome.';
            header('Location: ../app/');
            exit;
        } else {
            $_SESSION['error'] = 'Could not create account. ' . db_error();
            header('Location: login.php');
            exit;
        }
    }
}

// ─── SLS (Single Logout Service callback) ──────────────────────────────────

if (isset($_GET['sls'])) {
    $slug = $_GET['sls'];
    $provider = get_saml_provider_by_slug($slug);

    if ($provider) {
        $settings = build_saml_settings($provider);
        $auth = new SamlAuth($settings);
        $auth->processSLO();
    }

    session_destroy();
    header('Location: login.php');
    exit;
}

// Fallback — no valid action
$_SESSION['error'] = 'Invalid SAML request.';
header('Location: login.php');
exit;

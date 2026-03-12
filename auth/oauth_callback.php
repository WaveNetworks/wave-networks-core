<?php
include(__DIR__ . '/../include/common_auth.php');

// Handle redirect TO provider
if (isset($_GET['provider'])) {
    $provider = $_GET['provider'];
    oauth_redirect($provider);
    exit;
}

// Handle callback FROM provider
$provider = $_SESSION['oauth_provider'] ?? '';
$code     = $_GET['code'] ?? '';
$state    = $_GET['state'] ?? '';

if (!$provider || !$code) {
    $_SESSION['error'] = 'Invalid OAuth callback.';
    header('Location: login.php');
    exit;
}

$userData = oauth_callback($provider, $code, $state);
unset($_SESSION['oauth_provider'], $_SESSION['oauth2state']);

if (!$userData || empty($userData['email'])) {
    $_SESSION['error'] = $_SESSION['error'] ?? 'Could not retrieve your email from the OAuth provider.';
    header('Location: login.php');
    exit;
}

// Check if user exists
$user = get_user_by_email($userData['email']);

if ($user) {
    // Existing user — log them in
    if (!$user['oauth_provider']) {
        // Link OAuth to existing account
        $safe_provider = sanitize($provider, SQL);
        $safe_id = sanitize($userData['oauth_id'], SQL);
        db_query("UPDATE user SET oauth_provider = '$safe_provider', oauth_id = '$safe_id' WHERE user_id = '{$user['user_id']}'");
    }

    // Check 2FA
    if ($user['totp_enabled']) {
        $_SESSION['2fa_pending'] = true;
        $_SESSION['2fa_user_id'] = $user['user_id'];
        header('Location: 2fa.php');
        exit;
    }

    load_user_session($user);
    $_SESSION['success'] = 'Welcome back!';
    header('Location: ../app/');
    exit;

} else {
    // New user — auto-register
    $settings = db_fetch(db_query("SELECT registration_mode FROM auth_settings WHERE setting_id = 1"));
    $mode = $settings['registration_mode'] ?? 'open';

    if ($mode === 'closed') {
        $_SESSION['error'] = 'Registration is currently closed.';
        header('Location: login.php');
        exit;
    }

    $shard_id = get_least_loaded_shard();
    $safe_email    = sanitize($userData['email'], SQL);
    $safe_provider = sanitize($provider, SQL);
    $safe_oid      = sanitize($userData['oauth_id'], SQL);

    $r = db_query("INSERT INTO user (email, shard_id, is_confirmed, oauth_provider, oauth_id, created_date)
                    VALUES ('$safe_email', '$shard_id', 1, '$safe_provider', '$safe_oid', NOW())");

    if ($r) {
        $new_id = db_insert_id();
        $nameParts = explode(' ', $userData['name'], 2);

        prime_shard($shard_id);
        db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                        VALUES ('$new_id', '" . sanitize($nameParts[0] ?? '', SQL) . "', '" . sanitize($nameParts[1] ?? '', SQL) . "', NOW())");

        $_SESSION['shard_id'] = $shard_id;
        create_home_dir_id($new_id);

        $user = get_user($new_id);
        load_user_session($user);

        $_SESSION['success'] = 'Account created! Welcome.';
        header('Location: ../app/');
        exit;
    } else {
        $_SESSION['error'] = 'Could not create account. ' . db_error();
        header('Location: login.php');
        exit;
    }
}

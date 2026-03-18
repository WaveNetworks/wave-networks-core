<?php
/**
 * common.php
 * Full authenticated bootstrap.
 * Loads config → connects DB → runs migrations → guards session → includes all helpers + actions.
 */

// 1. Composer autoload
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Load config (config.php or env fallback for Docker)
$configFile = __DIR__ . '/../config/config.php';
if (file_exists($configFile)) {
    include($configFile);
} else {
    // Docker / CI: read from container environment
    $dbHostSpec     = getenv('DB_HOST_MAIN')       ?: 'localhost';
    $dbInstance     = getenv('DB_NAME_MAIN')       ?: 'wncore_main';
    $dbUserName     = getenv('DB_USER')            ?: 'root';
    $dbPassword     = getenv('DB_PASSWORD')        ?: '';
    $hiddenhash     = getenv('HIDDEN_HASH')        ?: 'dev_hash';
    $app_secret     = getenv('APP_SECRET')         ?: 'dev_secret';
    $files_location = getenv('FILES_LOCATION')     ?: '/var/files/';
    $smtp_host      = getenv('SMTP_HOST')          ?: 'mailhog';
    $smtp_port      = getenv('SMTP_PORT')          ?: 1025;
    $smtp_user      = getenv('SMTP_USER')          ?: '';
    $smtp_pass      = getenv('SMTP_PASS')          ?: '';
    $mail_from      = getenv('MAIL_FROM')          ?: 'noreply@localhost';
    $mail_from_name = getenv('MAIL_FROM_NAME')     ?: 'Admin';

    $google_client_id     = getenv('GOOGLE_CLIENT_ID')     ?: '';
    $google_client_secret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
    $github_client_id     = getenv('GITHUB_CLIENT_ID')     ?: '';
    $github_client_secret = getenv('GITHUB_CLIENT_SECRET') ?: '';
    $facebook_app_id      = getenv('FACEBOOK_APP_ID')      ?: '';
    $facebook_app_secret  = getenv('FACEBOOK_APP_SECRET')  ?: '';

    $grecaptcha_key    = getenv('RECAPTCHA_SITE_KEY')    ?: '';
    $grecaptcha_secret = getenv('RECAPTCHA_SECRET_KEY')  ?: '';

    $stripe_secret_key = getenv('STRIPE_SECRET_KEY') ?: '';
    $stripe_public_key = getenv('STRIPE_PUBLIC_KEY') ?: '';

    $vapid_subject     = getenv('VAPID_SUBJECT')     ?: '';
    $vapid_public_key  = getenv('VAPID_PUBLIC_KEY')   ?: '';
    $vapid_private_key = getenv('VAPID_PRIVATE_KEY')  ?: '';

    $shardConfigs = [];
    if (getenv('DB_HOST_SHARD')) {
        $shardConfigs['shard1'] = [
            'host' => getenv('DB_HOST_SHARD'),
            'name' => getenv('DB_NAME_SHARD') ?: 'wncore_shard_1',
            'user' => getenv('DB_USER') ?: 'root',
            'pass' => getenv('DB_PASSWORD') ?: '',
        ];
    }
    if (getenv('DB_HOST_SHARD2')) {
        $shardConfigs['shard2'] = [
            'host' => getenv('DB_HOST_SHARD2'),
            'name' => getenv('DB_NAME_SHARD2') ?: 'wncore_shard_2',
            'user' => getenv('DB_USER') ?: 'root',
            'pass' => getenv('DB_PASSWORD') ?: '',
        ];
    }
}

// 2b. Ensure files directory exists
if (!empty($files_location)) {
    if (!is_dir($files_location)) { @mkdir($files_location, 0755, true); }
    if (!is_dir($files_location . 'home/')) { @mkdir($files_location . 'home/', 0755, true); }
    if (!is_dir($files_location . 'branding/')) { @mkdir($files_location . 'branding/', 0755, true); }
}

// 3. PDO connection
$db = new PDO("mysql:host=$dbHostSpec;dbname=$dbInstance;charset=utf8mb4", $dbUserName, $dbPassword);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 4. Glob-include all helpers
foreach (glob(__DIR__ . '/common/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/common/*.inc.php') as $f) { include_once($f); }

// 5. Migrations
$db_version    = $db_version ?? 2.6;
$shard_version = $shard_version ?? 1.1;
check_and_migrate_main_db();
check_and_migrate_all_shards();

// 6. Session guard
session_start();
if (empty($_SESSION['user_id'])) {
    // Try auto-login via cookie
    $auto_logged = false;
    if (isset($_COOKIE['wn_auto_login'])) {
        $user = validate_api_key($_COOKIE['wn_auto_login']);
        if ($user) {
            load_user_session($user);
            $auto_logged = true;
        } else {
            setcookie('wn_auto_login', '', time() - 3600, '/', '', false, true);
        }
    }

    if (!$auto_logged) {
        header('Location: ../auth/login.php');
        exit;
    }
}

// 6b. Device tracking — identify all visitors via persistent cookie
if (function_exists('get_or_create_device')) {
    try { get_or_create_device(); } catch (Exception $e) { /* graceful */ }
}

// 7. definition.php
include(__DIR__ . '/definition.php');

// 8. Action includes
foreach (glob(__DIR__ . '/actions/*/*.php') as $f) { include_once($f); }

<?php
/**
 * bootstrap.php — the shared bootstrap for every entry point.
 *
 * common.php, common_auth.php and common_api.php each carried their own copy of this:
 * autoload, config, credentials, shard config, PDO connect, helper globs, migrations.
 * 73 lines of executable code, byte-identical in all three once comments were stripped,
 * and holding all 36 getenv() calls — DB password, APP_SECRET, SMTP, the OAuth client
 * secrets, Stripe, VAPID. Every credential in the platform was written out three times,
 * kept consistent only by nobody editing one copy in isolation.
 *
 * Eventually somebody did. $db_version drifted to 4.7 / 4.3 / 4.6, and because
 * run_pending_migrations() stops at `if ($ver > $db_version) break;` a stale copy
 * silently declined to apply migrations — so whether a schema upgrade landed depended on
 * which bootstrap the request came through. common_auth.php had also lost the mobile
 * engine glob, which is what took a child app's mobile Home and Gallery screens dark.
 * Both were invisible until something broke downstream.
 *
 * What legitimately differs between the three is only the auth posture, and that stays
 * in each file: guard, no guard, or Bearer API auth.
 *
 * Included at top level, so every variable it defines ($db, $shardConfigs,
 * $files_location, the credentials) lands in the including scope exactly as before.
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

// 2a. Notifications config partial — written by the admin UI (Push Setup).
// Loaded AFTER the main config / env block so it wins on those globals,
// and so the env-var fallback for Docker still applies when the partial
// is absent. Gitignored — see config/notifications_config.sample.php.
$notificationsConfigFile = __DIR__ . '/../config/notifications_config.php';
if (file_exists($notificationsConfigFile)) {
    include($notificationsConfigFile);
}

// 2b. Ensure files directory exists
if (!empty($files_location)) {
    if (!is_dir($files_location)) { @mkdir($files_location, 0755, true); }
    if (!is_dir($files_location . 'home/')) { @mkdir($files_location . 'home/', 0755, true); }
    if (!is_dir($files_location . 'branding/')) { @mkdir($files_location . 'branding/', 0755, true); }
}

// 3. PDO connection. Persistent so PHP-FPM workers reuse the socket across
// requests instead of opening a fresh one per AJAX call. Especially matters
// for child apps (each one opens its own $child_db on top of this $db).
$db = new PDO(
    "mysql:host=$dbHostSpec;dbname=$dbInstance;charset=utf8mb4",
    $dbUserName, $dbPassword,
    [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
    ]
);

// 4. Glob-include all helpers
foreach (glob(__DIR__ . '/common/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/common/*.inc.php') as $f) { include_once($f); }
// Mobile engine (child-app spec 05): the fragment splitter + build-time helpers, so the
// ?page=&mobile=1 fragment endpoint in every child app can call wn_split_view().
foreach (glob(__DIR__ . '/mobile/*.php') as $f) { include_once($f); }

// 5. Migrations
$db_version    = $db_version ?? 4.7;
$shard_version = $shard_version ?? 1.3;
check_and_migrate_main_db();
check_and_migrate_all_shards();


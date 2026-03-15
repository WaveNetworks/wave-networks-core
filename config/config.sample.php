<?php
// Copy to admin/config/config.php
// Fill in real values. NEVER commit config.php

$dbHostSpec  = 'your_main_db_host';
$dbInstance  = 'wncore_main';
$dbUserName  = 'your_main_db_user';
$dbPassword  = 'your_main_db_password';

$shardConfigs = [
    'shard1' => [
        'host' => 'your_shard1_db_host',
        'name' => 'wncore_shard_1',
        'user' => 'your_shard1_db_user',
        'pass' => 'your_shard1_db_password',
    ],
    'shard2' => [
        'host' => 'your_shard2_db_host',
        'name' => 'wncore_shard_2',
        'user' => 'your_shard2_db_user',
        'pass' => 'your_shard2_db_password',
    ],
];

$hiddenhash     = 'your_random_secret';   // salts all passwords
$app_secret     = 'your_64_char_secret';  // signs JWTs

$files_location = '/home/username/files/'; // trailing slash required

$smtp_host      = 'smtp.yourdomain.com';
$smtp_port      = 587;
$smtp_user      = '';
$smtp_pass      = '';
$mail_from      = 'noreply@yourdomain.com';
$mail_from_name = 'Admin';

$google_client_id     = '';
$google_client_secret = '';
$github_client_id     = '';
$github_client_secret = '';
$facebook_app_id      = '';
$facebook_app_secret  = '';

$grecaptcha_key    = '';
$grecaptcha_secret = '';

$stripe_secret_key = 'sk_live_xxxx';
$stripe_public_key = 'pk_live_xxxx';

// Web Push (VAPID) — generate keys: php vendor/bin/minishlink-web-push generate-keys
$vapid_subject     = 'mailto:admin@yourdomain.com';
$vapid_public_key  = '';
$vapid_private_key = '';

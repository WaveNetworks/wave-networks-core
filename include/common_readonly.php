<?php
/**
 * common_readonly.php
 * Read-only bootstrap — loads config + DB + helpers. No action includes.
 */

// 1. Composer autoload
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Load config
$configFile = __DIR__ . '/../config/config.php';
if (file_exists($configFile)) {
    include($configFile);
} else {
    $dbHostSpec     = getenv('DB_HOST_MAIN')       ?: 'localhost';
    $dbInstance     = getenv('DB_NAME_MAIN')       ?: 'wncore_main';
    $dbUserName     = getenv('DB_USER')            ?: 'root';
    $dbPassword     = getenv('DB_PASSWORD')        ?: '';
    $hiddenhash     = getenv('HIDDEN_HASH')        ?: 'dev_hash';
    $app_secret     = getenv('APP_SECRET')         ?: 'dev_secret';
    $files_location = getenv('FILES_LOCATION')     ?: '/var/files/';

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

// 2c. THE CLOCK: UTC here and on every connection (include/common/clockFunctions.php).
date_default_timezone_set('UTC');

// 3. PDO connection. NOT persistent — common_readonly.php is the bootstrap
// for CLI cron scripts which run as one-shot processes and exit. Persistent
// would do nothing here since there's no FPM worker to reuse the socket.
$db = new PDO("mysql:host=$dbHostSpec;dbname=$dbInstance;charset=utf8mb4", $dbUserName, $dbPassword,
    // One clock for the whole stack: UTC (include/common/clockFunctions.php).
    [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 4. Glob-include all helpers
foreach (glob(__DIR__ . '/common/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/common/*.inc.php') as $f) { include_once($f); }

// 4b. Shard registry — same merge as bootstrap.php. No migration is run from
// this read-only bootstrap, so an absent shard_config simply yields config.php's
// shards unchanged. See shardConfigFunctions.php.
$shardConfigs = shard_config_merge($shardConfigs ?? [], 'admin', '');

// 5. Session
init_session_storage();
session_start();

// 6. definition.php
include(__DIR__ . '/definition.php');

// No action includes — read-only

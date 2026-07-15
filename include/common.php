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
$db_version    = $db_version ?? 4.6;
$shard_version = $shard_version ?? 1.3;
check_and_migrate_main_db();
check_and_migrate_all_shards();

// 6. Session guard (browser requests only — CLI/cron has no session and
// cannot emit headers after job output has already started)
if (php_sapi_name() !== 'cli') {

    // A bundled mobile client authenticates with a Bearer device token, not a cookie
    // (child-app spec 05). It reaches this file because the mobile fragment endpoint IS
    // the ?page= view path — same guard, same view files, same permissions. Only the
    // credential differs, and by the time anything downstream runs, the session it
    // produces is identical to a browser's.
    //
    // No session is started for these requests: sessions are file-backed, and a phone
    // polling chat would leave a file behind on every request.
    if (function_exists('wn_send_cors_headers')) { wn_send_cors_headers(); }

    if (function_exists('wn_device_token') && wn_device_token() !== '') {
        $_SESSION = [];
        if (!wn_authenticate_device_token()) {
            // Never redirect a bundled client to a login PAGE — there is no server to
            // navigate to inside the app. 401 lets the shell route to its login screen.
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => 'Login required.', 'success' => '', 'info' => '', 'warning' => '', 'results' => []]);
            exit;
        }
    } else {
        // Ordinary browser request — cookie session, exactly as before.
        init_session_storage();
        session_start();

        if (empty($_SESSION['user_id'])) {
            // Try auto-login via cookie. Note this validates the SAME credential a
            // device token is (an api_key row) — the only difference is transport.
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
                // A mobile fragment request (mobile-web: cookie session, no Bearer token —
                // the device path is handled above) must get a clean 401, NOT a 302 to the
                // login PAGE. The shell fetches views as fragments; following a redirect would
                // splice the full login.php HTML into the content area. On 401 the shell
                // renders its own bundled login screen. Same contract as the device 401 above.
                if (!empty($_GET['mobile'])) {
                    if (!headers_sent()) {
                        http_response_code(401);
                        header('Content-Type: application/json');
                    }
                    echo json_encode(['error' => 'Login required.', 'success' => '', 'info' => '', 'warning' => '', 'results' => []]);
                    exit;
                }
                header('Location: ../auth/login.php');
                exit;
            }
        }
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

// 9. Action logging — record what was just executed
if (function_exists('log_user_action')) {
    $__action = $_POST['action'] ?? 'view';
    try { log_user_action($__action); } catch (Exception $e) { /* silent */ }
    unset($__action);
}

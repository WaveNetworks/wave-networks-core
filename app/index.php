<?php
/**
 * app/index.php
 * Main app controller. Session-guarded by common.php.
 * Routes to view files based on ?page= parameter.
 */
include(__DIR__ . '/../include/common.php');

$page = $_GET['page'] ?? 'dashboard';

// Map page names to view files
$views = [
    'dashboard'       => __DIR__ . '/../views/dashboard.php',
    'users'           => __DIR__ . '/../views/users.php',
    'user_edit'       => __DIR__ . '/../views/user_edit.php',
    'user_create'     => __DIR__ . '/../views/user_create.php',
    'roles'           => __DIR__ . '/../views/roles.php',
    'settings'        => __DIR__ . '/../views/settings.php',
    'oauth_providers' => __DIR__ . '/../views/oauth_providers.php',
    'saml_providers'       => __DIR__ . '/../views/saml_providers.php',
    'reports'              => __DIR__ . '/../views/reports.php',
    'reports_acquisition'  => __DIR__ . '/../views/reports_acquisition.php',
    'reports_retention'    => __DIR__ . '/../views/reports_retention.php',
    'reports_forecast'     => __DIR__ . '/../views/reports_forecast.php',
    'migration'                => __DIR__ . '/../views/migration.php',
    'notifications'            => __DIR__ . '/../views/notifications.php',
    'notification_preferences' => __DIR__ . '/../views/notification_preferences.php',
    'notification_admin'       => __DIR__ . '/../views/notification_admin.php',
    'email'                    => __DIR__ . '/../views/email.php',
    'error_log'                => __DIR__ . '/../views/error_log.php',
    'api_keys'                 => __DIR__ . '/../views/api_keys.php',
    'account_security'         => __DIR__ . '/../views/account_security.php',
];

if (isset($views[$page]) && file_exists($views[$page])) {
    $current_page_file = $views[$page];
} else {
    $current_page_file = __DIR__ . '/../views/404.php';
}

// Template wraps the view
include(__DIR__ . '/../views/template.php');

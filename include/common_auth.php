<?php
/**
 * common_auth.php
 * Bootstrap for auth pages (login, register, forgot, reset).
 * Same as common.php but WITHOUT session guard — unauthenticated users can access these.
 */

// Shared bootstrap: autoload, config + credentials, PDO, helpers, migrations.
// Everything below is what makes THIS entry point different — the auth posture.
require_once __DIR__ . '/bootstrap.php';

// 6. Session (start but NO guard — unauthenticated users need these pages)
init_session_storage();
session_start();

// 6b. Device tracking — identify all visitors via persistent cookie
if (function_exists('get_or_create_device')) {
    try { get_or_create_device(); } catch (Exception $e) { /* graceful */ }
}

// 7. definition.php
include(__DIR__ . '/definition.php');

// 7a. Proxy sign-in only (proxyFunctions.php): the proxy is the one way in, so the password,
// code, registration and in-app SSO doors are shut. login.php signs the person in from the
// proxy's identity, or says why it can't.
if (function_exists('wn_proxy_auth_required') && wn_proxy_auth_required()) {
    if (!in_array($action, [null, 'logout', 'acceptReconsent'], true)) { $action = null; }
    if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['login.php', 'consent.php'], true)) {
        header('Location: login.php');
        exit;
    }
}

// 8. Action includes — login actions only for auth pages
foreach (glob(__DIR__ . '/actions/loginActions/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/actions/apiActions/*.php') as $f) { include_once($f); }

// 9. Action logging — record what was just executed
if (function_exists('log_user_action')) {
    $__action = $_POST['action'] ?? 'view';
    try { log_user_action($__action); } catch (Exception $e) { /* silent */ }
    unset($__action);
}

<?php
/**
 * sessionFunctions.php
 * Session and role-checking helpers.
 */

/**
 * Check if the current user has a given role.
 * Roles are hierarchical: owner > admin > manager > employee.
 *
 * @param string $role  'owner', 'admin', 'manager', 'employee'
 * @return bool
 */
function has_role($role) {
    switch ($role) {
        case 'owner':
            return !empty($_SESSION['is_owner']);
        case 'admin':
            return !empty($_SESSION['is_owner']) || !empty($_SESSION['is_admin']);
        case 'manager':
            return !empty($_SESSION['is_owner']) || !empty($_SESSION['is_admin']) || !empty($_SESSION['is_manager']);
        case 'employee':
            return !empty($_SESSION['is_owner']) || !empty($_SESSION['is_admin']) || !empty($_SESSION['is_manager']) || !empty($_SESSION['is_employee']);
        default:
            return false;
    }
}

/**
 * Check if user is logged in.
 *
 * @return bool
 */
function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

/**
 * Get the full display name of the current user.
 *
 * @return string
 */
function get_display_name() {
    $first = $_SESSION['first_name'] ?? '';
    $last  = $_SESSION['last_name'] ?? '';
    $name  = trim("$first $last");
    return $name ?: ($_SESSION['email'] ?? 'User');
}

/**
 * Configure per-deployment session storage before session_start().
 *
 * Default session.save_path is /tmp, which on shared hosts produces
 * "Permission denied" / "Failed to read session data" warnings when
 * another tenant or PHP-FPM pool writes /tmp/sess_* files under a
 * different UID, or when /tmp cleanup races a live request. Route
 * session files to a dedicated directory inside $files_location
 * (outside webroot, owned by this site's UID) so each deployment
 * owns its own session storage.
 */
function init_session_storage() {
    global $files_location;

    if (empty($files_location)) { return; }

    // session.save_path cannot be changed once a session is active. This
    // happens when a CLI/cron process bootstraps twice (e.g. cron.php loads
    // common_readonly.php → session_start(), then a job includes common.php
    // which calls init_session_storage() again). Bail quietly instead of
    // emitting a "Session save path cannot be changed" warning.
    if (session_status() === PHP_SESSION_ACTIVE) { return; }

    $sessionDir = rtrim($files_location, '/') . '/sessions';

    if (!is_dir($sessionDir)) {
        if (!@mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
            return;
        }
    }

    if (!is_writable($sessionDir)) { return; }

    session_save_path($sessionDir);
}

/**
 * Destroy session and clear auto-login cookie.
 */
function logout() {
    // Delete API key if cookie exists
    if (isset($_COOKIE['wn_auto_login'])) {
        delete_api_key($_COOKIE['wn_auto_login']);
        setcookie('wn_auto_login', '', time() - 3600, '/', '', false, true);
    }

    session_unset();
    session_destroy();
}

/**
 * Where to send a user after they authenticate.
 *
 * Core is deployed per-app: each child app's deploy.yml rsyncs this repo into its
 * own public_html/admin/ alongside public_html/<slug>/. So on those deployments the
 * thing a person logged in to use is the CHILD app, not admin's own scaffold app —
 * but login.php, loginActions.php and consentActions.php all sent them to ../app/,
 * which is admin's. A supporter who registered from a campaign site landed in a
 * bare admin shell and had no idea where they were or how to get back.
 *
 * Detected by looking for a sibling app/index.php, the same one-app-per-deployment
 * assumption admin/cron/cron.php makes when it globs ../../{*}/cron/cron.php.
 *
 * Returns a path relative to /admin/<dir>/, matching the existing '../app/' callers.
 * Falls back to admin's own app when there is no sibling, so a standalone core
 * deployment is unchanged.
 */
function get_post_login_home($fallback = '../app/') {
    static $cached = null;
    if ($cached !== null) return $cached;

    // sessionFunctions.php sits at admin/include/common/, so three up is the web root.
    $webroot = dirname(__DIR__, 3);
    foreach (glob($webroot . '/*/app/index.php') ?: [] as $file) {
        $slug = basename(dirname($file, 2));
        if ($slug === 'admin' || $slug === '') continue;
        return $cached = '../../' . $slug . '/app/';
    }
    return $cached = $fallback;
}

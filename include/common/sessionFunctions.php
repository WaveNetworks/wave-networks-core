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

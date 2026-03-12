<?php
/**
 * userFunctions.php
 * User lookup and management helpers (main DB).
 */

/**
 * Get a user by ID from the main DB.
 *
 * @param int $user_id
 * @return array|false
 */
function get_user($user_id) {
    $user_id = (int)$user_id;
    $r = db_query("SELECT * FROM user WHERE user_id = '$user_id'");
    return db_fetch($r);
}

/**
 * Get a user by email from the main DB.
 *
 * @param string $email
 * @return array|false
 */
function get_user_by_email($email) {
    $email = sanitize($email, SQL);
    $r = db_query("SELECT * FROM user WHERE email = '$email'");
    return db_fetch($r);
}

/**
 * Get user profile from the shard.
 *
 * @param int    $user_id
 * @param string $shard_id
 * @return array|false
 */
function get_user_profile($user_id, $shard_id) {
    $user_id = (int)$user_id;
    prime_shard($shard_id);
    $r = db_query_shard($shard_id, "SELECT * FROM user_profile WHERE user_id = '$user_id'");
    return db_fetch($r);
}

/**
 * Get paginated user list from the main DB.
 *
 * @param int    $page
 * @param int    $per_page
 * @param string $search
 * @param string $sort
 * @param string $dir
 * @return array ['users' => [], 'total' => int]
 */
function get_users_paginated($page = 1, $per_page = 20, $search = '', $sort = 'user_id', $dir = 'DESC') {
    $offset = ($page - 1) * $per_page;
    $per_page = (int)$per_page;

    $allowed_sort = ['user_id', 'email', 'created_date', 'last_login', 'shard_id'];
    if (!in_array($sort, $allowed_sort)) $sort = 'user_id';
    $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

    $where = '';
    if ($search) {
        $search = sanitize($search, SQL);
        $where = "WHERE email LIKE '%$search%'";
    }

    $countR = db_query("SELECT COUNT(*) as cnt FROM user $where");
    $total = db_fetch($countR)['cnt'] ?? 0;

    $r = db_query("SELECT * FROM user $where ORDER BY $sort $dir LIMIT $offset, $per_page");
    $users = db_fetch_all($r);

    return ['users' => $users, 'total' => (int)$total];
}

/**
 * Load session data for a logged-in user.
 *
 * @param array $user Main DB user row
 */
function load_user_session($user) {
    $_SESSION['user_id']     = $user['user_id'];
    $_SESSION['email']       = $user['email'];
    $_SESSION['shard_id']    = $user['shard_id'];
    $_SESSION['is_admin']    = $user['is_admin'];
    $_SESSION['is_owner']    = $user['is_owner'];
    $_SESSION['is_manager']  = $user['is_manager'];
    $_SESSION['is_employee'] = $user['is_employee'];

    // Load profile from shard
    $profile = get_user_profile($user['user_id'], $user['shard_id']);
    if ($profile) {
        $_SESSION['first_name']    = $profile['first_name'];
        $_SESSION['last_name']     = $profile['last_name'];
        $_SESSION['homedir']       = $profile['homedir'];
        $_SESSION['profile_image'] = $profile['profile_image'];
    }

    // Update last_login
    db_query("UPDATE user SET last_login = NOW() WHERE user_id = '{$user['user_id']}'");
}

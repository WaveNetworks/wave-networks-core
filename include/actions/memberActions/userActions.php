<?php
/**
 * User Actions
 * Actions: addUser, editUser, deleteUser, confirmUser
 */

// ─── ADD USER ────────────────────────────────────────────────────────────────

if (($action ?? null) == 'addUser') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $is_admin   = (int)($_POST['is_admin'] ?? 0);
    $is_manager = (int)($_POST['is_manager'] ?? 0);
    $is_employee = (int)($_POST['is_employee'] ?? 0);

    if (!valid_email($email))       { $errs['email'] = 'Valid email is required.'; }
    if (!valid_password($password))  { $errs['password'] = 'Password must be at least 8 characters.'; }

    if (count($errs) <= 0) {
        $existing = get_user_by_email($email);
        if ($existing) { $errs['email'] = 'An account with this email already exists.'; }
    }

    if (count($errs) <= 0) {
        $hashed   = hash_password($password);
        $shard_id = get_least_loaded_shard();
        $safe_email = sanitize($email, SQL);

        $r = db_query("INSERT INTO user (email, password, shard_id, is_admin, is_manager, is_employee, is_confirmed, created_date)
                        VALUES ('$safe_email', '$hashed', '$shard_id', '$is_admin', '$is_manager', '$is_employee', 1, NOW())");

        if ($r) {
            $new_id = db_insert_id();

            prime_shard($shard_id);
            db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                            VALUES ('$new_id', '" . sanitize($first_name, SQL) . "', '" . sanitize($last_name, SQL) . "', NOW())");

            $tmp_shard = $_SESSION['shard_id'];
            $_SESSION['shard_id'] = $shard_id;
            create_home_dir_id($new_id);
            $_SESSION['shard_id'] = $tmp_shard;

            $_SESSION['success'] = 'User added successfully.';
            $data['user_id'] = $new_id;
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── EDIT USER ───────────────────────────────────────────────────────────────

if (($action ?? null) == 'editUser') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id     = (int)($_POST['user_id'] ?? 0);
    $email       = trim($_POST['email'] ?? '');
    $first_name  = trim($_POST['first_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $is_admin    = (int)($_POST['is_admin'] ?? 0);
    $is_manager  = (int)($_POST['is_manager'] ?? 0);
    $is_employee = (int)($_POST['is_employee'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if (!$user_id)            { $errs['user_id'] = 'User ID required.'; }
    if (!valid_email($email)) { $errs['email'] = 'Valid email is required.'; }

    if (count($errs) <= 0) {
        $user = get_user($user_id);
        if (!$user) { $errs['user_id'] = 'User not found.'; }
    }

    if (count($errs) <= 0) {
        // Check email uniqueness (if changed)
        if (strtolower($email) !== strtolower($user['email'])) {
            $existing = get_user_by_email($email);
            if ($existing) { $errs['email'] = 'Email already in use by another account.'; }
        }
    }

    if (count($errs) <= 0) {
        $safe_email = sanitize($email, SQL);
        $sql = "UPDATE user SET email = '$safe_email', is_admin = '$is_admin', is_manager = '$is_manager', is_employee = '$is_employee'";

        if ($new_password && valid_password($new_password)) {
            $hashed = hash_password($new_password);
            $sql .= ", password = '$hashed'";
        }

        $sql .= " WHERE user_id = '$user_id'";
        $r = db_query($sql);

        if ($r) {
            // Update profile on shard
            prime_shard($user['shard_id']);
            db_query_shard($user['shard_id'], "UPDATE user_profile SET
                first_name = '" . sanitize($first_name, SQL) . "',
                last_name = '" . sanitize($last_name, SQL) . "'
                WHERE user_id = '$user_id'");

            $_SESSION['success'] = 'User updated successfully.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── DELETE USER ─────────────────────────────────────────────────────────────

if (($action ?? null) == 'deleteUser') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) { $errs['user_id'] = 'User ID required.'; }

    if (count($errs) <= 0) {
        if ($user_id == $_SESSION['user_id']) {
            $errs['self'] = 'You cannot delete your own account.';
        }
    }

    if (count($errs) <= 0) {
        $user = get_user($user_id);
        if (!$user) { $errs['user_id'] = 'User not found.'; }
    }

    if (count($errs) <= 0) {
        // Delete from shard
        prime_shard($user['shard_id']);
        db_query_shard($user['shard_id'], "DELETE FROM user_profile WHERE user_id = '$user_id'");

        // Delete related records from main DB
        db_query("DELETE FROM api_key WHERE user_id = '$user_id'");
        db_query("DELETE FROM forgot WHERE user_id = '$user_id'");
        db_query("DELETE FROM notification WHERE user_id = '$user_id'");
        db_query("DELETE FROM user WHERE user_id = '$user_id'");

        $_SESSION['success'] = 'User deleted successfully.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── CONFIRM USER (manual admin confirmation) ───────────────────────────────

if (($action ?? null) == 'confirmUser') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) { $errs['user_id'] = 'User ID required.'; }

    if (count($errs) <= 0) {
        db_query("UPDATE user SET is_confirmed = 1, confirm_hash = NULL WHERE user_id = '$user_id'");
        $_SESSION['success'] = 'User confirmed successfully.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

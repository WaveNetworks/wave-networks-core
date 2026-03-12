<?php
/**
 * Role Actions
 * Actions: assignRole, revokeRole
 */

// ─── ASSIGN ROLE ─────────────────────────────────────────────────────────────

if (($action ?? null) == 'assignRole') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $role    = $_POST['role'] ?? '';

    if (!$user_id)                                          { $errs['user_id'] = 'User ID required.'; }
    if (!in_set($role, ['admin', 'manager', 'employee']))   { $errs['role'] = 'Invalid role.'; }

    if (count($errs) <= 0) {
        $column = 'is_' . $role;
        db_query("UPDATE user SET $column = 1 WHERE user_id = '$user_id'");
        $_SESSION['success'] = ucfirst($role) . ' role assigned.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── REVOKE ROLE ─────────────────────────────────────────────────────────────

if (($action ?? null) == 'revokeRole') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $role    = $_POST['role'] ?? '';

    if (!$user_id)                                          { $errs['user_id'] = 'User ID required.'; }
    if (!in_set($role, ['admin', 'manager', 'employee']))   { $errs['role'] = 'Invalid role.'; }

    if (count($errs) <= 0) {
        $column = 'is_' . $role;
        db_query("UPDATE user SET $column = 0 WHERE user_id = '$user_id'");
        $_SESSION['success'] = ucfirst($role) . ' role revoked.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

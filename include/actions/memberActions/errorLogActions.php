<?php
/**
 * errorLogActions.php
 * AJAX actions for error log viewer. Admin-only.
 */

if (($_POST['action'] ?? '') == 'getErrorLogs') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = max(1, min(100, (int)($_POST['per_page'] ?? 50)));

        $filters = [];
        if (!empty($_POST['level']))      { $filters['level'] = $_POST['level']; }
        if (!empty($_POST['source_app'])) { $filters['source_app'] = $_POST['source_app']; }
        if (!empty($_POST['search']))     { $filters['search'] = $_POST['search']; }
        if (isset($_POST['status']) && $_POST['status'] !== '') { $filters['status'] = $_POST['status']; }

        $result = get_error_logs_paginated($page, $per_page, $filters);
        $data['items']   = $result['items'];
        $data['total']   = $result['total'];
        $data['stats']   = get_error_log_stats();
        $data['sources'] = get_error_log_sources();
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'deleteErrorLog') {
    $errs = array();
    if (!$_SESSION['user_id'])    { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))       { $errs['role'] = 'Admin access required.'; }
    if (empty($_POST['error_id'])) { $errs['id'] = 'Error ID required.'; }

    if (count($errs) <= 0) {
        $id = (int)$_POST['error_id'];
        db_query("DELETE FROM error_log WHERE error_id = '$id'");
        $_SESSION['success'] = 'Error log entry deleted.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'clearErrorLogs') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $days = max(1, (int)($_POST['older_than_days'] ?? 30));
        $deleted = clear_error_logs($days);
        $_SESSION['success'] = "Cleared $deleted error log entries older than $days days.";
        $data['deleted'] = $deleted;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'resolveErrorLog') {
    $errs = array();
    if (!$_SESSION['user_id'])     { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))        { $errs['role'] = 'Admin access required.'; }
    if (empty($_POST['error_id'])) { $errs['id'] = 'Error ID required.'; }

    if (count($errs) <= 0) {
        resolve_error_log((int)$_POST['error_id'], $_SESSION['user_id']);
        $_SESSION['success'] = 'Error marked as resolved.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'unresolveErrorLog') {
    $errs = array();
    if (!$_SESSION['user_id'])     { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))        { $errs['role'] = 'Admin access required.'; }
    if (empty($_POST['error_id'])) { $errs['id'] = 'Error ID required.'; }

    if (count($errs) <= 0) {
        unresolve_error_log((int)$_POST['error_id']);
        $_SESSION['success'] = 'Error reopened.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

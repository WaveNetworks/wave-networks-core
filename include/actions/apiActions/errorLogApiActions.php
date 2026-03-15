<?php
/**
 * errorLogApiActions.php
 * Public API actions for error log, authenticated via service API key (Bearer token).
 * Actions: apiGetErrorLogs, apiGetErrorLog, apiResolveErrorLog, apiUnresolveErrorLog, apiGetErrorStats
 */

// ---- LIST ERRORS ----
if (($action ?? null) == 'apiGetErrorLogs') {
    if (require_api_scope('error_log:read')) {
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = max(1, min(100, (int)($_POST['per_page'] ?? 50)));

        $filters = [];
        if (!empty($_POST['level']))      { $filters['level'] = $_POST['level']; }
        if (!empty($_POST['source_app'])) { $filters['source_app'] = $_POST['source_app']; }
        if (!empty($_POST['search']))     { $filters['search'] = $_POST['search']; }
        if (isset($_POST['status']) && $_POST['status'] !== '') { $filters['status'] = $_POST['status']; }

        $result = get_error_logs_paginated($page, $per_page, $filters);
        $data['items'] = $result['items'];
        $data['total'] = $result['total'];
        $_SESSION['success'] = 'OK';
    }
}

// ---- GET SINGLE ERROR ----
if (($action ?? null) == 'apiGetErrorLog') {
    $errs = array();
    if (!require_api_scope('error_log:read')) { /* error already set */ }
    elseif (empty($_POST['error_id'])) { $errs['id'] = 'error_id required.'; }

    if (empty($errs) && $_SERVICE_API_KEY) {
        $id = (int)$_POST['error_id'];
        $r = db_query("SELECT * FROM error_log WHERE error_id = '$id'");
        $row = $r ? db_fetch($r) : null;
        if ($row) {
            $data['error_log'] = $row;
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = 'Error log entry not found.';
        }
    } elseif (!empty($errs)) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ---- RESOLVE ----
if (($action ?? null) == 'apiResolveErrorLog') {
    $errs = array();
    if (!require_api_scope('error_log:write')) { /* error already set */ }
    elseif (empty($_POST['error_id'])) { $errs['id'] = 'error_id required.'; }

    if (empty($errs) && $_SERVICE_API_KEY) {
        resolve_error_log((int)$_POST['error_id'], (int)$_SERVICE_API_KEY['created_by']);
        $_SESSION['success'] = 'Error marked as resolved.';
    } elseif (!empty($errs)) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ---- UNRESOLVE ----
if (($action ?? null) == 'apiUnresolveErrorLog') {
    $errs = array();
    if (!require_api_scope('error_log:write')) { /* error already set */ }
    elseif (empty($_POST['error_id'])) { $errs['id'] = 'error_id required.'; }

    if (empty($errs) && $_SERVICE_API_KEY) {
        unresolve_error_log((int)$_POST['error_id']);
        $_SESSION['success'] = 'Error reopened.';
    } elseif (!empty($errs)) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ---- STATS ----
if (($action ?? null) == 'apiGetErrorStats') {
    if (require_api_scope('error_log:read')) {
        $data['stats']   = get_error_log_stats();
        $data['sources'] = get_error_log_sources();
        $_SESSION['success'] = 'OK';
    }
}

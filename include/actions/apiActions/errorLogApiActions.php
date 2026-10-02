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

    $reason = $_POST['resolution_reason'] ?? null;
    if ($reason !== null && $reason !== '' && !in_array($reason, ['fixed','already_fixed','cant_fix','noise','wont_fix'], true)) {
        $errs['reason'] = 'resolution_reason must be one of: fixed, already_fixed, cant_fix, noise, wont_fix.';
    }

    if (empty($errs) && $_SERVICE_API_KEY) {
        resolve_error_log(
            (int)$_POST['error_id'],
            (int)$_SERVICE_API_KEY['created_by'],
            ($reason === '' ? null : $reason),
            $_POST['resolution_notes'] ?? null,
            // A commit sha or task id. Required for a 'fixed' resolution to announce
            // an error_fixed event so affected users can be credited/notified.
            $_POST['resolution_ref'] ?? null
        );
        $_SESSION['success'] = 'Error marked as resolved.';
    } elseif (!empty($errs)) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ---- LIST FIXED-ERROR ANNOUNCEMENTS (for nokemo / child apps to credit users) ----
// Poll with a cursor: pass the highest event_id you've seen as since_event_id.
// Returns a privacy-safe projection — date + page + a ready-to-show message, plus
// the affected user ids to credit — and NEVER the stack trace, file, or line.
if (($action ?? null) == 'apiListFixedErrorsSince') {
    if (require_api_scope('error_log:read')) {
        $since      = max(0, (int)($_POST['since_event_id'] ?? 0));
        $source_app = (isset($_POST['source_app']) && $_POST['source_app'] !== '') ? $_POST['source_app'] : null;
        $limit      = max(1, min(500, (int)($_POST['limit'] ?? 100)));

        $events = get_fixed_error_events_since($since, $source_app, $limit);
        $data['events']     = $events;
        $data['count']      = count($events);
        $data['next_cursor'] = $events ? (int)end($events)['event_id'] : $since;
        $_SESSION['success'] = 'OK';
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

// ---- BULK RESOLVE (stale errors) ----
// Resolves all open errors whose last_seen_at is older than a threshold.
// Designed for cleanup: after fixes ship and errors stop recurring, this
// clears the backlog so the dashboard reflects reality.
if (($action ?? null) == 'apiBulkResolveStaleErrors') {
    if (require_api_scope('error_log:write')) {
        $hours = max(1, min(720, (int) ($_POST['older_than_hours'] ?? 48)));
        $resolved_by = sanitize($_POST['resolved_by'] ?? 'bulk-stale-cleanup', SQL);

        $r = db_query_prepared(
            "UPDATE error_log
             SET resolved_at = NOW(), resolved_by = ?
             WHERE resolved_at IS NULL
               AND last_seen_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$resolved_by, $hours]
        );

        $affected = $r ? $r->rowCount() : 0;
        $_SESSION['success'] = "Resolved {$affected} stale error(s) older than {$hours}h.";
        $data['resolved'] = $affected;
        $data['older_than_hours'] = $hours;
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

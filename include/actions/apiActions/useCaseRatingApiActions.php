<?php
/**
 * useCaseRatingApiActions.php  (API task #2252)
 *
 * Bearer-token API over the use-case health & rating data:
 *   apiListUseCaseRatings        — ratings for a use case / app (scope actions:read)
 *   apiGetUseCaseHealth          — computed health for a use case (scope actions:read)
 *   apiListUseCaseRegressionsSince — regression event feed nokemo's monitoring cron
 *                                   polls to file a fix task (scope monitoring:read,
 *                                   falls back to actions:read)
 *   apiAckUseCaseRegression      — mark a regression event acknowledged once the
 *                                   task is filed (scope monitoring:write / tests:write)
 *   apiRecomputeUseCaseHealth    — recompute + regression-check (scope tests:write)
 */

// ── List ratings ──────────────────────────────────────────────────────────────
if (($action ?? null) == 'apiListUseCaseRatings') {
    if (require_api_scope('actions:read')) {
        $ucid  = (int)($_POST['use_case_id'] ?? 0);
        $app   = trim((string)($_POST['source_app'] ?? ''));
        $build = trim((string)($_POST['app_build'] ?? ''));
        $limit = max(1, min(500, (int)($_POST['limit'] ?? 100)));

        $where = [];
        $args  = [];
        if ($ucid > 0)   { $where[] = 'use_case_id = ?'; $args[] = $ucid; }
        if ($app !== '') { $where[] = 'source_app = ?';  $args[] = $app; }
        if (isset($_POST['app_build'])) { $where[] = 'app_build = ?'; $args[] = $build; }
        $sql_where = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $r = db_query_prepared(
            "SELECT rating_row_id, use_case_id, source_app, user_id, rating, comment,
                    app_build, platform, created, updated
             FROM use_case_rating $sql_where
             ORDER BY created DESC LIMIT $limit",
            $args
        );
        $items = [];
        while ($r && ($row = db_fetch($r))) { $row['rating'] = (int)$row['rating']; $items[] = $row; }

        // Per-build rollup when a single use case was asked for.
        if ($ucid > 0 && function_exists('uc_rating_builds')) {
            $data['builds'] = uc_rating_builds($ucid);
        }
        $data['items'] = $items;
        $data['count'] = count($items);
        $_SESSION['success'] = 'OK';
    }
}

// ── Computed health for one use case ──────────────────────────────────────────
if (($action ?? null) == 'apiGetUseCaseHealth') {
    if (require_api_scope('actions:read')) {
        $ucid = (int)($_POST['use_case_id'] ?? 0);
        if ($ucid <= 0) {
            $_SESSION['error'] = 'use_case_id is required.';
        } else {
            $data['health'] = uc_compute_health($ucid);
            if ($data['health'] === null) {
                $_SESSION['error'] = 'Use case not found.';
            } else {
                $data['comments'] = uc_recent_comments($ucid, 10);
                $_SESSION['success'] = 'OK';
            }
        }
    }
}

// ── Regression feed for nokemo's monitoring cron ──────────────────────────────
// Prefer monitoring:read (nokemo's monitoring key), fall back to actions:read.
if (($action ?? null) == 'apiListUseCaseRegressionsSince') {
    $has = false;
    $key = $_SERVICE_API_KEY ?? null;
    $scopes = $key ? (json_decode($key['scopes'] ?? '[]', true) ?: []) : [];
    if (in_array('monitoring:read', $scopes, true) || in_array('actions:read', $scopes, true)) {
        $has = true;
    }
    if (!$has) { $has = require_api_scope('monitoring:read'); }
    if ($has) {
        $since = (int)($_POST['since_event_id'] ?? 0);
        $app   = trim((string)($_POST['source_app'] ?? ''));
        $limit = max(1, min(500, (int)($_POST['limit'] ?? 100)));
        $only_unack = !empty($_POST['unacknowledged_only']);

        $events = uc_regression_events_since($since, $app !== '' ? $app : null, $limit);
        if ($only_unack) {
            $events = array_values(array_filter($events, function ($e) {
                return (int)($e['acknowledged'] ?? 0) === 0;
            }));
        }
        $data['events']      = $events;
        $data['count']       = count($events);
        $data['next_cursor'] = $events ? (int)end($events)['event_id'] : $since;
        $_SESSION['success'] = 'OK';
    }
}

// ── Acknowledge a regression event (once the fix task is filed) ───────────────
if (($action ?? null) == 'apiAckUseCaseRegression') {
    $key = $_SERVICE_API_KEY ?? null;
    $scopes = $key ? (json_decode($key['scopes'] ?? '[]', true) ?: []) : [];
    $ok = in_array('monitoring:write', $scopes, true) || in_array('tests:write', $scopes, true);
    if (!$ok) { $ok = require_api_scope('monitoring:write'); }
    if ($ok) {
        $eid = (int)($_POST['event_id'] ?? 0);
        if ($eid <= 0) {
            $_SESSION['error'] = 'event_id is required.';
        } else {
            db_query_prepared(
                "UPDATE use_case_regression_event SET acknowledged = 1 WHERE event_id = ?",
                [$eid]
            );
            $data['event_id'] = $eid;
            $_SESSION['success'] = 'OK';
        }
    }
}

// ── Recompute health + regression for one or all use cases ────────────────────
if (($action ?? null) == 'apiRecomputeUseCaseHealth') {
    if (require_api_scope('tests:write')) {
        $ucid = (int)($_POST['use_case_id'] ?? 0);
        $app  = trim((string)($_POST['source_app'] ?? ''));
        $ids  = [];
        if ($ucid > 0) {
            $ids = [$ucid];
        } else {
            $sql = "SELECT use_case_id FROM use_case";
            $args = [];
            if ($app !== '') { $sql .= " WHERE source_app = ?"; $args[] = $app; }
            $sql .= " LIMIT 2000";
            $r = db_query_prepared($sql, $args);
            while ($r && ($row = db_fetch($r))) { $ids[] = (int)$row['use_case_id']; }
        }
        $flagged = 0;
        foreach ($ids as $id) {
            uc_compute_health($id);
            if (uc_detect_regression($id)) { $flagged++; }
        }
        $data['recomputed'] = count($ids);
        $data['flagged']    = $flagged;
        $_SESSION['success'] = 'OK';
    }
}

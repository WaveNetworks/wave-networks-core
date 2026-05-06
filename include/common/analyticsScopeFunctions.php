<?php
/**
 * analyticsScopeFunctions.php
 * Generic admin-core analytics primitives shared by the Overview / Activity /
 * Cohorts pages and any future analytics views.
 *
 * Provides:
 *   - get_visible_user_scope($admin_user_id)  role-based user-id filter
 *   - is_analytics_visible($target_user_id)   privacy gate (per-user opt-out)
 *   - register_milestone_event($action, $page = null)
 *   - get_milestone_events()
 *   - register_cohort_segment($slug, $label, callable $resolver)
 *   - get_cohort_segments()
 *   - set_analytics_company_resolver(callable)
 *   - set_analytics_coached_users_resolver(callable)
 *
 * Child apps wire their domain-specific concepts (companies, coaches, key
 * activation events, cohort splits) via the register_* and set_*_resolver
 * hooks during bootstrap. The admin core does NOT know about elevateHER
 * or any other child app.
 */

if (!isset($GLOBALS['_analytics_milestone_events']))    { $GLOBALS['_analytics_milestone_events'] = []; }
if (!isset($GLOBALS['_analytics_cohort_segments']))     { $GLOBALS['_analytics_cohort_segments'] = []; }
if (!isset($GLOBALS['_analytics_company_resolver']))    { $GLOBALS['_analytics_company_resolver'] = null; }
if (!isset($GLOBALS['_analytics_coached_resolver']))    { $GLOBALS['_analytics_coached_resolver'] = null; }

/**
 * Return the set of user_ids the given admin is allowed to see in analytics.
 *
 * Output shape:
 *   [
 *     'type'       => 'all' | 'company' | 'coached' | 'self' | 'none',
 *     'user_ids'   => array of user_ids when bounded, NULL when type=='all'
 *     'sql_filter' => 'user.user_id IN (...)' or '1=1' for use in WHERE clauses
 *     'label'      => human-readable scope label
 *   ]
 *
 * Role mapping (against admin/include/common/sessionFunctions.php flags):
 *   is_owner            super-admin / all users
 *   is_admin            company-admin / company peers (hook resolves)
 *   is_manager          producer/coach / coached users (hook resolves)
 *   is_employee or none end user / self only
 */
function get_visible_user_scope($admin_user_id) {
    $admin_user_id = (int)$admin_user_id;
    if ($admin_user_id <= 0) {
        return ['type' => 'none', 'user_ids' => [], 'sql_filter' => '0=1', 'label' => 'No access'];
    }

    if (!empty($_SESSION['is_owner'])) {
        return ['type' => 'all', 'user_ids' => null, 'sql_filter' => '1=1', 'label' => 'All users'];
    }

    if (!empty($_SESSION['is_admin'])) {
        $ids = _resolve_company_user_ids($admin_user_id);
        return [
            'type'       => 'company',
            'user_ids'   => $ids,
            'sql_filter' => _scope_ids_to_sql($ids),
            'label'      => 'Company users',
        ];
    }

    if (!empty($_SESSION['is_manager'])) {
        $ids = _resolve_coached_user_ids($admin_user_id);
        // Producers always see themselves at minimum
        if (!in_array($admin_user_id, $ids, true)) { $ids[] = $admin_user_id; }
        return [
            'type'       => 'coached',
            'user_ids'   => $ids,
            'sql_filter' => _scope_ids_to_sql($ids),
            'label'      => 'Coached users',
        ];
    }

    // End user — self only
    $ids = [$admin_user_id];
    return [
        'type'       => 'self',
        'user_ids'   => $ids,
        'sql_filter' => _scope_ids_to_sql($ids),
        'label'      => 'You',
    ];
}

/**
 * Return TRUE if it's OK to show row-level analytics about $target_user_id.
 * Honours the cookie_analytics consent type seeded by GDPR migration 2.5.
 * Default-open (no recorded consent row counts as opt-in, matching the
 * admin core's existing tracking posture). An explicit 'withdrawn' row
 * suppresses drill-down.
 */
function is_analytics_visible($target_user_id) {
    $target_user_id = (int)$target_user_id;
    if ($target_user_id <= 0) return false;

    if (function_exists('get_consent_status')) {
        $status = get_consent_status($target_user_id, 'cookie_analytics');
        if ($status === 'withdrawn') return false;
    }
    return true;
}

/**
 * Child apps register the actions/pages that count as "key activation events"
 * (used by Overview activation rate + 30/90-day milestone counts).
 *   register_milestone_event('completeOnboarding')
 *   register_milestone_event(null, 'first_workout')   // page-only
 */
function register_milestone_event($action, $page = null, $label = null) {
    $GLOBALS['_analytics_milestone_events'][] = [
        'action' => $action,
        'page'   => $page,
        'label'  => $label ?: ($action ?: $page),
    ];
}

function get_milestone_events() {
    return $GLOBALS['_analytics_milestone_events'];
}

/**
 * Cohort comparison segments (toggleable filter on the cohort heatmap).
 * Resolver is called with no args, returns a list of user_ids matching the segment.
 */
function register_cohort_segment($slug, $label, $resolver) {
    if (!is_callable($resolver)) return;
    $GLOBALS['_analytics_cohort_segments'][$slug] = ['label' => $label, 'resolver' => $resolver];
}

function get_cohort_segments() {
    return $GLOBALS['_analytics_cohort_segments'];
}

/**
 * Hooks for company/coach user-id resolution (defined in child app bootstrap).
 * Resolver signature: function($admin_user_id) : int[]
 */
function set_analytics_company_resolver($callable) {
    if (is_callable($callable)) $GLOBALS['_analytics_company_resolver'] = $callable;
}

function set_analytics_coached_users_resolver($callable) {
    if (is_callable($callable)) $GLOBALS['_analytics_coached_resolver'] = $callable;
}

// ── Internal helpers ──────────────────────────────────────────

function _resolve_company_user_ids($admin_user_id) {
    $cb = $GLOBALS['_analytics_company_resolver'];
    if (is_callable($cb)) {
        $ids = $cb($admin_user_id);
        if (is_array($ids)) return array_values(array_unique(array_map('intval', $ids)));
    }
    // Default fallback: just self. Child apps with company context override.
    return [(int)$admin_user_id];
}

function _resolve_coached_user_ids($admin_user_id) {
    $cb = $GLOBALS['_analytics_coached_resolver'];
    if (is_callable($cb)) {
        $ids = $cb($admin_user_id);
        if (is_array($ids)) return array_values(array_unique(array_map('intval', $ids)));
    }
    return [(int)$admin_user_id];
}

function _scope_ids_to_sql($ids) {
    if (!is_array($ids) || count($ids) === 0) return '0=1';
    $clean = array_map('intval', $ids);
    return 'user.user_id IN (' . implode(',', $clean) . ')';
}

/**
 * Convenience: enumerate all configured shard ids. Used by analytics
 * queries that must visit per-user tables (user_action_summary).
 */
function get_all_shard_ids() {
    global $shardConfigs;
    if (!is_array($shardConfigs)) return [];
    return array_keys($shardConfigs);
}

/**
 * Group the user_ids of the visible scope by shard_id so callers can
 * issue one query per shard. Returns ['shard_id' => [user_id, ...], ...].
 *
 * Pass NULL user_ids (type='all') to mean "every user on every shard" —
 * caller should still bound by other filters (e.g. created_date).
 */
function group_scope_user_ids_by_shard($user_ids) {
    global $db;
    if ($user_ids === null) {
        // Pull all user_ids grouped by shard
        $r = db_query("SELECT user_id, shard_id FROM user");
    } else {
        if (count($user_ids) === 0) return [];
        $clean = array_map('intval', $user_ids);
        $r = db_query("SELECT user_id, shard_id FROM user WHERE user_id IN (" . implode(',', $clean) . ")");
    }
    $out = [];
    while ($row = db_fetch($r)) {
        $sid = $row['shard_id'];
        if (!isset($out[$sid])) $out[$sid] = [];
        $out[$sid][] = (int)$row['user_id'];
    }
    return $out;
}

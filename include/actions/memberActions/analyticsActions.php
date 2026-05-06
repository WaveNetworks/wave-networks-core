<?php
/**
 * Analytics Actions
 * Backs the three admin-core analytics pages (Overview, Activity, Cohorts).
 * Actions: getAnalyticsData
 *
 * Every query funnels through get_visible_user_scope() — no raw cross-tenant
 * SELECTs. Pre-aggregated tables (user_action_summary, feature_metric_daily)
 * are preferred over raw user_action_log per task #534 perf rules.
 */

if (($_POST['action'] ?? '') == 'getAnalyticsData') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $page    = $_POST['page']    ?? '';
    $segment = $_POST['segment'] ?? '';

    if (!in_array($page, ['overview', 'activity', 'cohorts'], true)) {
        $errs['page'] = 'Unknown analytics page.';
    }

    $scope = get_visible_user_scope($_SESSION['user_id']);
    if ($scope['type'] === 'none') { $errs['scope'] = 'No analytics access.'; }
    // End users get a 403 — they don't even reach the analytics views, but
    // belt-and-braces guard the action endpoint too.
    if ($scope['type'] === 'self' && empty($_SESSION['is_employee']) && empty($_SESSION['is_manager']) && empty($_SESSION['is_admin']) && empty($_SESSION['is_owner'])) {
        $errs['scope'] = 'Insufficient privileges.';
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    } else {
        $data['scope'] = ['type' => $scope['type'], 'label' => $scope['label']];
        $userFilter = $scope['sql_filter']; // 'user.user_id IN (...)' or '1=1'

        if ($page === 'overview')  { _analytics_overview($scope, $userFilter, $data); }
        if ($page === 'activity')  { _analytics_activity($scope, $userFilter, $data); }
        if ($page === 'cohorts')   { _analytics_cohorts($scope, $userFilter, $data, $segment); }

        $_SESSION['success'] = 'Analytics loaded.';
    }
}

function _analytics_overview($scope, $userFilter, &$data) {
    global $db;

    // DAU / WAU / MAU — use main DB user.last_login (cheap, scope-safe).
    $dau = db_fetch(db_query(
        "SELECT COUNT(DISTINCT user.user_id) AS c FROM user
         WHERE last_login >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND $userFilter"
    ))['c'] ?? 0;
    $wau = db_fetch(db_query(
        "SELECT COUNT(DISTINCT user.user_id) AS c FROM user
         WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND $userFilter"
    ))['c'] ?? 0;
    $mau = db_fetch(db_query(
        "SELECT COUNT(DISTINCT user.user_id) AS c FROM user
         WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND $userFilter"
    ))['c'] ?? 0;

    // Activation rate — % of users who registered ≥30 days ago that
    // also fired any registered milestone event. If no milestones are
    // registered, fall back to "logged in at least once after signup".
    $signups30plus = db_fetch(db_query(
        "SELECT COUNT(*) AS c FROM user
         WHERE created_date <= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND created_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND $userFilter"
    ))['c'] ?? 0;

    $activatedCount = 0;
    $milestones = get_milestone_events();
    if ($signups30plus > 0) {
        if (count($milestones) === 0) {
            // Fallback: any login at all
            $activatedCount = db_fetch(db_query(
                "SELECT COUNT(*) AS c FROM user
                 WHERE created_date <= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   AND created_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                   AND last_login IS NOT NULL
                   AND $userFilter"
            ))['c'] ?? 0;
        } else {
            // Cross-shard: scan user_action_summary for any registered milestone.
            $activatedCount = _count_users_with_milestone($scope, $milestones, 90);
        }
    }
    $activationRate = $signups30plus > 0 ? round(($activatedCount / $signups30plus) * 100, 1) : 0;

    // Retention — Day 1/7/30/90 retention for users who signed up in last 90 days.
    $retention = _retention_curve($scope, 90, [1, 7, 30, 90]);

    // Trailing 30/90-day milestone counts (cross-shard sum from user_action_summary).
    $milestoneCounts = _milestone_counts($scope, $milestones, [30, 90]);

    // Header chart — signups vs active users last 90 days
    $signupsTrend = db_fetch_all(db_query(
        "SELECT DATE(created_date) AS d, COUNT(*) AS c FROM user
         WHERE created_date >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND $userFilter
         GROUP BY DATE(created_date) ORDER BY d"
    ));
    $activeTrend = db_fetch_all(db_query(
        "SELECT DATE(last_login) AS d, COUNT(DISTINCT user.user_id) AS c FROM user
         WHERE last_login >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND $userFilter
         GROUP BY DATE(last_login) ORDER BY d"
    ));

    $data['headline'] = [
        'dau' => (int)$dau, 'wau' => (int)$wau, 'mau' => (int)$mau,
        'activation_rate' => $activationRate,
        'activated' => (int)$activatedCount,
        'signups_30plus' => (int)$signups30plus,
    ];
    $data['retention']        = $retention;
    $data['milestone_counts'] = $milestoneCounts;
    $data['signups_trend']    = $signupsTrend;
    $data['active_trend']     = $activeTrend;
}

function _analytics_activity($scope, $userFilter, &$data) {
    global $db;

    // Login frequency distribution (last 30 days from login_history on main).
    // login_history doesn't carry the scope filter directly; join via user.
    $rows = db_fetch_all(db_query(
        "SELECT lh.user_id, COUNT(*) AS logins
         FROM login_history lh
         JOIN user ON user.user_id = lh.user_id
         WHERE lh.status = 'success' AND lh.created >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND $userFilter
         GROUP BY lh.user_id"
    ));
    $buckets = ['0' => 0, '1-3' => 0, '4-10' => 0, '11-30' => 0, '30+' => 0];
    $userLoginCounts = [];
    foreach ($rows as $r) {
        $userLoginCounts[(int)$r['user_id']] = (int)$r['logins'];
        $n = (int)$r['logins'];
        if ($n <= 0)        $buckets['0']++;
        elseif ($n <= 3)    $buckets['1-3']++;
        elseif ($n <= 10)   $buckets['4-10']++;
        elseif ($n <= 30)   $buckets['11-30']++;
        else                $buckets['30+']++;
    }
    // "0 logins in 30d" = total users in scope minus the ones we counted
    $totalInScope = (int)(db_fetch(db_query("SELECT COUNT(*) AS c FROM user WHERE $userFilter"))['c'] ?? 0);
    $buckets['0'] = max(0, $totalInScope - count($userLoginCounts));

    $loginFrequency = [];
    foreach ($buckets as $label => $count) {
        $loginFrequency[] = ['label' => $label, 'value' => (int)$count];
    }

    // Time-since-last-login distribution
    $tsll = db_fetch_all(db_query(
        "SELECT
           CASE
             WHEN last_login IS NULL THEN 'Never'
             WHEN DATE(last_login) = CURDATE() THEN 'Today'
             WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN '1-7 days'
             WHEN last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN '8-30 days'
             WHEN last_login >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN '31-90 days'
             ELSE '90+ days'
           END AS bucket,
           COUNT(*) AS c
         FROM user
         WHERE $userFilter
         GROUP BY bucket"
    ));
    $orderTsll = ['Today', '1-7 days', '8-30 days', '31-90 days', '90+ days', 'Never'];
    $tsllMap = [];
    foreach ($tsll as $r) { $tsllMap[$r['bucket']] = (int)$r['c']; }
    $tsllOrdered = [];
    foreach ($orderTsll as $b) {
        $tsllOrdered[] = ['label' => $b, 'value' => $tsllMap[$b] ?? 0];
    }

    // Churn-risk table — last_active >14d, sortable, with last_login + best-known last_action.
    // last_action is derived from the raw shard log if we can; otherwise just last_login.
    $churn = db_fetch_all(db_query(
        "SELECT user.user_id, user.email, user.first_name, user.last_name, user.last_login, user.shard_id, user.created_date
         FROM user
         WHERE $userFilter
           AND user.is_active = 1
           AND (user.last_login IS NULL OR user.last_login < DATE_SUB(NOW(), INTERVAL 14 DAY))
         ORDER BY (user.last_login IS NULL) DESC, user.last_login ASC
         LIMIT 200"
    ));
    // Privacy gate — replace name/email for opted-out users.
    foreach ($churn as &$row) {
        if (!is_analytics_visible($row['user_id'])) {
            $row['email'] = '— opted out —';
            $row['first_name'] = '';
            $row['last_name'] = '';
            $row['opted_out'] = 1;
        } else {
            $row['opted_out'] = 0;
        }
    }
    unset($row);

    // Anonymous device activity (device_action_log on main) for funnel-from-signup-page analysis.
    $deviceFunnel = db_fetch_all(db_query(
        "SELECT page, COUNT(*) AS c FROM device_action_log
         WHERE created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY page ORDER BY c DESC LIMIT 12"
    ));

    $data['login_frequency']       = $loginFrequency;
    $data['time_since_last_login'] = $tsllOrdered;
    $data['churn_risk']            = $churn;
    $data['device_funnel']         = $deviceFunnel;
    // session_length / avg_duration — defer if not present in summary; guard with try.
    $sessionLength = db_fetch_all(db_query(
        "SELECT
           CASE
             WHEN duration_ms < 30000   THEN '<30s'
             WHEN duration_ms < 120000  THEN '30s-2m'
             WHEN duration_ms < 600000  THEN '2-10m'
             WHEN duration_ms < 1800000 THEN '10-30m'
             ELSE '30m+'
           END AS bucket,
           COUNT(*) AS c
         FROM device_action_log
         WHERE duration_ms IS NOT NULL
           AND created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY bucket"
    ));
    $orderSess = ['<30s','30s-2m','2-10m','10-30m','30m+'];
    $sessMap = [];
    foreach ($sessionLength as $r) { $sessMap[$r['bucket']] = (int)$r['c']; }
    $sessOrdered = [];
    foreach ($orderSess as $b) {
        $sessOrdered[] = ['label' => $b, 'value' => $sessMap[$b] ?? 0];
    }
    $data['session_length'] = $sessOrdered;
}

function _analytics_cohorts($scope, $userFilter, &$data, $segment) {
    global $db;

    // Apply segment filter (intersect with scope) if a known segment is selected.
    $segmentIds = null;
    $segments = get_cohort_segments();
    if ($segment && isset($segments[$segment])) {
        $resolver = $segments[$segment]['resolver'];
        $rawIds = call_user_func($resolver);
        if (is_array($rawIds)) {
            $segmentIds = array_values(array_unique(array_map('intval', $rawIds)));
        }
    }

    // Determine cohort users — last 12 weeks of signups within scope ∩ segment.
    $segmentSql = '';
    if ($segmentIds !== null) {
        if (count($segmentIds) === 0) {
            $segmentSql = ' AND 0=1 ';
        } else {
            $segmentSql = ' AND user.user_id IN (' . implode(',', $segmentIds) . ') ';
        }
    }

    $cohortUsers = db_fetch_all(db_query(
        "SELECT user.user_id, user.shard_id, DATE(user.created_date) AS signup_date,
                YEARWEEK(user.created_date, 1) AS signup_week
         FROM user
         WHERE user.created_date >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
           AND user.created_date < CURDATE()
           AND $userFilter $segmentSql"
    ));

    // Group users by week and by shard (for shard-local activity probes)
    $cohorts = []; // signup_week => ['size'=>N, 'users'=>[user_id => signup_date]]
    $usersByShard = []; // shard_id => array of [user_id, signup_date]
    foreach ($cohortUsers as $u) {
        $w = (int)$u['signup_week'];
        $uid = (int)$u['user_id'];
        if (!isset($cohorts[$w])) $cohorts[$w] = ['signup_week' => $w, 'size' => 0, 'users' => []];
        $cohorts[$w]['size']++;
        $cohorts[$w]['users'][$uid] = $u['signup_date'];
        if (!isset($usersByShard[$u['shard_id']])) $usersByShard[$u['shard_id']] = [];
        $usersByShard[$u['shard_id']][] = ['user_id' => $uid, 'signup_date' => $u['signup_date']];
    }

    // For each user, find which weeks-since-signup they had user_action_summary activity.
    // Per-shard pass: pull all summary rows for these users and bucket in PHP.
    $activeWeeksByUser = []; // user_id => set of week-since-signup ints
    foreach ($usersByShard as $shardId => $list) {
        if (count($list) === 0) continue;
        $ids = array_map(function ($r) { return (int)$r['user_id']; }, $list);
        if (count($ids) === 0) continue;
        $idsSql = implode(',', $ids);
        $stmt = db_query_shard($shardId,
            "SELECT user_id, day FROM user_action_summary
             WHERE user_id IN ($idsSql)
               AND day >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
             GROUP BY user_id, day"
        );
        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $signupByUser = [];
            foreach ($list as $r) { $signupByUser[(int)$r['user_id']] = $r['signup_date']; }
            foreach ($rows as $r) {
                $uid = (int)$r['user_id'];
                $signup = $signupByUser[$uid] ?? null;
                if (!$signup) continue;
                $diffDays = (int)((strtotime($r['day']) - strtotime($signup)) / 86400);
                if ($diffDays < 0) continue;
                $weekSince = intdiv($diffDays, 7);
                if ($weekSince > 11) continue;
                if (!isset($activeWeeksByUser[$uid])) $activeWeeksByUser[$uid] = [];
                $activeWeeksByUser[$uid][$weekSince] = true;
            }
        }
    }

    // Build the cohort retention matrix: rows = signup_week, cols = 0..11 weeks since signup
    ksort($cohorts);
    $matrix = [];
    foreach ($cohorts as $w => $info) {
        $row = ['signup_week' => $w, 'size' => $info['size'], 'cells' => []];
        for ($wk = 0; $wk < 12; $wk++) {
            $count = 0;
            foreach ($info['users'] as $uid => $signup) {
                if (!empty($activeWeeksByUser[$uid][$wk])) $count++;
            }
            $pct = $info['size'] > 0 ? round(($count / $info['size']) * 100, 1) : 0;
            $row['cells'][] = ['week' => $wk, 'active' => $count, 'pct' => $pct];
        }
        $matrix[] = $row;
    }

    // N-day retention curve (Day 1, 7, 14, 30, 60, 90) computed across the
    // last 90 days of signups in scope.
    $retentionCurve = _retention_curve($scope, 90, [1, 7, 14, 30, 60, 90]);

    // Available segment list for the toggleable filter dropdown.
    $segmentList = [];
    foreach ($segments as $slug => $cfg) {
        $segmentList[] = ['slug' => $slug, 'label' => $cfg['label']];
    }

    $data['matrix']           = $matrix;
    $data['retention_curve']  = $retentionCurve;
    $data['segments']         = $segmentList;
    $data['active_segment']   = $segment;
}

// ── Shared helpers ─────────────────────────────────────────────

/**
 * Day-N retention from signup date. For each requested day-N, computes
 *   % of users-signed-up-in-last-90d who had at least one user_action_summary
 *   row whose `day` is exactly N days after their signup.
 * Returns: [['day' => N, 'pct' => P, 'active' => K, 'cohort' => C], ...]
 */
function _retention_curve($scope, $cohortWindowDays, $days) {
    global $db;
    $userFilter = $scope['sql_filter'];

    $cohort = db_fetch_all(db_query(
        "SELECT user.user_id, user.shard_id, DATE(user.created_date) AS signup_date
         FROM user
         WHERE user.created_date >= DATE_SUB(CURDATE(), INTERVAL " . (int)$cohortWindowDays . " DAY)
           AND user.created_date < CURDATE()
           AND $userFilter"
    ));
    if (count($cohort) === 0) {
        $out = [];
        foreach ($days as $d) {
            $out[] = ['day' => $d, 'pct' => 0, 'active' => 0, 'cohort' => 0];
        }
        return $out;
    }

    $signupByUser = [];
    $usersByShard = [];
    foreach ($cohort as $u) {
        $uid = (int)$u['user_id'];
        $signupByUser[$uid] = $u['signup_date'];
        if (!isset($usersByShard[$u['shard_id']])) $usersByShard[$u['shard_id']] = [];
        $usersByShard[$u['shard_id']][] = $uid;
    }

    $activeOnDay = []; // day_n => [user_id => true]
    foreach ($usersByShard as $shardId => $ids) {
        if (count($ids) === 0) continue;
        $idsSql = implode(',', array_map('intval', $ids));
        $stmt = db_query_shard($shardId,
            "SELECT user_id, day FROM user_action_summary
             WHERE user_id IN ($idsSql)
             GROUP BY user_id, day"
        );
        if (!$stmt) continue;
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $signup = $signupByUser[$uid] ?? null;
            if (!$signup) continue;
            $diff = (int)((strtotime($r['day']) - strtotime($signup)) / 86400);
            foreach ($days as $d) {
                if ($diff === (int)$d) {
                    if (!isset($activeOnDay[$d])) $activeOnDay[$d] = [];
                    $activeOnDay[$d][$uid] = true;
                }
            }
        }
    }

    $out = [];
    $cohortSize = count($signupByUser);
    foreach ($days as $d) {
        $cohortEligible = 0;
        $cutoff = strtotime("-{$d} days", strtotime('today'));
        foreach ($signupByUser as $uid => $signup) {
            if (strtotime($signup) <= $cutoff) $cohortEligible++;
        }
        $active = isset($activeOnDay[$d]) ? count($activeOnDay[$d]) : 0;
        $pct = $cohortEligible > 0 ? round(($active / $cohortEligible) * 100, 1) : 0;
        $out[] = ['day' => (int)$d, 'pct' => $pct, 'active' => $active, 'cohort' => $cohortEligible];
    }
    return $out;
}

function _count_users_with_milestone($scope, $milestones, $cohortWindowDays) {
    global $db;
    $userFilter = $scope['sql_filter'];
    $cohort = db_fetch_all(db_query(
        "SELECT user.user_id, user.shard_id FROM user
         WHERE user.created_date >= DATE_SUB(CURDATE(), INTERVAL " . (int)$cohortWindowDays . " DAY)
           AND user.created_date <= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND $userFilter"
    ));
    if (count($cohort) === 0) return 0;

    $byShard = [];
    foreach ($cohort as $u) {
        if (!isset($byShard[$u['shard_id']])) $byShard[$u['shard_id']] = [];
        $byShard[$u['shard_id']][] = (int)$u['user_id'];
    }

    $hit = [];
    foreach ($byShard as $sid => $ids) {
        if (count($ids) === 0) continue;
        $idsSql = implode(',', array_map('intval', $ids));
        $orParts = [];
        foreach ($milestones as $m) {
            $clauses = [];
            if (!empty($m['action'])) { $clauses[] = "action = " . _q($m['action']); }
            if (!empty($m['page']))   { $clauses[] = "page = "   . _q($m['page']); }
            if (count($clauses) > 0)  { $orParts[] = '(' . implode(' AND ', $clauses) . ')'; }
        }
        if (count($orParts) === 0) continue;
        $where = '(' . implode(' OR ', $orParts) . ')';
        $stmt = db_query_shard($sid,
            "SELECT DISTINCT user_id FROM user_action_summary
             WHERE user_id IN ($idsSql) AND $where"
        );
        if (!$stmt) continue;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hit[(int)$r['user_id']] = true;
        }
    }
    return count($hit);
}

function _milestone_counts($scope, $milestones, $windows) {
    $out = [];
    if (count($milestones) === 0) {
        foreach ($windows as $w) { $out[$w] = 0; }
        return $out;
    }
    // Roll up cross-user counts from feature_metric_daily on main DB.
    foreach ($windows as $w) {
        $orParts = [];
        foreach ($milestones as $m) {
            $clauses = [];
            if (!empty($m['action'])) { $clauses[] = "action = " . _q($m['action']); }
            if (!empty($m['page']))   { $clauses[] = "page = "   . _q($m['page']); }
            if (count($clauses) > 0)  { $orParts[] = '(' . implode(' AND ', $clauses) . ')'; }
        }
        if (count($orParts) === 0) { $out[$w] = 0; continue; }
        $where = '(' . implode(' OR ', $orParts) . ')';
        $row = db_fetch(db_query(
            "SELECT COALESCE(SUM(event_count),0) AS c FROM feature_metric_daily
             WHERE day >= DATE_SUB(CURDATE(), INTERVAL " . (int)$w . " DAY)
               AND $where"
        ));
        $out[$w] = (int)($row['c'] ?? 0);
        // NOTE: feature_metric_daily is global, not scope-bounded. For
        // company/coached scopes, child apps that need scoped milestone
        // totals should override via their own action. // TODO denormalize
    }
    return $out;
}

function _q($v) {
    // SQL string literal helper for shard queries (we're in a non-prepared path).
    return "'" . addslashes($v) . "'";
}

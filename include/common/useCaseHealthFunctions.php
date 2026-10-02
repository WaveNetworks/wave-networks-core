<?php
/**
 * useCaseHealthFunctions.php  (API task #2252)
 *
 * "Use case health" per build, from three signals already captured on every
 * app's admin:
 *   - latest automated test status      (use_case_test_run / use_case.test_status)
 *   - error rate in the flow            (error_log + error_occurrence_user, #2249)
 *   - average rating and count          (use_case_rating, this migration)
 *
 * Plus the in-flow rating-prompt budget (shared with any future survey via
 * engagement_prompt_log) and the regression detector that flags a use case
 * 'needs attention' and queues a use_case_regression_event for nokemo to poll.
 *
 * All tables live on the admin MAIN DB. These helpers are glob-included and so
 * are available to every child app for free (the in-flow prompt + rating path)
 * and to the admin UI + the Bearer API.
 */

// Regression thresholds — the margin a rating must drop, and the minimum number
// of ratings before a drop counts (noise guard), and the error-rate rise ratio.
if (!defined('UC_REGRESSION_RATING_DROP'))  define('UC_REGRESSION_RATING_DROP', 0.75);
if (!defined('UC_REGRESSION_MIN_RATINGS'))  define('UC_REGRESSION_MIN_RATINGS', 5);
if (!defined('UC_REGRESSION_ERROR_RISE'))   define('UC_REGRESSION_ERROR_RISE', 1.5);
// Daily in-flow prompt cap per user (shared across prompt kinds).
if (!defined('UC_PROMPT_DAILY_CAP'))        define('UC_PROMPT_DAILY_CAP', 1);

/**
 * Pages that make up a use case's flow — starting_page + every page named in the
 * action_path + (best effort) the ending_action. error_log records the ?page=
 * view, so error matching is page-based.
 * @return string[] distinct non-empty page names
 */
function uc_flow_pages(array $uc): array {
    $pages = [];
    if (!empty($uc['starting_page'])) { $pages[] = (string)$uc['starting_page']; }
    $path = $uc['action_path'] ?? null;
    if (is_string($path)) { $path = json_decode($path, true); }
    if (is_array($path)) {
        foreach ($path as $step) {
            if (!empty($step['page'])) { $pages[] = (string)$step['page']; }
        }
    }
    $pages = array_values(array_unique(array_filter($pages, 'strlen')));
    return $pages;
}

/**
 * Error rate for a use case's flow: distinct users who hit an (open) error on
 * one of the flow's pages, counted across #2249's error_occurrence_user so every
 * affected user is counted, not just the first. Optional date window.
 *
 * @return array{affected_users:int, error_events:int, pages:string[]}
 */
function uc_error_rate(array $uc, ?string $since = null, ?string $until = null): array {
    $pages = uc_flow_pages($uc);
    $out = ['affected_users' => 0, 'error_events' => 0, 'pages' => $pages];
    if (!$pages) { return $out; }

    $app = (string)($uc['source_app'] ?? '');
    $place = implode(',', array_fill(0, count($pages), '?'));
    $args  = array_merge([$app], $pages);
    $win = '';
    if ($since) { $win .= ' AND el.created >= ?'; $args[] = $since; }
    if ($until) { $win .= ' AND el.created <  ?'; $args[] = $until; }

    // Affected users: distinct users across every occurrence of every matching
    // error_hash (error_occurrence_user), plus the de-duplicated row's own user.
    $sql = "SELECT COUNT(*) AS c,
                   COUNT(DISTINCT eou.user_id) AS u
            FROM error_log el
            LEFT JOIN error_occurrence_user eou ON eou.error_hash = el.error_hash
            WHERE el.source_app = ?
              AND el.page IN ($place)
              AND el.resolved_at IS NULL
              $win";
    $r = db_query_prepared($sql, $args);
    $row = $r ? db_fetch($r) : null;
    if ($row) {
        $out['error_events']   = (int)$row['c'];
        $out['affected_users'] = (int)$row['u'];
    }
    return $out;
}

/**
 * Per-build rating stats for a use case: avg + count + distribution, newest
 * build first. A build is the app_build label on the rating ('' = web/unlabelled).
 * @return array<int,array> rows: build, avg, count, last_rated_at
 */
function uc_rating_builds(int $use_case_id): array {
    $rows = [];
    $r = db_query_prepared(
        "SELECT app_build AS build,
                ROUND(AVG(rating), 2) AS avg,
                COUNT(*)              AS count,
                MAX(created)          AS last_rated_at
         FROM use_case_rating
         WHERE use_case_id = ?
         GROUP BY app_build
         ORDER BY last_rated_at DESC",
        [$use_case_id]
    );
    while ($r && ($row = db_fetch($r))) {
        $row['avg']   = $row['avg'] !== null ? (float)$row['avg'] : null;
        $row['count'] = (int)$row['count'];
        $rows[] = $row;
    }
    return $rows;
}

/** Most recent rating comments for a use case (newest first). */
function uc_recent_comments(int $use_case_id, int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    $out = [];
    $r = db_query_prepared(
        "SELECT rating, comment, app_build, platform, created
         FROM use_case_rating
         WHERE use_case_id = ? AND comment IS NOT NULL AND comment <> ''
         ORDER BY created DESC
         LIMIT $limit",
        [$use_case_id]
    );
    while ($r && ($row = db_fetch($r))) {
        $row['rating'] = (int)$row['rating'];
        $out[] = $row;
    }
    return $out;
}

/**
 * Compute (and cache onto the use_case row) the health for a use case. Returns
 * the health array. Does NOT itself file a regression — call
 * uc_detect_regression() for that.
 */
function uc_compute_health(int $use_case_id): ?array {
    $r = db_query_prepared("SELECT * FROM use_case WHERE use_case_id = ?", [$use_case_id]);
    $uc = $r ? db_fetch($r) : null;
    if (!$uc) { return null; }

    $builds   = uc_rating_builds($use_case_id);
    $latest   = $builds[0] ?? null;
    $err      = uc_error_rate($uc);

    $health = [
        'use_case_id'    => $use_case_id,
        'test_status'    => $uc['test_status'],
        'latest_build'   => $latest['build'] ?? null,
        'rating_avg'     => $latest['avg']   ?? null,
        'rating_count'   => $latest['count'] ?? 0,
        'error_users'    => $err['affected_users'],
        'error_events'   => $err['error_events'],
        'builds'         => $builds,
        'computed_at'    => date('Y-m-d H:i:s'),
    ];
    // A single 0-100 score: start at test status, subtract for errors + weak
    // ratings. Purely a convenience ordering key; the parts above are the truth.
    $score = 100;
    if ($uc['test_status'] === 'failing')      { $score -= 50; }
    elseif ($uc['test_status'] === 'flaky')    { $score -= 20; }
    elseif ($uc['test_status'] === 'pending')  { $score -= 10; }
    if ($err['affected_users'] > 0)            { $score -= min(40, $err['affected_users'] * 5); }
    if ($latest && $latest['avg'] !== null)    { $score -= (int)round((5 - $latest['avg']) * 8); }
    $health['score'] = max(0, min(100, $score));

    db_query_prepared(
        "UPDATE use_case SET health_json = ?, health_computed_at = NOW() WHERE use_case_id = ?",
        [json_encode($health), $use_case_id]
    );
    return $health;
}

/**
 * Detect a regression for a use case's latest rated build vs the previous build:
 *   - average rating dropped by >= UC_REGRESSION_RATING_DROP (both builds having
 *     at least UC_REGRESSION_MIN_RATINGS ratings), OR
 *   - the flow's error rate rose by >= UC_REGRESSION_ERROR_RISE vs the previous
 *     build's window.
 * On a hit: set use_case.health_status='needs_attention' + flag_* and INSERT an
 * idempotent use_case_regression_event (UNIQUE use_case_id+build) for nokemo to
 * poll. Returns the reason string, or null when healthy.
 */
function uc_detect_regression(int $use_case_id): ?array {
    $r = db_query_prepared("SELECT * FROM use_case WHERE use_case_id = ?", [$use_case_id]);
    $uc = $r ? db_fetch($r) : null;
    if (!$uc) { return null; }

    $builds = uc_rating_builds($use_case_id);
    if (count($builds) < 2) { return null; } // need a previous build to compare
    $cur  = $builds[0];
    $prev = $builds[1];

    $reasons = [];
    $metric  = ['build' => $cur['build'], 'prev_build' => $prev['build']];

    // Rating drop
    if ($cur['count'] >= UC_REGRESSION_MIN_RATINGS
        && $prev['count'] >= UC_REGRESSION_MIN_RATINGS
        && $cur['avg'] !== null && $prev['avg'] !== null
        && ($prev['avg'] - $cur['avg']) >= UC_REGRESSION_RATING_DROP) {
        $drop = round($prev['avg'] - $cur['avg'], 2);
        $reasons[] = "rating dropped {$drop} ({$prev['avg']}→{$cur['avg']})";
        $metric['rating_prev'] = $prev['avg'];
        $metric['rating_cur']  = $cur['avg'];
    }

    // Error-rate rise: compare the window of the current build (since prev build's
    // last rating) against the previous build's window.
    $cur_err  = uc_error_rate($uc, $prev['last_rated_at'] ?: null);
    $prev_err = uc_error_rate($uc, null, $prev['last_rated_at'] ?: null);
    if ($cur_err['affected_users'] > 0
        && $cur_err['affected_users'] >= $prev_err['affected_users'] * UC_REGRESSION_ERROR_RISE
        && $cur_err['affected_users'] > $prev_err['affected_users']) {
        $reasons[] = "error rate rose ({$prev_err['affected_users']}→{$cur_err['affected_users']} users)";
        $metric['error_users_prev'] = $prev_err['affected_users'];
        $metric['error_users_cur']  = $cur_err['affected_users'];
    }

    if (!$reasons) {
        // Clear a stale flag for THIS build if it has since recovered.
        if ($uc['health_status'] === 'needs_attention' && $uc['flag_build'] === $cur['build']) {
            db_query_prepared(
                "UPDATE use_case SET health_status='ok', flag_reason=NULL, flag_build=NULL, flagged_at=NULL
                 WHERE use_case_id = ?",
                [$use_case_id]
            );
        }
        return null;
    }

    $reason = implode('; ', $reasons);
    db_query_prepared(
        "UPDATE use_case
         SET health_status='needs_attention', flag_reason=?, flag_build=?, flagged_at=NOW()
         WHERE use_case_id = ?",
        [$reason, $cur['build'], $use_case_id]
    );

    // Comments from the regressed build for the task to quote.
    $comments = uc_recent_comments($use_case_id, 10);

    db_query_prepared(
        "INSERT INTO use_case_regression_event
            (use_case_id, source_app, slug, name, build, prev_build, reason,
             metric_json, sample_comments)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason),
                                 prev_build = VALUES(prev_build),
                                 metric_json = VALUES(metric_json),
                                 sample_comments = VALUES(sample_comments)",
        [
            $use_case_id, (string)$uc['source_app'], (string)$uc['slug'],
            $uc['name'] ?: null, (string)$cur['build'], $prev['build'] ?: null,
            $reason, json_encode($metric), json_encode($comments),
        ]
    );
    return ['reason' => $reason, 'build' => $cur['build'], 'metric' => $metric];
}

/** Regression events since a cursor, for nokemo's monitoring cron to poll. */
function uc_regression_events_since(int $since_event_id = 0, ?string $source_app = null, int $limit = 100): array {
    $since = max(0, $since_event_id);
    $limit = max(1, min(500, $limit));
    $where = 'event_id > ?';
    $args  = [$since];
    if ($source_app !== null && $source_app !== '') { $where .= ' AND source_app = ?'; $args[] = $source_app; }
    $out = [];
    $r = db_query_prepared(
        "SELECT * FROM use_case_regression_event WHERE $where ORDER BY event_id ASC LIMIT $limit",
        $args
    );
    while ($r && ($row = db_fetch($r))) {
        $row['metric']     = json_decode($row['metric_json'] ?? 'null', true);
        $row['comments']   = json_decode($row['sample_comments'] ?? '[]', true);
        $out[] = $row;
    }
    return $out;
}

/**
 * The shared in-flow prompt budget: should we ask THIS user to rate THIS use
 * case on THIS build right now?
 *
 * Rules (shared with any future survey via engagement_prompt_log):
 *   - at most one prompt per user per day (any prompt kind);
 *   - each use case at most once per build per user;
 *   - the user must not have already rated this use case on this build;
 *   - audience: beta testers first (is_test_account, or caller asserts is_beta),
 *     later anyone who opts in (caller asserts opted_in).
 *
 * @param array $opts is_beta(bool), opted_in(bool), prompt_kind(string)
 * @return array{ok:bool, reason:string}
 */
function uc_should_prompt_rating(int $user_id, string $source_app, int $use_case_id, string $app_build = '', array $opts = []): array {
    if ($user_id <= 0 || $use_case_id <= 0) { return ['ok' => false, 'reason' => 'bad_args']; }
    $kind = $opts['prompt_kind'] ?? 'use_case_rating';

    // Audience gate: beta-first, else opt-in.
    $is_beta = !empty($opts['is_beta']);
    if (!$is_beta) {
        $ur = db_query_prepared("SELECT is_test_account FROM `user` WHERE user_id = ?", [$user_id]);
        $urow = $ur ? db_fetch($ur) : null;
        if ($urow && (int)$urow['is_test_account'] === 1) { $is_beta = true; }
    }
    if (!$is_beta && empty($opts['opted_in'])) {
        return ['ok' => false, 'reason' => 'audience_not_eligible'];
    }

    // Already rated this build?
    $ar = db_query_prepared(
        "SELECT 1 FROM use_case_rating WHERE use_case_id = ? AND user_id = ? AND app_build = ? LIMIT 1",
        [$use_case_id, $user_id, $app_build]
    );
    if ($ar && db_fetch($ar)) { return ['ok' => false, 'reason' => 'already_rated']; }

    // Already prompted for this use case on this build?
    $pr = db_query_prepared(
        "SELECT 1 FROM engagement_prompt_log
         WHERE user_id = ? AND prompt_kind = ? AND ref_id = ? AND app_build = ? LIMIT 1",
        [$user_id, $kind, $use_case_id, $app_build]
    );
    if ($pr && db_fetch($pr)) { return ['ok' => false, 'reason' => 'already_prompted_this_build']; }

    // Daily cap across all prompt kinds for this user.
    $dr = db_query_prepared(
        "SELECT COUNT(*) AS c FROM engagement_prompt_log
         WHERE user_id = ? AND shown_at >= (NOW() - INTERVAL 1 DAY)",
        [$user_id]
    );
    $drow = $dr ? db_fetch($dr) : null;
    if ($drow && (int)$drow['c'] >= UC_PROMPT_DAILY_CAP) {
        return ['ok' => false, 'reason' => 'daily_cap_reached'];
    }

    return ['ok' => true, 'reason' => 'ok'];
}

/** Record that an in-flow prompt was shown (counts against the daily budget). */
function uc_record_prompt_shown(int $user_id, string $source_app, int $use_case_id, string $app_build = '', string $kind = 'use_case_rating'): bool {
    $r = db_query_prepared(
        "INSERT IGNORE INTO engagement_prompt_log
            (user_id, source_app, prompt_kind, ref_id, app_build, shown_at)
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$user_id, $source_app, $kind, $use_case_id, $app_build]
    );
    return (bool)$r;
}

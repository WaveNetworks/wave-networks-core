<?php
/**
 * experimentFunctions.php — A/B testing framework (Task #795, Phase 2).
 *
 * ARCHITECTURE (decided 2026-05-31, layered on the #793 acquisition funnel):
 * Experiments ride the SAME single write path as everything else. There is no
 * parallel event table. A device hits a page, get_variant($slug) deterministically
 * (and stickily) assigns it a variant, and every subsequent log_user_action() call
 * is auto-stamped with event_data._experiments = { slug: variant, ... } by the
 * stamp injected into actionLogFunctions.php. The nightly rollup in
 * cleanup_action_log.php reads that map and upserts experiment_funnel_daily so the
 * funnel naturally splits by variant. The admin dashboard (?page=experiments)
 * reads experiment_funnel_daily and runs a chi-squared significance test.
 *
 * DETERMINISM / STICKINESS: variant = weighted pick keyed on crc32(device_id:slug).
 * Same device + slug always returns the same variant — no DB read needed to be
 * stable — but the first assignment is also persisted to experiment_assignment for
 * audit + claim-at-register. Traffic ramp and target filter are evaluated against
 * the same deterministic hash so ramping up traffic never reshuffles existing
 * assignments.
 *
 * DEVICE IDENTITY: we reuse the integer device-table PK (the same id the action
 * log keys on) stringified, so experiment splits line up exactly with the funnel's
 * unique_devices dedup across the anonymous -> registered boundary.
 *
 * Tables are created via ensure_experiment_tables() in autocommit — NOT the
 * migration runner — because makershost runs MariaDB and the runner's per-migration
 * transaction non-deterministically drops DDL. Mirrors ensure_acquisition_tables()
 * and ensure_media_table().
 */

/**
 * Guarantee the experiment tables exist (autocommit, idempotent, request-cached).
 * Single CREATE per statement (MariaDB DDL-drop bug). Safe to call on every request.
 */
function ensure_experiment_tables(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    db_query(
        "CREATE TABLE IF NOT EXISTS `experiment` (
            `experiment_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source_app`        VARCHAR(64)  NOT NULL,
            `slug`              VARCHAR(120) NOT NULL,
            `description`       TEXT         NULL,
            `hypothesis`        TEXT         NULL,
            `variants`          JSON         NOT NULL,
            `traffic_pct`       TINYINT UNSIGNED NOT NULL DEFAULT 100,
            `target_filter`     JSON         NULL,
            `primary_metric`    VARCHAR(120) NOT NULL,
            `guardrail_metrics` JSON         NULL,
            `status`            ENUM('draft','active','paused','concluded') NOT NULL DEFAULT 'draft',
            `started_at`        DATETIME     NULL,
            `concluded_at`      DATETIME     NULL,
            `winning_variant`   VARCHAR(64)  NULL,
            `conclusion_note`   TEXT         NULL,
            `created_by`        INT UNSIGNED NOT NULL DEFAULT 0,
            `created`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`experiment_id`),
            UNIQUE KEY `uk_app_slug` (`source_app`, `slug`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    db_query(
        "CREATE TABLE IF NOT EXISTS `experiment_assignment` (
            `experiment_id` INT UNSIGNED NOT NULL,
            `device_id`     VARCHAR(64)  NOT NULL,
            `variant_key`   VARCHAR(64)  NOT NULL,
            `assigned_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `user_id`       INT UNSIGNED NULL,
            PRIMARY KEY (`experiment_id`, `device_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_assigned` (`assigned_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    db_query(
        "CREATE TABLE IF NOT EXISTS `experiment_funnel_daily` (
            `day`            DATE         NOT NULL,
            `experiment_id`  INT UNSIGNED NOT NULL,
            `variant_key`    VARCHAR(64)  NOT NULL,
            `stage_key`      VARCHAR(64)  NOT NULL,
            `unique_devices` INT UNSIGNED NOT NULL DEFAULT 0,
            `unique_users`   INT UNSIGNED NOT NULL DEFAULT 0,
            `event_count`    INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `experiment_id`, `variant_key`, `stage_key`),
            KEY `idx_exp` (`experiment_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * The current device's stable identity for experiment assignment, as a string.
 * Reuses the integer device-table PK the action log keys on. Null if unknown.
 */
function current_device_id(): ?string
{
    if (!empty($_SESSION['device_id'])) {
        return (string)(int)$_SESSION['device_id'];
    }
    $cookie_id = $_SERVER['HTTP_X_WN_DEVICE'] ?? $_COOKIE['wn_device'] ?? null;
    if ($cookie_id && function_exists('get_device_by_cookie')) {
        $device = get_device_by_cookie($cookie_id);
        if ($device) { return (string)(int)$device['device_id']; }
    }
    return null;
}

/**
 * Detect the source_app for the current request (falls back to detect_source_app()).
 */
function experiment_source_app(?string $source_app = null): string
{
    if ($source_app !== null && $source_app !== '') { return $source_app; }
    if (function_exists('detect_source_app')) { return detect_source_app(); }
    global $source_app_global;
    return $source_app_global ?? 'admin';
}

/**
 * Whether the current actor is a test account — test users opt out of experiments
 * (always 'control') so automated suites stay deterministic. Request-cached.
 */
function experiment_is_test_actor(): bool
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = false;
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) {
        try {
            $r = db_query_prepared("SELECT is_test_account FROM user WHERE user_id = ?", [(int)$uid]);
            $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
            if ($row) { $cache = (int)$row['is_test_account'] === 1; }
        } catch (Exception $e) { /* ignore */ }
    }
    return $cache;
}

/**
 * Load all non-draft experiments for an app, request-cached and keyed by slug.
 * Includes active, paused and concluded so get_variant() can serve a concluded
 * winner. Draft experiments are intentionally excluded (assign to nobody).
 */
function load_app_experiments(?string $source_app = null): array
{
    static $cache = [];
    $app = experiment_source_app($source_app);
    if (isset($cache[$app])) { return $cache[$app]; }

    ensure_experiment_tables();
    $rows = db_fetch_all(db_query_prepared(
        "SELECT * FROM `experiment`
          WHERE `source_app` = ? AND `status` <> 'draft'",
        [$app]
    ));
    $byslug = [];
    foreach ($rows as $row) { $byslug[$row['slug']] = $row; }
    $cache[$app] = $byslug;
    return $byslug;
}

/**
 * Load a single named experiment (any non-draft status) for the app, or null.
 */
function load_active_experiment(string $slug, ?string $source_app = null): ?array
{
    $all = load_app_experiments($source_app);
    return $all[$slug] ?? null;
}

/**
 * Sticky lookup of a persisted assignment. Returns variant_key or null.
 */
function lookup_assignment(int $experiment_id, string $device_id): ?string
{
    $row = db_fetch(db_query_prepared(
        "SELECT `variant_key` FROM `experiment_assignment`
          WHERE `experiment_id` = ? AND `device_id` = ?",
        [$experiment_id, $device_id]
    ));
    return $row ? (string)$row['variant_key'] : null;
}

/**
 * Persist a first-time assignment (INSERT IGNORE so concurrent first hits race-safe).
 */
function record_assignment(int $experiment_id, string $device_id, string $variant): void
{
    db_query_prepared(
        "INSERT IGNORE INTO `experiment_assignment`
            (`experiment_id`, `device_id`, `variant_key`, `assigned_at`)
         VALUES (?, ?, ?, NOW())",
        [$experiment_id, $device_id, $variant]
    );
}

/**
 * Decode a JSON column that may already be an array (PDO sometimes returns string).
 */
function experiment_json_decode($val): array
{
    if (is_array($val)) { return $val; }
    if (!is_string($val) || $val === '') { return []; }
    $d = json_decode($val, true);
    return is_array($d) ? $d : [];
}

/**
 * Evaluate a target_filter against the current device's known attributes.
 * Empty/null filter always matches. Recognized keys: cohort, source_app.
 * Unknown attributes fail closed (device not in the experiment).
 */
function matches_target_filter($filter): bool
{
    $filter = experiment_json_decode($filter);
    if (!$filter) { return true; }

    foreach ($filter as $key => $want) {
        $have = experiment_device_attribute((string)$key);
        if ($have === null || (string)$have !== (string)$want) {
            return false;
        }
    }
    return true;
}

/**
 * Resolve a known attribute of the current device for target filtering.
 * Extend here as more segment dimensions become available.
 */
function experiment_device_attribute(string $key): ?string
{
    switch ($key) {
        case 'source_app':
            return experiment_source_app();
        case 'cohort':
            // Pre-reg quiz cohort captured against the device, if present.
            if (function_exists('get_prereg_cohort_for_device')) {
                $did = current_device_id();
                if ($did) { return get_prereg_cohort_for_device($did); }
            }
            return $_SESSION['prereg_cohort'] ?? null;
        default:
            return null;
    }
}

/**
 * Deterministic weighted variant pick. Same (device_id, slug) -> same key.
 * Weights need not sum to 100 — the hash is scaled into the total weight.
 */
function pick_weighted_variant($variants, string $device_id, string $slug): string
{
    $variants = experiment_json_decode($variants);
    if (!$variants) { return 'control'; }

    $total = 0;
    foreach ($variants as $v) { $total += max(0, (int)($v['weight'] ?? 0)); }
    if ($total <= 0) {
        return (string)($variants[0]['key'] ?? 'control');
    }

    // Independent hash seed from the bucket hash so ramp + pick don't correlate.
    $point = crc32('variant:' . $device_id . ':' . $slug) % $total;
    $acc = 0;
    foreach ($variants as $v) {
        $acc += max(0, (int)($v['weight'] ?? 0));
        if ($point < $acc) { return (string)($v['key'] ?? 'control'); }
    }
    return (string)($variants[count($variants) - 1]['key'] ?? 'control');
}

/**
 * Get the variant assignment for the current device on a named experiment.
 * Returns the variant key (e.g. 'control', 'shorter') or null if the device is
 * not in an active experiment. Deterministic + sticky + persisted on first call.
 *
 * Concluded experiments with a winning_variant serve the winner to everyone.
 */
function get_variant(string $slug, ?string $source_app = null): ?string
{
    $exp = load_active_experiment($slug, $source_app);
    if (!$exp) { return null; }

    // Concluded -> serve the declared winner (or fall back to control) for everyone.
    if ($exp['status'] === 'concluded') {
        return $exp['winning_variant'] ?: null;
    }
    // Paused -> hold existing assignments steady but assign nobody new; treat as control.
    if ($exp['status'] !== 'active') {
        $did = current_device_id();
        if ($did) {
            $existing = lookup_assignment((int)$exp['experiment_id'], $did);
            if ($existing) { return $existing; }
        }
        return 'control';
    }

    // Test accounts opt out — always control so Playwright is deterministic.
    if (experiment_is_test_actor()) { return 'control'; }

    $device_id = current_device_id();
    if (!$device_id) { return 'control'; }

    // Sticky: existing assignment wins, untouched by later ramp/filter changes.
    $existing = lookup_assignment((int)$exp['experiment_id'], $device_id);
    if ($existing) { return $existing; }

    // Traffic ramp: deterministic bucket; outside the ramp -> control, not enrolled.
    $bucket = crc32('ramp:' . $device_id . ':' . $slug) % 100;
    if ($bucket >= (int)$exp['traffic_pct']) { return 'control'; }

    // Target filter (cohort, source_app, ...). Non-matching -> control, not enrolled.
    if (!matches_target_filter($exp['target_filter'] ?? null)) { return 'control'; }

    $variant = pick_weighted_variant($exp['variants'], $device_id, $slug);
    record_assignment((int)$exp['experiment_id'], $device_id, $variant);

    // First-assignment is its own auditable event (params allowlisted: slug, variant).
    if (function_exists('log_user_action')) {
        try {
            log_user_action('experiment_assigned', 'success', ['slug' => $slug, 'variant' => $variant]);
        } catch (Exception $e) { /* silent */ }
    }
    return $variant;
}

/**
 * Return all active experiment assignments for a device as { slug: variant_key }.
 * Request-cached. Only enrolled (persisted) assignments on still-active experiments
 * are returned, so the funnel split reflects real randomized exposure. This is the
 * map the action-log stamp attaches to every event.
 */
function get_all_assignments_for_device(string $device_id, ?string $source_app = null): array
{
    static $cache = [];
    $app = experiment_source_app($source_app);
    $ckey = $app . '|' . $device_id;
    if (isset($cache[$ckey])) { return $cache[$ckey]; }

    $out = [];
    $experiments = load_app_experiments($app);
    $active = [];
    foreach ($experiments as $slug => $exp) {
        if ($exp['status'] === 'active') { $active[(int)$exp['experiment_id']] = $slug; }
    }
    if ($active) {
        $rows = db_fetch_all(db_query_prepared(
            "SELECT `experiment_id`, `variant_key` FROM `experiment_assignment`
              WHERE `device_id` = ?",
            [$device_id]
        ));
        foreach ($rows as $r) {
            $eid = (int)$r['experiment_id'];
            if (isset($active[$eid])) { $out[$active[$eid]] = (string)$r['variant_key']; }
        }
    }
    $cache[$ckey] = $out;
    return $out;
}

/**
 * Claim a device's anonymous assignments to a user at register_success.
 * Returns the number of assignment rows linked. Call from the register flow.
 */
function claim_experiment_assignments(string $device_id, int $user_id): int
{
    if ($device_id === '' || $user_id <= 0) { return 0; }
    ensure_experiment_tables();
    $stmt = db_query_prepared(
        "UPDATE `experiment_assignment`
            SET `user_id` = ?
          WHERE `device_id` = ? AND (`user_id` IS NULL OR `user_id` = 0)",
        [$user_id, $device_id]
    );
    return $stmt ? (int)$stmt->rowCount() : 0;
}

/**
 * Thin wrapper: emit a funnel/experiment event through the single write path.
 * The _experiments stamp is added centrally in log_user_action(), so this just
 * forwards. Provided for #793/#794 call-site readability.
 */
function record_acquisition_event(string $event_type, array $opts = []): void
{
    if (!function_exists('log_user_action')) { return; }
    $params = $opts['params'] ?? $opts;
    unset($params['result'], $params['duration_ms']);
    try {
        log_user_action($event_type, $opts['result'] ?? 'success', $params, $opts['duration_ms'] ?? null);
    } catch (Exception $e) { /* silent */ }
}

/* ───────────────────────── Statistics (Phase 2: chi-squared) ───────────────────────── */

/**
 * erf via Abramowitz & Stegun 7.1.26 (max error ~1.5e-7). PHP has no built-in erf.
 */
function experiment_erf(float $x): float
{
    $sign = $x < 0 ? -1 : 1;
    $x = abs($x);
    $t = 1 / (1 + 0.3275911 * $x);
    $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);
    return $sign * $y;
}

/**
 * Two-tailed p-value for a 2x2 contingency chi-squared statistic (df=1).
 * p = erfc(sqrt(chi2 / 2)).
 */
function experiment_chi2_pvalue(float $chi2): float
{
    if ($chi2 <= 0) { return 1.0; }
    $erfc = 1 - experiment_erf(sqrt($chi2 / 2));
    return max(0.0, min(1.0, $erfc));
}

/**
 * 2x2 chi-squared on conversion: control (n_a, c_a) vs variant (n_b, c_b).
 * Returns ['chi2','p','p_a','p_b','lift','significant'].
 */
function experiment_chi_squared(int $n_a, int $c_a, int $n_b, int $c_b): array
{
    $p_a = $n_a > 0 ? $c_a / $n_a : 0.0;
    $p_b = $n_b > 0 ? $c_b / $n_b : 0.0;
    $lift = $p_a > 0 ? ($p_b - $p_a) / $p_a : 0.0;

    $out = ['chi2' => 0.0, 'p' => 1.0, 'p_a' => $p_a, 'p_b' => $p_b, 'lift' => $lift, 'significant' => false];
    $total = $n_a + $n_b;
    if ($n_a <= 0 || $n_b <= 0 || $total <= 0) { return $out; }

    // 2x2 table: [converted, not-converted] x [control, variant]
    $a = $c_a;           $b = $c_b;
    $cc = $n_a - $c_a;   $d = $n_b - $c_b;
    $row1 = $a + $b;     $row2 = $cc + $d;
    if ($row1 <= 0 || $row2 <= 0) { return $out; }

    // chi2 = N (ad - bc)^2 / (row1 row2 col1 col2)
    $num = $total * pow(($a * $d - $b * $cc), 2);
    $den = $row1 * $row2 * $n_a * $n_b;
    $chi2 = $den > 0 ? $num / $den : 0.0;

    $out['chi2'] = $chi2;
    $out['p'] = experiment_chi2_pvalue($chi2);
    $out['significant'] = $out['p'] < 0.05;
    return $out;
}

/**
 * 95% Wilson score interval for a binomial proportion. Returns ['lo','hi'].
 */
function experiment_wilson_ci(int $conversions, int $n, float $z = 1.96): array
{
    if ($n <= 0) { return ['lo' => 0.0, 'hi' => 0.0]; }
    $p = $conversions / $n;
    $z2 = $z * $z;
    $denom = 1 + $z2 / $n;
    $centre = $p + $z2 / (2 * $n);
    $margin = $z * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n);
    return ['lo' => max(0.0, ($centre - $margin) / $denom), 'hi' => min(1.0, ($centre + $margin) / $denom)];
}

/**
 * Approx. sample size PER GROUP for power=0.8, two-sided alpha=0.05, to detect the
 * observed difference between p_a and p_b. Returns 0 when the inputs are degenerate.
 */
function experiment_sample_size(float $p_a, float $p_b): int
{
    $diff = abs($p_a - $p_b);
    if ($diff <= 0 || $p_a <= 0 || $p_a >= 1) { return 0; }
    $z_alpha = 1.959963985; // two-sided 0.05
    $z_beta  = 0.841621234; // power 0.80
    $pbar = ($p_a + $p_b) / 2;
    $term = $z_alpha * sqrt(2 * $pbar * (1 - $pbar)) + $z_beta * sqrt($p_a * (1 - $p_a) + $p_b * (1 - $p_b));
    return (int)ceil(($term * $term) / ($diff * $diff));
}

/**
 * Pull the per-variant funnel for an experiment from experiment_funnel_daily.
 * Returns [ variant_key => [ stage_key => ['unique_devices'=>,'unique_users'=>,'event_count'=>] ] ].
 */
function get_experiment_funnel(int $experiment_id): array
{
    ensure_experiment_tables();
    $rows = db_fetch_all(db_query_prepared(
        "SELECT `variant_key`, `stage_key`,
                SUM(`unique_devices`) AS unique_devices,
                SUM(`unique_users`)   AS unique_users,
                SUM(`event_count`)    AS event_count
           FROM `experiment_funnel_daily`
          WHERE `experiment_id` = ?
          GROUP BY `variant_key`, `stage_key`",
        [$experiment_id]
    ));
    $out = [];
    foreach ($rows as $r) {
        $out[$r['variant_key']][$r['stage_key']] = [
            'unique_devices' => (int)$r['unique_devices'],
            'unique_users'   => (int)$r['unique_users'],
            'event_count'    => (int)$r['event_count'],
        ];
    }
    return $out;
}

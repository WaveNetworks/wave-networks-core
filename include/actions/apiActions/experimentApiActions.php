<?php
/**
 * experimentApiActions.php — public API for the A/B experiment watchdog (Task #796).
 * Authenticated via service API key (Bearer token). Scope-gated.
 *
 * Actions:
 *   apiListActiveExperiments — compact per-experiment summary the hourly heartbeat
 *     watchdog polls (chi-squared p/effect/CI, n_to_significance, guardrail status,
 *     staleness, low-volume signals). Read-only.
 *   apiConcludeExperiment — manual "conclude + ship the winner" step. Marks the
 *     experiment concluded, records the winner + note, logs a lifecycle event.
 *
 * The watchdog NEVER auto-concludes — apiConcludeExperiment is a deliberate,
 * user-triggered call. Test-account traffic is already excluded upstream (enrolled
 * assignments and the funnel rollup both drop is_test_account rows).
 */

// ---- LIST ACTIVE EXPERIMENTS (watchdog poll) ----
if (($action ?? null) == 'apiListActiveExperiments') {
    if (require_api_scope('experiments:read')) {
        $status               = isset($_POST['status']) && $_POST['status'] !== '' ? $_POST['status'] : 'active';
        $source_app           = trim($_POST['source_app'] ?? '') ?: null;
        $include_funnel       = !empty($_POST['include_funnel']);
        $include_significance = !isset($_POST['include_significance']) || !empty($_POST['include_significance']);

        $data['experiments'] = list_experiment_summaries($status, $source_app, $include_funnel, $include_significance);
        $data['count']       = count($data['experiments']);
        $_SESSION['success'] = 'OK';
    }
}

// ---- CONCLUDE EXPERIMENT + SHIP WINNER ----
if (($action ?? null) == 'apiConcludeExperiment') {
    $errs = array();
    if (!require_api_scope('experiments:write')) { /* error already set */ }
    else {
        $slug            = trim($_POST['experiment_slug'] ?? $_POST['slug'] ?? '');
        $source_app      = trim($_POST['source_app'] ?? '') ?: null;
        $winning_variant = trim($_POST['winning_variant'] ?? '');
        $note            = trim($_POST['conclusion_note'] ?? '');
        $inconclusive    = !empty($_POST['inconclusive']);

        if ($slug === '')            { $errs['slug'] = 'experiment_slug is required.'; }
        if ($winning_variant === '') { $errs['winning_variant'] = 'winning_variant is required.'; }

        if (empty($errs) && $_SERVICE_API_KEY) {
            // Auto-populate a conclusion note from current stats if the caller omits one.
            if ($note === '') {
                $exp = load_active_experiment($slug, $source_app);
                if ($exp) {
                    $s = build_experiment_summary($exp, false, true);
                    $note = sprintf(
                        'Concluded via API: winner=%s, lift=%+.1f%%, p=%s (n=%d/%d visits).',
                        $winning_variant,
                        $s['effect_pct'] ?? 0,
                        isset($s['p_value']) ? (string)$s['p_value'] : 'n/a',
                        (int)($s['visits_per_variant'][$s['control_variant']] ?? 0),
                        (int)array_sum($s['visits_per_variant'] ?? [])
                    );
                }
                if ($note === '') { $note = 'Concluded via API.'; }
            }

            $res = conclude_experiment($slug, $source_app, $winning_variant, $note, $inconclusive);
            if ($res['ok']) {
                $data['experiment_id']  = $res['experiment_id'];
                $data['winning_variant'] = $winning_variant;
                $data['conclusion_note'] = $note;
                $_SESSION['success'] = 'Experiment concluded. Winner now serves to everyone; file a bake-in task to remove the get_variant branch.';
            } else {
                $_SESSION['error'] = $res['error'];
            }
        } elseif (!empty($errs)) {
            $_SESSION['error'] = implode('<br>', $errs);
        }
    }
}

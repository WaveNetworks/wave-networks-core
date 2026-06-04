<?php
/**
 * experimentActions.php — admin CRUD + lifecycle for A/B experiments (Task #795).
 * Form-POST actions on views/experiments.php. Admin-only.
 *
 * Lifecycle is a manual state machine — NEVER auto-conclude on p-value (peeking
 * inflates false positives). Conclude requires a winning_variant + conclusion_note.
 */

if (($_POST['action'] ?? '') === 'createExperiment') {
    $errs = [];
    if (!has_role('admin')) { $errs['auth'] = 'Admin access required.'; }

    $source_app     = trim($_POST['source_app'] ?? '');
    $slug           = trim($_POST['slug'] ?? '');
    $primary_metric = trim($_POST['primary_metric'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $hypothesis     = trim($_POST['hypothesis'] ?? '');
    $traffic_pct    = (int)($_POST['traffic_pct'] ?? 100);
    if ($traffic_pct < 0)   { $traffic_pct = 0; }
    if ($traffic_pct > 100) { $traffic_pct = 100; }

    if ($source_app === '')     { $errs['source_app'] = 'Source app is required.'; }
    if ($slug === '')           { $errs['slug'] = 'Slug is required.'; }
    elseif (!preg_match('/^[A-Za-z0-9_.\-]+$/', $slug)) { $errs['slug'] = 'Slug may only contain letters, numbers, _ . and -'; }
    if ($primary_metric === '') { $errs['primary_metric'] = 'Primary metric is required.'; }

    // Variants: prefer JSON; else build from parallel key/weight line lists.
    $variants = [];
    $vjson = trim($_POST['variants_json'] ?? '');
    if ($vjson !== '') {
        $decoded = json_decode($vjson, true);
        if (!is_array($decoded)) { $errs['variants'] = 'Variants JSON is not valid.'; }
        else { $variants = $decoded; }
    } else {
        $keys    = preg_split('/[\r\n,]+/', trim($_POST['variant_keys'] ?? ''));
        $weights = preg_split('/[\r\n,]+/', trim($_POST['variant_weights'] ?? ''));
        foreach ($keys as $i => $k) {
            $k = trim($k);
            if ($k === '') { continue; }
            $variants[] = ['key' => $k, 'weight' => (int)($weights[$i] ?? 50)];
        }
    }
    if (!$errs && count($variants) < 2) { $errs['variants'] = 'At least two variants are required.'; }
    // Normalize + validate variant shape.
    $clean_variants = [];
    foreach ($variants as $v) {
        $vk = trim((string)($v['key'] ?? ''));
        if ($vk === '') { continue; }
        $clean_variants[] = ['key' => $vk, 'weight' => max(0, (int)($v['weight'] ?? 0))];
    }
    if (!$errs && count($clean_variants) < 2) { $errs['variants'] = 'At least two valid variants are required.'; }

    // Optional JSON blobs.
    $target_filter = null;
    if (trim($_POST['target_filter'] ?? '') !== '') {
        $tf = json_decode(trim($_POST['target_filter']), true);
        if (!is_array($tf)) { $errs['target_filter'] = 'Target filter must be valid JSON.'; }
        else { $target_filter = json_encode($tf); }
    }
    $guardrail = null;
    if (trim($_POST['guardrail_metrics'] ?? '') !== '') {
        $gm = json_decode(trim($_POST['guardrail_metrics']), true);
        if (!is_array($gm)) { $errs['guardrail_metrics'] = 'Guardrail metrics must be valid JSON.'; }
        else { $guardrail = json_encode($gm); }
    }

    if (!$errs) {
        ensure_experiment_tables();
        $ok = db_query_prepared(
            "INSERT INTO experiment
                (source_app, slug, description, hypothesis, variants, traffic_pct,
                 target_filter, primary_metric, guardrail_metrics, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
            [$source_app, $slug, ($description ?: null), ($hypothesis ?: null),
             json_encode($clean_variants), $traffic_pct, $target_filter,
             $primary_metric, $guardrail, (int)($_SESSION['user_id'] ?? 0)]
        );
        if ($ok === false) {
            $_SESSION['error'] = 'Could not create experiment (slug may already exist for this app).';
        } else {
            $_SESSION['success'] = 'Experiment created as draft. Activate it to start assigning variants.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') === 'updateExperimentStatus') {
    $errs = [];
    if (!has_role('admin')) { $errs['auth'] = 'Admin access required.'; }

    $experiment_id = (int)($_POST['experiment_id'] ?? 0);
    $new_status    = $_POST['new_status'] ?? '';
    $allowed       = ['active', 'paused', 'concluded'];
    if ($experiment_id <= 0)                  { $errs['id'] = 'Missing experiment.'; }
    if (!in_array($new_status, $allowed, true)) { $errs['status'] = 'Invalid status.'; }

    $winning_variant = trim($_POST['winning_variant'] ?? '');
    $conclusion_note = trim($_POST['conclusion_note'] ?? '');
    if (!$errs && $new_status === 'concluded') {
        if ($winning_variant === '') { $errs['winning'] = 'A winning variant is required to conclude.'; }
        if ($conclusion_note === '') { $errs['note'] = 'A conclusion note is required.'; }
    }

    if (!$errs) {
        ensure_experiment_tables();
        if ($new_status === 'active') {
            // Stamp started_at only on the first activation.
            db_query_prepared(
                "UPDATE experiment
                    SET status = 'active',
                        started_at = COALESCE(started_at, NOW())
                  WHERE experiment_id = ?",
                [$experiment_id]);
            $_SESSION['success'] = 'Experiment is now active — devices will be assigned variants.';
        } elseif ($new_status === 'paused') {
            db_query_prepared(
                "UPDATE experiment SET status = 'paused' WHERE experiment_id = ?",
                [$experiment_id]);
            $_SESSION['success'] = 'Experiment paused. No new assignments; existing ones are kept.';
        } else { // concluded
            db_query_prepared(
                "UPDATE experiment
                    SET status = 'concluded', concluded_at = NOW(),
                        winning_variant = ?, conclusion_note = ?
                  WHERE experiment_id = ?",
                [$winning_variant, $conclusion_note, $experiment_id]);
            $_SESSION['success'] = 'Experiment concluded. The winning variant will now serve to everyone.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

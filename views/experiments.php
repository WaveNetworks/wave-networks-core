<?php
/**
 * views/experiments.php — A/B experiment dashboard (Task #795). Admin-only.
 *
 * List view: every non-? experiment per app with at-a-glance lift + significance.
 * Detail view (?experiment_id=N): per-variant funnel side by side, drop-off,
 * guardrail status, chi-squared p-value + Wilson CIs + sample-size projection.
 */
if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
ensure_experiment_tables();
$page_title = 'Experiments';

$detail_id = (int)($_GET['experiment_id'] ?? 0);

/** Helper: stage list (ordered) + first/primary stage keys for an app. */
function experiments_app_stages(string $app): array {
    $def = function_exists('get_funnel_def') ? get_funnel_def($app) : [];
    $stages = [];
    foreach ($def as $d) { $stages[] = $d['stage_key']; }
    return $stages;
}

/** Helper: render a p-value badge. */
function experiments_sig_badge(array $stat): string {
    if ($stat['p_a'] <= 0 && $stat['p_b'] <= 0) {
        return '<span class="badge bg-secondary">no data</span>';
    }
    if ($stat['significant']) {
        return '<span class="badge bg-success">significant · p=' . h(number_format($stat['p'], 4)) . '</span>';
    }
    return '<span class="badge bg-secondary">p=' . h(number_format($stat['p'], 4)) . '</span>';
}

/* ───────────────────────── DETAIL VIEW ───────────────────────── */
if ($detail_id > 0):
    $exp = db_fetch(db_query_prepared("SELECT * FROM experiment WHERE experiment_id = ?", [$detail_id]));
    if (!$exp):
?>
<div class="alert alert-warning">Experiment not found. <a href="index.php?page=experiments">Back to list</a>.</div>
<?php
    else:
        $variants = experiment_json_decode($exp['variants']);
        $variant_keys = array_map(fn($v) => (string)($v['key'] ?? ''), $variants);
        $stages = experiments_app_stages($exp['source_app']);
        if (!$stages) { $stages = [$exp['primary_metric']]; }
        $first_stage   = $stages[0];
        $primary_stage = $exp['primary_metric'];
        $funnel = get_experiment_funnel($detail_id); // [variant][stage] => counts

        // Control vs each variant on the primary metric, denominator = first stage.
        $control_key = in_array('control', $variant_keys, true) ? 'control' : ($variant_keys[0] ?? 'control');
        $n_ctrl = (int)($funnel[$control_key][$first_stage]['unique_devices'] ?? 0);
        $c_ctrl = (int)($funnel[$control_key][$primary_stage]['unique_devices'] ?? 0);

        $guardrails = experiment_json_decode($exp['guardrail_metrics']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="index.php?page=experiments" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> All experiments</a>
        <h4 class="mb-0 mt-1"><i class="bi bi-flask me-2"></i><?= h($exp['slug']) ?></h4>
    </div>
    <span class="badge bg-<?= $exp['status'] === 'active' ? 'success' : ($exp['status'] === 'paused' ? 'warning text-dark' : ($exp['status'] === 'concluded' ? 'secondary' : 'light text-dark')) ?> fs-6">
        <?= h(ucfirst($exp['status'])) ?>
    </span>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row small">
            <div class="col-md-3"><strong>App:</strong> <?= h($exp['source_app']) ?></div>
            <div class="col-md-3"><strong>Primary metric:</strong> <code><?= h($exp['primary_metric']) ?></code></div>
            <div class="col-md-3"><strong>Traffic:</strong> <?= (int)$exp['traffic_pct'] ?>%</div>
            <div class="col-md-3"><strong>Started:</strong> <?= h($exp['started_at'] ?: '—') ?></div>
        </div>
        <?php if ($exp['description']) { ?><p class="mt-2 mb-1"><?= h($exp['description']) ?></p><?php } ?>
        <?php if ($exp['hypothesis']) { ?><p class="mb-0 text-muted"><em>Hypothesis: <?= h($exp['hypothesis']) ?></em></p><?php } ?>
        <?php if ($exp['status'] === 'concluded') { ?>
        <div class="alert alert-secondary mt-3 mb-0">
            <strong>Concluded</strong> on <?= h($exp['concluded_at']) ?> — winner:
            <span class="badge bg-success"><?= h($exp['winning_variant']) ?></span>
            <?php if ($exp['conclusion_note']) { ?><div class="mt-1"><?= h($exp['conclusion_note']) ?></div><?php } ?>
        </div>
        <?php } ?>
    </div>
</div>

<!-- Significance card -->
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0">Primary metric significance (vs <?= h($control_key) ?>)</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Variant</th><th>Visitors</th><th>Conversions</th>
                <th>Conv. rate (95% CI)</th><th>Lift</th><th>Significance</th><th>n / group needed</th>
            </tr></thead>
            <tbody>
            <?php foreach ($variant_keys as $vk):
                $n_v = (int)($funnel[$vk][$first_stage]['unique_devices'] ?? 0);
                $c_v = (int)($funnel[$vk][$primary_stage]['unique_devices'] ?? 0);
                $ci  = experiment_wilson_ci($c_v, $n_v);
                $rate = $n_v > 0 ? $c_v / $n_v : 0;
                if ($vk === $control_key) { ?>
                <tr class="table-light">
                    <td><strong><?= h($vk) ?></strong> <span class="badge bg-light text-dark">control</span></td>
                    <td><?= number_format($n_v) ?></td>
                    <td><?= number_format($c_v) ?></td>
                    <td><?= number_format($rate * 100, 2) ?>% <span class="text-muted">(<?= number_format($ci['lo']*100,2) ?>–<?= number_format($ci['hi']*100,2) ?>%)</span></td>
                    <td>—</td><td>—</td><td>—</td>
                </tr>
                <?php } else {
                    $stat = experiment_chi_squared($n_ctrl, $c_ctrl, $n_v, $c_v);
                    $nNeed = experiment_sample_size($stat['p_a'], $stat['p_b']);
                ?>
                <tr>
                    <td><strong><?= h($vk) ?></strong></td>
                    <td><?= number_format($n_v) ?></td>
                    <td><?= number_format($c_v) ?></td>
                    <td><?= number_format($rate * 100, 2) ?>% <span class="text-muted">(<?= number_format($ci['lo']*100,2) ?>–<?= number_format($ci['hi']*100,2) ?>%)</span></td>
                    <td class="<?= $stat['lift'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($stat['lift'] >= 0 ? '+' : '') . number_format($stat['lift'] * 100, 1) ?>%</td>
                    <td><?= experiments_sig_badge($stat) ?></td>
                    <td><?= $nNeed > 0 ? number_format($nNeed) : '—' ?></td>
                </tr>
                <?php } endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        Denominator = unique devices at first funnel stage <code><?= h($first_stage) ?></code>.
        Conversion = unique devices reaching <code><?= h($primary_stage) ?></code>.
        Chi-squared 2×2, two-tailed; sample size for power 0.8 at α=0.05.
        <strong>Never auto-concluded</strong> — significance is informational; you decide.
    </div>
</div>

<!-- Per-variant funnel -->
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0">Funnel by variant (unique devices)</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>Stage</th>
                <?php foreach ($variant_keys as $vk) { ?><th><?= h($vk) ?></th><?php } ?>
            </tr></thead>
            <tbody>
            <?php
            $prev = [];
            foreach ($stages as $si => $stage): ?>
                <tr>
                    <td>
                        <code><?= h($stage) ?></code>
                        <?php if ($stage === $primary_stage) { ?><span class="badge bg-primary">primary</span><?php } ?>
                        <?php
                        $gbreach = false;
                        foreach ($guardrails as $g) { if (($g['stage'] ?? '') === $stage) { $gbreach = true; } }
                        if ($gbreach) { ?><span class="badge bg-info text-dark">guardrail</span><?php } ?>
                    </td>
                    <?php foreach ($variant_keys as $vk):
                        $cnt = (int)($funnel[$vk][$stage]['unique_devices'] ?? 0);
                        $drop = '';
                        if ($si > 0 && isset($prev[$vk]) && $prev[$vk] > 0) {
                            $delta = round((1 - $cnt / $prev[$vk]) * 100);
                            $drop = '<span class="text-muted small"> (−' . (int)$delta . '%)</span>';
                        }
                        $prev[$vk] = $cnt;
                    ?>
                        <td><?= number_format($cnt) ?><?= $drop ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Lifecycle actions -->
<div class="card">
    <div class="card-header"><h6 class="mb-0">Lifecycle</h6></div>
    <div class="card-body d-flex gap-2 flex-wrap">
        <?php if (in_array($exp['status'], ['draft', 'paused'], true)) { ?>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="updateExperimentStatus">
            <input type="hidden" name="experiment_id" value="<?= (int)$exp['experiment_id'] ?>">
            <input type="hidden" name="new_status" value="active">
            <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-play-fill"></i> <?= $exp['status'] === 'draft' ? 'Activate' : 'Resume' ?></button>
        </form>
        <?php } ?>
        <?php if ($exp['status'] === 'active') { ?>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="updateExperimentStatus">
            <input type="hidden" name="experiment_id" value="<?= (int)$exp['experiment_id'] ?>">
            <input type="hidden" name="new_status" value="paused">
            <button class="btn btn-warning btn-sm" type="submit"><i class="bi bi-pause-fill"></i> Pause</button>
        </form>
        <?php } ?>
        <?php if ($exp['status'] !== 'concluded') { ?>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#concludeModal"><i class="bi bi-flag-fill"></i> Conclude…</button>
        <?php } ?>
    </div>
</div>

<!-- Conclude modal -->
<div class="modal fade" id="concludeModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="updateExperimentStatus">
      <input type="hidden" name="experiment_id" value="<?= (int)$exp['experiment_id'] ?>">
      <input type="hidden" name="new_status" value="concluded">
      <div class="modal-header"><h5 class="modal-title">Conclude experiment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="text-muted small">Concluding picks a permanent winner — it will serve to everyone. This is a manual decision; reaching significance does not auto-conclude.</p>
        <div class="mb-3">
          <label class="form-label">Winning variant</label>
          <select name="winning_variant" class="form-select" required>
            <option value="">Choose…</option>
            <?php foreach ($variant_keys as $vk) { ?><option value="<?= h($vk) ?>"><?= h($vk) ?></option><?php } ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Conclusion note</label>
          <textarea name="conclusion_note" class="form-control" rows="3" required placeholder="Why this variant won, observed lift, p-value, sample size…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Conclude experiment</button>
      </div>
    </form>
  </div>
</div>
<?php
    endif;
else:
/* ───────────────────────── LIST VIEW ───────────────────────── */
    $rows = db_fetch_all(db_query("SELECT * FROM experiment ORDER BY FIELD(status,'active','paused','draft','concluded'), created DESC"));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-flask me-2"></i>Experiments</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal"><i class="bi bi-plus-lg"></i> New experiment</button>
</div>

<?php if (!$rows) { ?>
<div class="alert alert-info">No experiments yet. Create one to start A/B testing on top of the acquisition funnel.</div>
<?php } else { ?>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th>Slug</th><th>App</th><th>Status</th><th>Primary metric</th>
                <th>Traffic</th><th>Lift</th><th>Significance</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $variants = experiment_json_decode($r['variants']);
                $vkeys = array_map(fn($v) => (string)($v['key'] ?? ''), $variants);
                $stages = experiments_app_stages($r['source_app']);
                $first_stage = $stages[0] ?? $r['primary_metric'];
                $funnel = get_experiment_funnel((int)$r['experiment_id']);
                $control_key = in_array('control', $vkeys, true) ? 'control' : ($vkeys[0] ?? 'control');
                $other = null;
                foreach ($vkeys as $vk) { if ($vk !== $control_key) { $other = $vk; break; } }
                $stat = ['p_a' => 0, 'p_b' => 0, 'lift' => 0, 'p' => 1, 'significant' => false];
                if ($other !== null) {
                    $stat = experiment_chi_squared(
                        (int)($funnel[$control_key][$first_stage]['unique_devices'] ?? 0),
                        (int)($funnel[$control_key][$r['primary_metric']]['unique_devices'] ?? 0),
                        (int)($funnel[$other][$first_stage]['unique_devices'] ?? 0),
                        (int)($funnel[$other][$r['primary_metric']]['unique_devices'] ?? 0)
                    );
                }
            ?>
                <tr>
                    <td><a href="index.php?page=experiments&experiment_id=<?= (int)$r['experiment_id'] ?>" class="text-decoration-none fw-semibold"><?= h($r['slug']) ?></a></td>
                    <td><?= h($r['source_app']) ?></td>
                    <td><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : ($r['status'] === 'paused' ? 'warning text-dark' : ($r['status'] === 'concluded' ? 'secondary' : 'light text-dark')) ?>"><?= h(ucfirst($r['status'])) ?></span></td>
                    <td><code><?= h($r['primary_metric']) ?></code></td>
                    <td><?= (int)$r['traffic_pct'] ?>%</td>
                    <td class="<?= $stat['lift'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $other !== null ? (($stat['lift'] >= 0 ? '+' : '') . number_format($stat['lift'] * 100, 1) . '%') : '—' ?></td>
                    <td><?= experiments_sig_badge($stat) ?></td>
                    <td><a href="index.php?page=experiments&experiment_id=<?= (int)$r['experiment_id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<!-- Create modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="createExperiment">
      <div class="modal-header"><h5 class="modal-title">New experiment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Source app</label>
            <input name="source_app" class="form-control" placeholder="elevateher" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Slug</label>
            <input name="slug" class="form-control" placeholder="quiz_welcome_copy_v2" required>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Description</label>
          <input name="description" class="form-control" placeholder="Short copy on the quiz welcome screen">
        </div>
        <div class="mb-3">
          <label class="form-label">Hypothesis</label>
          <input name="hypothesis" class="form-control" placeholder="Shorter copy lifts register_success +5%">
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Variant keys <span class="text-muted small">(one per line)</span></label>
            <textarea name="variant_keys" class="form-control" rows="3" placeholder="control&#10;shorter"></textarea>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Weights <span class="text-muted small">(one per line, matches keys)</span></label>
            <textarea name="variant_weights" class="form-control" rows="3" placeholder="50&#10;50"></textarea>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">…or variants JSON <span class="text-muted small">(overrides the two boxes above)</span></label>
          <input name="variants_json" class="form-control font-monospace" placeholder='[{"key":"control","weight":50},{"key":"shorter","weight":50}]'>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Primary metric <span class="text-muted small">(funnel stage key)</span></label>
            <input name="primary_metric" class="form-control" placeholder="acq_registered" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Traffic %</label>
            <input type="number" name="traffic_pct" class="form-control" min="0" max="100" value="100">
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Target filter JSON <span class="text-muted small">(optional)</span></label>
            <input name="target_filter" class="form-control font-monospace" placeholder='{"cohort":"under_25"}'>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Guardrail metrics JSON <span class="text-muted small">(optional)</span></label>
            <input name="guardrail_metrics" class="form-control font-monospace" placeholder='[{"stage":"acq_signup_started","max_drop_pct":5}]'>
          </div>
        </div>
        <p class="text-muted small mb-0">Created as <strong>draft</strong>. Activate it from its detail page to start assigning variants.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Create experiment</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
/**
 * views/acquisition.php — acquisition-funnel dashboard (Task #805). Admin-only.
 *
 * Per source_app, renders the ordered funnel from acquisition_funnel_def populated
 * by acquisition_funnel_daily: per-stage unique_devices / unique_users, stage-to-stage
 * drop-off %, and the active-user count (the stage flagged is_active_user) over a
 * selectable date range. App selector for multi-app deployments.
 *
 * OUT OF SCOPE (other tasks): A/B per-variant split (#795 -> ?page=experiments),
 * PDF/share export (#537).
 */
if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
ensure_acquisition_tables();
$page_title = 'Acquisition Funnel';

// ── App selector ──────────────────────────────────────────────
$apps = get_acquisition_apps();
$app  = trim((string)($_GET['app'] ?? ''));
if ($app === '' || !in_array($app, $apps, true)) {
    $app = $apps[0] ?? '';
}

// ── Date range (default last 30 days) ─────────────────────────
$range_days = (int)($_GET['range'] ?? 30);
if (!in_array($range_days, [7, 30, 90, 365], true)) { $range_days = 30; }
// Range is relative; cron rollup writes per-day rows so a relative window is correct.
$to   = date('Y-m-d');
$from = date('Y-m-d', strtotime('-' . ($range_days - 1) . ' days'));

$def    = $app !== '' ? get_funnel_def($app) : [];
$funnel = $app !== '' ? get_acquisition_funnel($app, $from, $to, '') : [];
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-funnel me-2"></i>Acquisition Funnel</h4>
    <form method="get" class="d-flex align-items-center gap-2">
        <input type="hidden" name="page" value="acquisition">
        <?php if (count($apps) > 1) { ?>
        <label class="small text-muted mb-0">App</label>
        <select name="app" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <?php foreach ($apps as $a) { ?>
            <option value="<?= h($a) ?>" <?= $a === $app ? 'selected' : '' ?>><?= h($a) ?></option>
            <?php } ?>
        </select>
        <?php } elseif ($app !== '') { ?>
        <input type="hidden" name="app" value="<?= h($app) ?>">
        <span class="badge bg-light text-dark border"><?= h($app) ?></span>
        <?php } ?>
        <label class="small text-muted mb-0 ms-2">Range</label>
        <select name="range" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $rv => $rl) { ?>
            <option value="<?= (int)$rv ?>" <?= $rv === $range_days ? 'selected' : '' ?>><?= h($rl) ?></option>
            <?php } ?>
        </select>
    </form>
</div>

<?php if ($app === '') { ?>
<div class="alert alert-info">
    No acquisition funnels found yet. Declare an app's funnel stages via
    <code>declare_funnel()</code> and let the nightly rollup populate
    <code>acquisition_funnel_daily</code> — this dashboard renders automatically once data lands.
</div>
<?php } elseif (!$def) { ?>
<div class="alert alert-warning">
    App <strong><?= h($app) ?></strong> has rollup data but no funnel definition.
    Call <code>declare_funnel('<?= h($app) ?>', [...])</code> to define the ordered stages.
</div>
<?php } else {
    // Compute totals + active-user stage.
    $first_devices = (int)($funnel[$def[0]['stage_key']]['unique_devices'] ?? 0);
    $active_devices = 0; $active_users = 0; $active_label = '';
    foreach ($def as $d) {
        if (!empty($d['is_active_user'])) {
            $active_devices = (int)($funnel[$d['stage_key']]['unique_devices'] ?? 0);
            $active_users   = (int)($funnel[$d['stage_key']]['unique_users'] ?? 0);
            $active_label   = $d['stage_label'] !== '' ? $d['stage_label'] : $d['stage_key'];
        }
    }
    $overall_conv = $first_devices > 0 ? round($active_devices / $first_devices * 100, 1) : 0;
?>

<!-- Summary cards -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Entered funnel <span class="text-muted">(<?= h($def[0]['stage_label'] !== '' ? $def[0]['stage_label'] : $def[0]['stage_key']) ?>)</span></div>
            <div class="fs-3 fw-semibold"><?= number_format($first_devices) ?></div>
            <div class="small text-muted">unique devices</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Active users <?= $active_label !== '' ? '<span class="text-muted">(' . h($active_label) . ')</span>' : '' ?></div>
            <div class="fs-3 fw-semibold"><?= number_format($active_users) ?></div>
            <div class="small text-muted"><?= number_format($active_devices) ?> unique devices</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">Overall conversion</div>
            <div class="fs-3 fw-semibold"><?= number_format($overall_conv, 1) ?>%</div>
            <div class="small text-muted">entry → active (devices)</div>
        </div></div>
    </div>
</div>

<!-- Funnel table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Funnel for <code><?= h($app) ?></code></h6>
        <span class="small text-muted"><?= h($from) ?> → <?= h($to) ?></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead><tr>
                <th style="width:40px">#</th>
                <th>Stage</th>
                <th class="text-end">Unique devices</th>
                <th class="text-end">Unique users</th>
                <th class="text-end">Step drop-off</th>
                <th class="text-end">% of entry</th>
                <th style="width:30%">Conversion bar</th>
            </tr></thead>
            <tbody>
            <?php
            $prev_devices = null;
            foreach ($def as $i => $d):
                $sk      = $d['stage_key'];
                $label   = $d['stage_label'] !== '' ? $d['stage_label'] : $sk;
                $devices = (int)($funnel[$sk]['unique_devices'] ?? 0);
                $users   = (int)($funnel[$sk]['unique_users'] ?? 0);

                $drop_html = '<span class="text-muted">—</span>';
                if ($prev_devices !== null && $prev_devices > 0) {
                    $drop = round((1 - $devices / $prev_devices) * 100, 1);
                    $cls  = $drop > 0 ? 'text-danger' : 'text-success';
                    $drop_html = '<span class="' . $cls . '">' . ($drop > 0 ? '−' : '+') . number_format(abs($drop), 1) . '%</span>';
                }

                $pct_entry = $first_devices > 0 ? round($devices / $first_devices * 100, 1) : 0;
                $prev_devices = $devices;
            ?>
                <tr<?= !empty($d['is_active_user']) ? ' class="table-success"' : '' ?>>
                    <td class="text-muted"><?= (int)$i + 1 ?></td>
                    <td>
                        <?= h($label) ?>
                        <?php if ($label !== $sk) { ?><br><code class="small text-muted"><?= h($sk) ?></code><?php } ?>
                        <?php if (!empty($d['is_active_user'])) { ?><span class="badge bg-success ms-1">active user</span><?php } ?>
                    </td>
                    <td class="text-end"><?= number_format($devices) ?></td>
                    <td class="text-end"><?= number_format($users) ?></td>
                    <td class="text-end"><?= $drop_html ?></td>
                    <td class="text-end"><?= number_format($pct_entry, 1) ?>%</td>
                    <td>
                        <div class="progress" style="height:18px" role="progressbar" aria-valuenow="<?= (int)round($pct_entry) ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar<?= !empty($d['is_active_user']) ? ' bg-success' : '' ?>" style="width:<?= (float)$pct_entry ?>%"></div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        Counts are unique devices / users from the durable <code>acquisition_funnel_daily</code> rollup
        (segment = all). Step drop-off compares each stage to the one above it; % of entry is relative to
        the first stage. The <span class="badge bg-success">active user</span> stage is flagged via
        <code>acquisition_funnel_def.is_active_user</code>. A/B per-variant splits live under
        <a href="index.php?page=experiments">Experiments</a>.
    </div>
</div>
<?php } ?>

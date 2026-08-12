<?php
/**
 * views/db_sizes.php
 * Shard / database size monitor. Lists every DB schema on this host with its
 * size, % of the ~1GB shared-hosting limit, and OK/WARNING/CRITICAL status so
 * operators know when to provision a new shard.
 */
$page_title = 'Shard Sizes';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$rows = get_cached_db_sizes(60);

$total_mb = 0; $ok = 0; $warn = 0; $crit = 0;
$last_checked = null;
foreach ($rows as $r) {
    $total_mb += (float)$r['size_mb'];
    if ($r['status'] === 'warning')      { $warn++; }
    elseif ($r['status'] === 'critical') { $crit++; }
    else                                 { $ok++; }
    if (!empty($r['last_checked']) && ($last_checked === null || $r['last_checked'] > $last_checked)) {
        $last_checked = $r['last_checked'];
    }
}

function db_size_badge($status) {
    switch ($status) {
        case 'critical': return '<span class="badge bg-danger">CRITICAL</span>';
        case 'warning':  return '<span class="badge bg-warning text-dark">WARNING</span>';
        default:         return '<span class="badge bg-success">OK</span>';
    }
}
function db_size_bar_class($status) {
    switch ($status) {
        case 'critical': return 'bg-danger';
        case 'warning':  return 'bg-warning';
        default:         return 'bg-success';
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Shard Database Sizes</h3>
    <form method="post" class="mb-0">
        <input type="hidden" name="action" value="refreshDbSizes">
        <button type="submit" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-sync"></i> Refresh now
        </button>
    </form>
</div>

<?php if ($crit > 0) { ?>
<div class="alert alert-danger">
    <strong><?= $crit ?></strong> schema<?= $crit === 1 ? '' : 's' ?> over
    <?= DB_SIZE_CRITICAL_MB ?>MB — a new shard is needed. These are logged as
    FATAL errors and surfaced in the error monitoring pipeline.
</div>
<?php } elseif ($warn > 0) { ?>
<div class="alert alert-warning">
    <strong><?= $warn ?></strong> schema<?= $warn === 1 ? '' : 's' ?> in the
    <?= DB_SIZE_WARN_MB ?>–<?= DB_SIZE_CRITICAL_MB ?>MB warning band. Plan a new
    shard soon.
</div>
<?php } ?>

<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3">
        <div class="card h-100"><div class="card-body text-center py-3">
            <h6 class="card-title text-muted mb-1 small">Schemas</h6>
            <h3 class="mb-0"><?= count($rows) ?></h3>
        </div></div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card h-100"><div class="card-body text-center py-3">
            <h6 class="card-title text-muted mb-1 small">Total Size</h6>
            <h3 class="mb-0"><?= number_format($total_mb, 1) ?> MB</h3>
        </div></div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card h-100"><div class="card-body text-center py-3">
            <h6 class="card-title text-muted mb-1 small">Warning</h6>
            <h3 class="mb-0 text-warning"><?= $warn ?></h3>
        </div></div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card h-100"><div class="card-body text-center py-3">
            <h6 class="card-title text-muted mb-1 small">Critical</h6>
            <h3 class="mb-0 text-danger"><?= $crit ?></h3>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Per-schema usage (limit <?= DB_SIZE_LIMIT_MB ?>MB / shard)</h6>
            <?php if ($last_checked) { ?>
            <small class="text-muted">Last checked <?= h($last_checked) ?></small>
            <?php } ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Schema</th>
                        <th class="text-end">Size (MB)</th>
                        <th class="text-end">Tables</th>
                        <th style="min-width:200px;">% of <?= DB_SIZE_LIMIT_MB ?>MB</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) { ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No schemas found.</td></tr>
                <?php } else { foreach ($rows as $r) {
                    $pct = isset($r['pct']) ? (float)$r['pct']
                         : min(100, round(((float)$r['size_mb'] / DB_SIZE_LIMIT_MB) * 100, 1));
                ?>
                    <tr>
                        <td><code><?= h($r['schema_name']) ?></code></td>
                        <td class="text-end"><?= number_format((float)$r['size_mb'], 1) ?></td>
                        <td class="text-end"><?= (int)($r['table_count'] ?? 0) ?></td>
                        <td>
                            <div class="progress" style="height:18px;">
                                <div class="progress-bar <?= db_size_bar_class($r['status']) ?>"
                                     role="progressbar" style="width:<?= $pct ?>%;"
                                     aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                                    <?= $pct ?>%
                                </div>
                            </div>
                        </td>
                        <td><?= db_size_badge($r['status']) ?></td>
                    </tr>
                <?php } } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3 text-muted small">
    <strong>Provisioning a new shard (manual):</strong> add a new entry to
    <code>$shardConfigs</code> in <code>admin/config/config.php</code> pointing at
    the new database, create the shard DB on the host, then run the admin
    migrations against it (they run automatically on the next request). New
    registrations are assigned to the least-loaded shard automatically.
</div>

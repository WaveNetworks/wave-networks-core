<?php
/**
 * views/dashboard.php
 * Admin dashboard — summary cards and recent activity.
 */
$page_title = 'Dashboard';

// Get counts
$userCount = db_fetch(db_query("SELECT COUNT(*) as cnt FROM user"))['cnt'] ?? 0;
$recentUsers = db_fetch_all(db_query("SELECT user_id, email, created_date, shard_id FROM user ORDER BY created_date DESC LIMIT 5"));

// Check for updates (admin only, uses 24h cache)
$updateInfo = null;
if (has_role('admin')) {
    $updateInfo = check_for_updates();
}
$hasUpdates = $updateInfo && ($updateInfo['admin']['outdated'] || $updateInfo['child_app']['outdated']);
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Dashboard</h3>
</div>

<?php if ($hasUpdates) { ?>
<!-- Update available banner -->
<div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
    <div class="d-flex align-items-start">
        <i class="bi bi-arrow-up-circle-fill me-2 mt-1"></i>
        <div class="flex-grow-1">
            <strong>Updates available</strong>
            <ul class="mb-1 mt-1">
                <?php if ($updateInfo['admin']['outdated']) { ?>
                <li>
                    Admin: <strong><?= h($updateInfo['admin']['current']) ?></strong>
                    <i class="bi bi-arrow-right"></i>
                    <strong><?= h($updateInfo['admin']['latest']) ?></strong>
                    <?php if ($updateInfo['admin']['date']) { ?>
                    <small class="text-muted">(<?= h($updateInfo['admin']['date']) ?>)</small>
                    <?php } ?>
                    <?php if ($updateInfo['admin']['migration_required']) { ?>
                    <span class="badge bg-warning text-dark ms-1">migration required</span>
                    <?php } ?>
                </li>
                <?php } ?>
                <?php if ($updateInfo['child_app']['outdated']) { ?>
                <li>
                    Child App: <strong><?= h($updateInfo['child_app']['current']) ?></strong>
                    <i class="bi bi-arrow-right"></i>
                    <strong><?= h($updateInfo['child_app']['latest']) ?></strong>
                    <?php if ($updateInfo['child_app']['date']) { ?>
                    <small class="text-muted">(<?= h($updateInfo['child_app']['date']) ?>)</small>
                    <?php } ?>
                    <?php if ($updateInfo['child_app']['migration_required']) { ?>
                    <span class="badge bg-warning text-dark ms-1">migration required</span>
                    <?php } ?>
                </li>
                <?php } ?>
            </ul>
            <a href="https://subtheme.com/docs/changelog" target="_blank" class="btn btn-sm btn-outline-info">
                View Changelog <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<!-- Summary cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title text-muted">Total Users</h5>
                <h2><?= h($userCount) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title text-muted">Shards</h5>
                <h2><?= count($shardConfigs ?? []) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title text-muted">Your Role</h5>
                <h2>
                    <?php if (has_role('owner')) { echo 'Owner'; }
                    elseif (has_role('admin')) { echo 'Admin'; }
                    elseif (has_role('manager')) { echo 'Manager'; }
                    elseif (has_role('employee')) { echo 'Employee'; }
                    else { echo 'User'; } ?>
                </h2>
            </div>
        </div>
    </div>
</div>

<!-- Recent users -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Recent Users</h5>
        <?php if (has_role('admin')) { ?>
        <a href="index.php?page=users" class="btn btn-sm btn-outline-primary">View All</a>
        <?php } ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentUsers)) { ?>
        <div class="p-4 text-center text-muted">
            <p>No users yet.</p>
        </div>
        <?php } else { ?>
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Shard</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentUsers as $u) { ?>
                <tr>
                    <td><?= h($u['user_id']) ?></td>
                    <td><?= h($u['email']) ?></td>
                    <td><span class="badge bg-secondary"><?= h($u['shard_id']) ?></span></td>
                    <td><?= h($u['created_date']) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </div>
</div>

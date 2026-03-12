<?php
/**
 * views/dashboard.php
 * Admin dashboard — summary cards and recent activity.
 */
$page_title = 'Dashboard';

// Get counts
$userCount = db_fetch(db_query("SELECT COUNT(*) as cnt FROM user"))['cnt'] ?? 0;
$recentUsers = db_fetch_all(db_query("SELECT user_id, email, created_date, shard_id FROM user ORDER BY created_date DESC LIMIT 5"));
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Dashboard</h3>
</div>

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

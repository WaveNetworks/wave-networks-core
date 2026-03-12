<?php
/**
 * views/roles.php
 * Role management overview.
 */
$page_title = 'Roles';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$admins    = db_fetch_all(db_query("SELECT user_id, email FROM user WHERE is_admin = 1 ORDER BY email"));
$managers  = db_fetch_all(db_query("SELECT user_id, email FROM user WHERE is_manager = 1 ORDER BY email"));
$employees = db_fetch_all(db_query("SELECT user_id, email FROM user WHERE is_employee = 1 ORDER BY email"));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Roles</h3>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><strong>Admins</strong> (<?= count($admins) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($admins as $u) { ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= h($u['email']) ?>
                    <a href="index.php?page=user_edit&id=<?= h($u['user_id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                </li>
                <?php } ?>
                <?php if (empty($admins)) { ?>
                <li class="list-group-item text-muted">No admins</li>
                <?php } ?>
            </ul>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><strong>Managers</strong> (<?= count($managers) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($managers as $u) { ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= h($u['email']) ?>
                    <a href="index.php?page=user_edit&id=<?= h($u['user_id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                </li>
                <?php } ?>
                <?php if (empty($managers)) { ?>
                <li class="list-group-item text-muted">No managers</li>
                <?php } ?>
            </ul>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><strong>Employees</strong> (<?= count($employees) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($employees as $u) { ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= h($u['email']) ?>
                    <a href="index.php?page=user_edit&id=<?= h($u['user_id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                </li>
                <?php } ?>
                <?php if (empty($employees)) { ?>
                <li class="list-group-item text-muted">No employees</li>
                <?php } ?>
            </ul>
        </div>
    </div>
</div>

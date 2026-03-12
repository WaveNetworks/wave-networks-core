<?php
/**
 * views/users.php
 * Paginated user list with search and filter.
 */
$page_title = 'Users';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$search      = $_GET['search'] ?? '';
$sort         = $_GET['sort'] ?? 'user_id';
$dir          = $_GET['dir'] ?? 'DESC';
$per_page     = (int)($_GET['per_page'] ?? 20);
$current_page = max(1, (int)($_GET['current_page'] ?? 1));

$result = get_users_paginated($current_page, $per_page, $search, $sort, $dir);
$users  = $result['users'];
$total  = $result['total'];
$total_pages = max(1, ceil($total / $per_page));

// Sort direction toggle
$next_dir = ($dir === 'ASC') ? 'DESC' : 'ASC';
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Users <small class="text-muted">(<?= h($total) ?>)</small></h3>
    <a href="index.php?page=user_create" class="btn btn-primary">Add User</a>
</div>

<!-- Search -->
<form method="get" class="mb-3">
    <input type="hidden" name="page" value="users">
    <div class="input-group" style="max-width: 400px;">
        <input type="text" class="form-control" name="search" placeholder="Search by email..." value="<?= h($search) ?>">
        <button class="btn btn-outline-secondary" type="submit">Search</button>
        <?php if ($search) { ?>
        <a href="index.php?page=users" class="btn btn-outline-danger">Clear</a>
        <?php } ?>
    </div>
</form>

<!-- User table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><a href="?page=users&sort=user_id&dir=<?= $next_dir ?>&search=<?= h($search) ?>">ID</a></th>
                    <th><a href="?page=users&sort=email&dir=<?= $next_dir ?>&search=<?= h($search) ?>">Email</a></th>
                    <th>Roles</th>
                    <th>Shard</th>
                    <th>Confirmed</th>
                    <th><a href="?page=users&sort=created_date&dir=<?= $next_dir ?>&search=<?= h($search) ?>">Created</a></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)) { ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                <?php } ?>
                <?php foreach ($users as $u) { ?>
                <tr>
                    <td><?= h($u['user_id']) ?></td>
                    <td><?= h($u['email']) ?></td>
                    <td>
                        <?php if ($u['is_owner']) { ?><span class="badge bg-danger">Owner</span><?php } ?>
                        <?php if ($u['is_admin']) { ?><span class="badge bg-primary">Admin</span><?php } ?>
                        <?php if ($u['is_manager']) { ?><span class="badge bg-info">Manager</span><?php } ?>
                        <?php if ($u['is_employee']) { ?><span class="badge bg-secondary">Employee</span><?php } ?>
                    </td>
                    <td><span class="badge bg-light text-dark"><?= h($u['shard_id']) ?></span></td>
                    <td>
                        <?php if ($u['is_confirmed']) { ?>
                        <span class="text-success">Yes</span>
                        <?php } else { ?>
                        <span class="text-danger">No</span>
                        <?php } ?>
                    </td>
                    <td><?= h($u['created_date']) ?></td>
                    <td>
                        <a href="index.php?page=user_edit&id=<?= h($u['user_id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1) { ?>
<nav class="mt-3">
    <ul class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++) { ?>
        <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
            <a class="page-link" href="?page=users&current_page=<?= $i ?>&per_page=<?= $per_page ?>&sort=<?= h($sort) ?>&dir=<?= h($dir) ?>&search=<?= h($search) ?>">
                <?= $i ?>
            </a>
        </li>
        <?php } ?>
    </ul>
</nav>
<?php } ?>

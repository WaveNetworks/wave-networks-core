<?php
/**
 * views/user_edit.php
 * Edit a user's details + roles.
 */
$page_title = 'Edit User';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = (int)($_GET['id'] ?? 0);
if (!$user_id) {
    $_SESSION['error'] = 'User ID required.';
    header('Location: index.php?page=users');
    exit;
}

$user = get_user($user_id);
if (!$user) {
    $_SESSION['error'] = 'User not found.';
    header('Location: index.php?page=users');
    exit;
}

// Get profile from shard
$profile = get_user_profile($user_id, $user['shard_id']);
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Edit User #<?= h($user_id) ?></h3>
    <a href="index.php?page=users" class="btn btn-outline-secondary">Back to Users</a>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="editUser">
                    <input type="hidden" name="user_id" value="<?= h($user_id) ?>">

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= h($user['email']) ?>" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?= h($profile['first_name'] ?? '') ?>">
                        </div>
                        <div class="col">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?= h($profile['last_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password <small class="text-muted">(leave blank to keep current)</small></label>
                        <input type="password" class="form-control" id="new_password" name="new_password">
                    </div>

                    <h5 class="mt-4 mb-3">Roles</h5>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_admin" id="is_admin" value="1" <?= $user['is_admin'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_admin">Admin</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_manager" id="is_manager" value="1" <?= $user['is_manager'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_manager">Manager</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_employee" id="is_employee" value="1" <?= $user['is_employee'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_employee">Employee</label>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Info</div>
            <div class="card-body">
                <p><strong>User ID:</strong> <?= h($user['user_id']) ?></p>
                <p><strong>Shard:</strong> <?= h($user['shard_id']) ?></p>
                <p><strong>Created:</strong> <?= h($user['created_date']) ?></p>
                <p><strong>Last Login:</strong> <?= h($user['last_login'] ?? 'Never') ?></p>
                <p><strong>Confirmed:</strong> <?= $user['is_confirmed'] ? 'Yes' : 'No' ?></p>
                <p><strong>2FA:</strong> <?= $user['totp_enabled'] ? 'Enabled' : 'Disabled' ?></p>
                <p><strong>OAuth:</strong> <?= h($user['oauth_provider'] ?? 'None') ?></p>

                <?php if (!$user['is_confirmed']) { ?>
                <form method="post" class="mt-2">
                    <input type="hidden" name="action" value="confirmUser">
                    <input type="hidden" name="user_id" value="<?= h($user_id) ?>">
                    <button type="submit" class="btn btn-sm btn-success">Confirm User</button>
                </form>
                <?php } ?>
            </div>
        </div>

        <?php if ($user['user_id'] != $_SESSION['user_id']) { ?>
        <div class="card mt-3">
            <div class="card-header text-danger">Danger Zone</div>
            <div class="card-body">
                <form method="post" onsubmit="return confirm('Are you sure you want to delete this user?');">
                    <input type="hidden" name="action" value="deleteUser">
                    <input type="hidden" name="user_id" value="<?= h($user_id) ?>">
                    <button type="submit" class="btn btn-danger btn-sm w-100">Delete User</button>
                </form>
            </div>
        </div>
        <?php } ?>
    </div>
</div>

<?php
/**
 * views/user_create.php
 * Create a new user.
 */
$page_title = 'Create User';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Create User</h3>
    <a href="index.php?page=users" class="btn btn-outline-secondary">Back to Users</a>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="addUser">

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name">
                        </div>
                        <div class="col">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <div class="form-text">Minimum 8 characters</div>
                    </div>

                    <h5 class="mt-4 mb-3">Roles</h5>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_admin" id="is_admin" value="1">
                        <label class="form-check-label" for="is_admin">Admin</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_manager" id="is_manager" value="1">
                        <label class="form-check-label" for="is_manager">Manager</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_employee" id="is_employee" value="1">
                        <label class="form-check-label" for="is_employee">Employee</label>
                    </div>

                    <button type="submit" class="btn btn-primary">Create User</button>
                </form>
            </div>
        </div>
    </div>
</div>

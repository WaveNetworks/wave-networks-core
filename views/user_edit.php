<?php
/**
 * views/user_edit.php
 * Edit a user's details, roles, password, and view compliance data.
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

// Compliance data
$consent_history   = function_exists('get_consent_history') ? get_consent_history($user_id) : [];
$consent_statuses  = function_exists('get_all_consent_statuses') ? get_all_consent_statuses($user_id) : [];
$login_history     = function_exists('get_login_history') ? get_login_history($user_id, 20) : [];
$active_devices    = function_exists('get_user_devices') ? get_user_devices($user_id) : [];
$pending_deletion  = function_exists('get_pending_deletion') ? get_pending_deletion($user_id) : null;
$latest_export     = function_exists('get_latest_export') ? get_latest_export($user_id) : null;

$active_tab = $_GET['tab'] ?? 'profile';
?>

<!-- REGION: page-header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>Edit User #<?= h($user_id) ?> <small class="text-muted"><?= h($user['email']) ?></small></h3>
    <a href="index.php?page=users" class="btn btn-outline-secondary">Back to Users</a>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'profile' ? 'active' : '' ?>" href="?page=user_edit&id=<?= h($user_id) ?>&tab=profile">
            <i class="bi bi-person me-1"></i> Profile
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'consent' ? 'active' : '' ?>" href="?page=user_edit&id=<?= h($user_id) ?>&tab=consent">
            <i class="bi bi-clipboard-check me-1"></i> Consent
            <?php
            $granted_count = count(array_filter($consent_statuses, fn($s) => $s === 'granted'));
            $total_types = 5; // tos, privacy, marketing_email, cookie_analytics, cookie_marketing
            ?>
            <span class="badge bg-secondary"><?= $granted_count ?>/<?= $total_types ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'logins' ? 'active' : '' ?>" href="?page=user_edit&id=<?= h($user_id) ?>&tab=logins">
            <i class="bi bi-clock-history me-1"></i> Login History
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'sessions' ? 'active' : '' ?>" href="?page=user_edit&id=<?= h($user_id) ?>&tab=sessions">
            <i class="bi bi-laptop me-1"></i> Sessions
            <?php if (count($active_devices)) { ?>
            <span class="badge bg-info"><?= count($active_devices) ?></span>
            <?php } ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'data' ? 'active' : '' ?>" href="?page=user_edit&id=<?= h($user_id) ?>&tab=data">
            <i class="bi bi-shield-check me-1"></i> Data & Deletion
            <?php if ($pending_deletion) { ?>
            <span class="badge bg-danger">Pending</span>
            <?php } ?>
        </a>
    </li>
</ul>

<?php if ($active_tab === 'profile') { ?>
<!-- ═══ PROFILE TAB ═══ -->
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

        <!-- Password Reset -->
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-key me-1"></i> Reset Password</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="adminResetPassword">
                    <input type="hidden" name="user_id" value="<?= h($user_id) ?>">

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                        <div class="form-text">Minimum 8 characters. The user will be notified by email.</div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="notify_user" id="notify_user" value="1" checked>
                        <label class="form-check-label" for="notify_user">Send email notification to user</label>
                    </div>

                    <button type="submit" class="btn btn-warning" onclick="return confirm('Reset this user\'s password?')">
                        <i class="bi bi-key me-1"></i> Reset Password
                    </button>
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

        <!-- Quick compliance summary -->
        <div class="card mt-3">
            <div class="card-header">Compliance Summary</div>
            <div class="card-body small">
                <p class="mb-1">
                    <strong>ToS:</strong>
                    <?php if (($consent_statuses['terms_of_service'] ?? '') === 'granted') { ?>
                    <span class="badge bg-success">Accepted</span>
                    <?php } else { ?>
                    <span class="badge bg-warning text-dark">Not accepted</span>
                    <?php } ?>
                </p>
                <p class="mb-1">
                    <strong>Privacy:</strong>
                    <?php if (($consent_statuses['privacy_policy'] ?? '') === 'granted') { ?>
                    <span class="badge bg-success">Accepted</span>
                    <?php } else { ?>
                    <span class="badge bg-warning text-dark">Not accepted</span>
                    <?php } ?>
                </p>
                <p class="mb-1">
                    <strong>Marketing:</strong>
                    <?php if (($consent_statuses['marketing_email'] ?? '') === 'granted') { ?>
                    <span class="badge bg-success">Opted in</span>
                    <?php } else { ?>
                    <span class="badge bg-secondary">Opted out</span>
                    <?php } ?>
                </p>
                <?php if ($pending_deletion) { ?>
                <p class="mb-0 mt-2 text-danger">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Deletion scheduled</strong>
                    <?= h(date('M j, Y', strtotime($pending_deletion['cancel_before']))) ?>
                </p>
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

<?php } elseif ($active_tab === 'consent') { ?>
<!-- ═══ CONSENT TAB ═══ -->
<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-clipboard-check me-1"></i> Current Consent Status</div>
            <div class="card-body">
                <?php
                $type_labels = [
                    'terms_of_service' => ['Terms of Service', 'Required'],
                    'privacy_policy'   => ['Privacy Policy', 'Required'],
                    'marketing_email'  => ['Marketing Emails', 'Optional'],
                    'cookie_analytics' => ['Analytics Cookies', 'Optional'],
                    'cookie_marketing' => ['Marketing Cookies', 'Optional'],
                ];
                foreach ($type_labels as $type => [$label, $req]) {
                    $status = $consent_statuses[$type] ?? null;
                ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <strong><?= h($label) ?></strong>
                        <div class="text-muted small"><?= h($req) ?></div>
                    </div>
                    <?php if ($status === 'granted') { ?>
                    <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Granted</span>
                    <?php } elseif ($status === 'withdrawn') { ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-x-lg me-1"></i>Withdrawn</span>
                    <?php } else { ?>
                    <span class="badge bg-secondary">No record</span>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-journal-text me-1"></i> Consent Audit Trail</div>
            <div class="card-body p-0">
                <?php if (empty($consent_history)) { ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-journal fs-1"></i>
                    <p class="mt-2 mb-0">No consent records.</p>
                </div>
                <?php } else { ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Action</th>
                                <th>Version</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($consent_history as $entry) { ?>
                            <tr>
                                <td class="small"><?= h(date('M j, Y g:i A', strtotime($entry['created']))) ?></td>
                                <td class="small"><?= h(str_replace('_', ' ', ucwords($entry['consent_type'], '_'))) ?></td>
                                <td>
                                    <?php if ($entry['action'] === 'granted') { ?>
                                    <span class="badge bg-success">Granted</span>
                                    <?php } else { ?>
                                    <span class="badge bg-warning text-dark">Withdrawn</span>
                                    <?php } ?>
                                </td>
                                <td class="small"><?= h($entry['version_label'] ?? '-') ?></td>
                                <td class="small"><code><?= h($entry['ip_address'] ?? '') ?></code></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php } elseif ($active_tab === 'logins') { ?>
<!-- ═══ LOGIN HISTORY TAB ═══ -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1"></i> Login History (Last 20)</span>
        <?php
        $total_logins = function_exists('count_login_history') ? count_login_history($user_id) : 0;
        $success_count = 0;
        $failed_count = 0;
        foreach ($login_history as $lh) {
            if ($lh['status'] === 'success') $success_count++;
            else $failed_count++;
        }
        ?>
        <div>
            <span class="badge bg-success"><?= $success_count ?> successful</span>
            <span class="badge bg-danger"><?= $failed_count ?> failed</span>
            <span class="badge bg-secondary"><?= $total_logins ?> total</span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($login_history)) { ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-clock fs-1"></i>
            <p class="mt-2 mb-0">No login history recorded.</p>
        </div>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Browser</th>
                        <th>IP Address</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>User Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($login_history as $entry) { ?>
                    <tr>
                        <td class="small"><?= h(date('M j, Y g:i A', strtotime($entry['created']))) ?></td>
                        <td class="small"><?= h($entry['browser'] ?? 'Unknown') ?></td>
                        <td class="small"><code><?= h($entry['ip_address'] ?? '') ?></code></td>
                        <td class="small">
                            <?php
                            $method_icons = ['password' => 'bi-key', 'oauth' => 'bi-cloud', 'remember_me' => 'bi-cookie', 'saml' => 'bi-building', '2fa' => 'bi-shield-lock'];
                            $icon = $method_icons[$entry['login_method']] ?? 'bi-box-arrow-in-right';
                            ?>
                            <i class="bi <?= $icon ?>" title="<?= h($entry['login_method']) ?>"></i>
                            <?= h($entry['login_method']) ?>
                        </td>
                        <td>
                            <?php if ($entry['status'] === 'success') { ?>
                            <span class="badge bg-success">Success</span>
                            <?php } else { ?>
                            <span class="badge bg-danger">Failed</span>
                            <?php } ?>
                        </td>
                        <td class="small text-truncate" style="max-width: 200px;" title="<?= h($entry['user_agent'] ?? '') ?>">
                            <?= h($entry['user_agent'] ?? '') ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </div>
</div>

<?php } elseif ($active_tab === 'sessions') { ?>
<!-- ═══ ACTIVE SESSIONS TAB ═══ -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-laptop me-1"></i> Active Sessions</span>
        <?php if (count($active_devices) > 0) { ?>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="adminRevokeAllSessions">
            <input type="hidden" name="user_id" value="<?= h($user_id) ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke all sessions for this user? They will be logged out everywhere.')">
                <i class="bi bi-box-arrow-right me-1"></i>Revoke All Sessions
            </button>
        </form>
        <?php } ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($active_devices)) { ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-laptop fs-1"></i>
            <p class="mt-2 mb-0">No active sessions.</p>
        </div>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Browser</th>
                        <th>IP Address</th>
                        <th>Last Active</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_devices as $device) { ?>
                    <tr>
                        <td class="small">
                            <i class="bi bi-<?= ($device['browser'] ?? '') === 'Chrome' ? 'browser-chrome' : (($device['browser'] ?? '') === 'Safari' ? 'browser-safari' : (($device['browser'] ?? '') === 'Firefox' ? 'browser-firefox' : (($device['browser'] ?? '') === 'Edge' ? 'browser-edge' : 'globe'))) ?> me-1"></i>
                            <?= h($device['browser'] ?? 'Unknown') ?>
                        </td>
                        <td class="small"><code><?= h($device['ip_address'] ?? '') ?></code></td>
                        <td class="small"><?= $device['last_used'] ? h(date('M j, g:i A', strtotime($device['last_used']))) : 'Unknown' ?></td>
                        <td class="small"><?= h(date('M j, Y', strtotime($device['created']))) ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="adminRevokeSession">
                                <input type="hidden" name="user_id" value="<?= h($user_id) ?>">
                                <input type="hidden" name="device_id" value="<?= (int)$device['device_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Revoke this session" onclick="return confirm('Revoke this session?')">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </div>
</div>

<?php } elseif ($active_tab === 'data') { ?>
<!-- ═══ DATA & DELETION TAB ═══ -->
<div class="row g-4">
    <!-- Data Export Status -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-download me-1"></i> Data Export</div>
            <div class="card-body">
                <?php if ($latest_export) { ?>
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="small">Status</th>
                        <td>
                            <?php
                            $export_badges = ['pending' => 'bg-warning text-dark', 'processing' => 'bg-info', 'ready' => 'bg-success', 'expired' => 'bg-secondary'];
                            ?>
                            <span class="badge <?= $export_badges[$latest_export['status']] ?? 'bg-secondary' ?>"><?= h(ucfirst($latest_export['status'])) ?></span>
                        </td>
                    </tr>
                    <tr><th class="small">Format</th><td class="small"><?= h(strtoupper($latest_export['format'])) ?></td></tr>
                    <tr><th class="small">Requested</th><td class="small"><?= h(date('M j, Y g:i A', strtotime($latest_export['requested_at']))) ?></td></tr>
                    <?php if ($latest_export['completed_at']) { ?>
                    <tr><th class="small">Completed</th><td class="small"><?= h(date('M j, Y g:i A', strtotime($latest_export['completed_at']))) ?></td></tr>
                    <?php } ?>
                    <?php if ($latest_export['file_size']) { ?>
                    <tr><th class="small">Size</th><td class="small"><?= h(number_format($latest_export['file_size'] / 1024, 1)) ?> KB</td></tr>
                    <?php } ?>
                    <?php if ($latest_export['expires_at']) { ?>
                    <tr>
                        <th class="small">Expires</th>
                        <td class="small">
                            <?= h(date('M j, Y', strtotime($latest_export['expires_at']))) ?>
                            <?php if (strtotime($latest_export['expires_at']) < time()) { ?>
                            <span class="badge bg-secondary">Expired</span>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                </table>
                <?php } else { ?>
                <p class="text-muted mb-0">No data export requests from this user.</p>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- Account Deletion -->
    <div class="col-lg-6">
        <div class="card h-100 <?= $pending_deletion ? 'border-danger' : '' ?>">
            <div class="card-header <?= $pending_deletion ? 'bg-danger bg-opacity-10 text-danger' : '' ?>">
                <i class="bi bi-exclamation-triangle me-1"></i> Account Deletion
            </div>
            <div class="card-body">
                <?php if ($pending_deletion) { ?>
                <div class="alert alert-danger mb-3">
                    <h6 class="alert-heading mb-1"><i class="bi bi-clock-history me-1"></i> Deletion Scheduled</h6>
                    <p class="mb-1 small">
                        Scheduled for <strong><?= h(date('F j, Y', strtotime($pending_deletion['cancel_before']))) ?></strong>.
                    </p>
                    <p class="mb-0 small">
                        Requested on <?= h(date('M j, Y \\a\\t g:i A', strtotime($pending_deletion['requested_at']))) ?>.
                        <?php if ($pending_deletion['reason']) { ?>
                        <br>Reason: <em><?= h($pending_deletion['reason']) ?></em>
                        <?php } ?>
                    </p>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="adminCancelDeletion">
                    <input type="hidden" name="user_id" value="<?= h($user_id) ?>">
                    <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Cancel this user\'s deletion request?')">
                        <i class="bi bi-x-circle me-1"></i> Cancel Deletion Request
                    </button>
                </form>
                <?php } else { ?>
                <p class="text-muted mb-0"><i class="bi bi-check-circle me-1"></i> No pending deletion request.</p>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<?php } ?>

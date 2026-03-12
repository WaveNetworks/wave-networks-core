<?php
/**
 * views/notification_preferences.php
 * User notification preference management — per-category frequency + push settings.
 */
$page_title  = 'Notification Preferences';
$user_id     = (int)$_SESSION['user_id'];
$shard_id    = $_SESSION['shard_id'];
$preferences = get_user_notification_preferences($user_id, $shard_id);
$push_subs   = function_exists('get_user_push_subscriptions')
    ? get_user_push_subscriptions($user_id, $shard_id)
    : [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Notification Preferences</h4>
    <a href="index.php?page=notifications" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Notifications
    </a>
</div>

<!-- Push Notification Status -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h6 class="mb-1"><i class="bi bi-broadcast"></i> Push Notifications</h6>
                <p class="text-muted small mb-0" id="pushStatus">Checking push support...</p>
            </div>
            <div>
                <button class="btn btn-sm btn-primary d-none" id="enablePushBtn" onclick="subscribeToPush()">
                    <i class="bi bi-bell"></i> Enable Push
                </button>
                <button class="btn btn-sm btn-outline-danger d-none" id="disablePushBtn" onclick="unsubscribeFromPush()">
                    <i class="bi bi-bell-slash"></i> Disable Push
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Category Preferences -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">Category Settings</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Description</th>
                    <th style="width: 160px;">Frequency</th>
                    <th style="width: 90px;" class="text-center">Push</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preferences as $pref) { ?>
                <tr>
                    <td>
                        <i class="bi <?= h($pref['icon']) ?> me-1"></i>
                        <strong><?= h($pref['name']) ?></strong>
                        <?php if ($pref['is_system']) { ?>
                        <span class="badge bg-secondary ms-1">System</span>
                        <?php } ?>
                    </td>
                    <td class="text-muted small"><?= h($pref['description'] ?? '') ?></td>
                    <td>
                        <?php if ($pref['allow_frequency_override']) { ?>
                        <select class="form-select form-select-sm" onchange="savePref('<?= h($pref['slug']) ?>', this.value, this.closest('tr').querySelector('[data-push]').checked ? 1 : 0)">
                            <option value="realtime" <?= $pref['frequency'] === 'realtime' ? 'selected' : '' ?>>Realtime</option>
                            <option value="daily" <?= $pref['frequency'] === 'daily' ? 'selected' : '' ?>>Daily Digest</option>
                            <option value="weekly" <?= $pref['frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly Digest</option>
                            <option value="off" <?= $pref['frequency'] === 'off' ? 'selected' : '' ?>>Off</option>
                        </select>
                        <?php } else { ?>
                        <span class="text-muted small">
                            <i class="bi bi-lock me-1"></i><?= ucfirst(h($pref['frequency'])) ?>
                        </span>
                        <?php } ?>
                    </td>
                    <td class="text-center">
                        <?php if ($pref['allow_frequency_override']) { ?>
                        <div class="form-check form-switch d-flex justify-content-center">
                            <input class="form-check-input" type="checkbox" data-push
                                   <?= $pref['push_enabled'] ? 'checked' : '' ?>
                                   onchange="savePref('<?= h($pref['slug']) ?>', this.closest('tr').querySelector('select').value, this.checked ? 1 : 0)">
                        </div>
                        <?php } else { ?>
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Push Subscriptions (Devices) -->
<?php if (!empty($push_subs)) { ?>
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Subscribed Devices</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Browser</th>
                    <th>Registered</th>
                    <th>Last Used</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($push_subs as $sub) { ?>
                <tr>
                    <td class="small"><?= h($sub['user_agent'] ? substr($sub['user_agent'], 0, 80) : 'Unknown') ?></td>
                    <td class="small text-muted"><?= h($sub['created'] ? date('M j, Y', strtotime($sub['created'])) : '-') ?></td>
                    <td class="small text-muted"><?= h($sub['last_used'] ? date('M j, Y', strtotime($sub['last_used'])) : 'Never') ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<script>
function savePref(slug, frequency, pushEnabled) {
    apiPost('saveNotificationPreference', {
        category_slug: slug,
        frequency: frequency,
        push_enabled: pushEnabled
    });
}
</script>

<?php
/**
 * views/notifications.php
 * Full notification list page with infinite scroll.
 */
$page_title = 'Notifications';
$user_id    = (int)$_SESSION['user_id'];
$shard_id   = $_SESSION['shard_id'];

$notifications = get_user_notifications($user_id, $shard_id, 50, 0);
$unread_count  = get_unread_count($user_id, $shard_id);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Notifications</h4>
    <div>
        <?php if ($unread_count > 0) { ?>
        <button class="btn btn-sm btn-outline-secondary me-2" onclick="apiPost('markAllNotificationsRead', {}, function() { location.reload(); })">
            <i class="bi bi-check-all"></i> Mark all read
        </button>
        <?php } ?>
        <a href="index.php?page=notification_preferences" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-gear"></i> Preferences
        </a>
    </div>
</div>

<?php if (empty($notifications)) { ?>
<div class="text-center py-5 text-muted">
    <i class="bi bi-bell-slash" style="font-size: 3rem;"></i>
    <p class="mt-3">No notifications yet</p>
</div>
<?php } else { ?>
<div class="list-group">
    <?php foreach ($notifications as $n) { ?>
    <?php $is_read = (int)$n['is_read']; ?>
    <div class="list-group-item list-group-item-action <?= $is_read ? 'opacity-75' : '' ?>" data-notif-id="<?= (int)$n['notification_id'] ?>">
        <div class="d-flex align-items-start">
            <div class="me-3 mt-1">
                <i class="bi <?= h($n['category_icon']) ?> <?= $is_read ? 'text-muted' : 'text-primary' ?>" style="font-size: 1.25rem;"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start">
                    <h6 class="mb-1 <?= $is_read ? '' : 'fw-bold' ?>"><?= h($n['title']) ?></h6>
                    <small class="text-muted ms-2 text-nowrap"><?= h(date('M j, g:ia', strtotime($n['created']))) ?></small>
                </div>
                <?php if ($n['body']) { ?>
                <p class="mb-1 text-muted small"><?= h($n['body']) ?></p>
                <?php } ?>
                <div class="d-flex align-items-center">
                    <span class="badge bg-secondary bg-opacity-25 text-dark small me-2"><?= h($n['category_name']) ?></span>
                    <?php if ($n['source_app']) { ?>
                    <span class="text-muted small">via <?= h($n['source_app']) ?></span>
                    <?php } ?>
                    <?php if ($n['action_url']) { ?>
                    <a href="<?= h($n['action_url']) ?>" class="ms-auto btn btn-sm btn-outline-primary">
                        <?= h($n['action_label'] ?: 'View') ?> <i class="bi bi-arrow-right"></i>
                    </a>
                    <?php } ?>
                    <?php if (!$is_read) { ?>
                    <button class="ms-auto btn btn-sm btn-link text-muted p-0" onclick="markRead(this, <?= (int)$n['notification_id'] ?>)" title="Mark as read">
                        <i class="bi bi-check2"></i>
                    </button>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
</div>
<?php } ?>

<script>
function markRead(btn, notifId) {
    apiPost('markNotificationRead', { notification_id: notifId }, function() {
        var item = btn.closest('.list-group-item');
        if (item) {
            item.classList.add('opacity-75');
            btn.remove();
        }
    });
}
</script>

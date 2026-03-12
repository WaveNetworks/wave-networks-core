<?php
/**
 * API Actions (public + authenticated)
 * Actions: keepAlive
 *
 * NOTE: getNotifications and markNotificationRead moved to
 * memberActions/notificationActions.php (shard-aware versions).
 */

// ─── KEEP ALIVE ──────────────────────────────────────────────────────────────

if (($action ?? null) == 'keepAlive') {
    $data['alive'] = true;
    $data['timestamp'] = date('c');
    $_SESSION['success'] = 'OK';
}

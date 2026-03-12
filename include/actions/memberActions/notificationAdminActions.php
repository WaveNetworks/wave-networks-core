<?php
/**
 * Notification Admin Actions (admin only)
 * Actions: getNotificationCategories, saveNotificationCategory,
 *          deleteNotificationCategory, sendBroadcastNotification
 */

// ─── GET CATEGORIES ─────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'getNotificationCategories') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $data['categories'] = get_notification_categories();
        $_SESSION['success'] = 'Categories loaded.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── SAVE CATEGORY ──────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'saveNotificationCategory') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $category_id    = (int)($_POST['category_id'] ?? 0);
    $slug           = trim($_POST['slug'] ?? '');
    $name           = trim($_POST['name'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $icon           = trim($_POST['icon'] ?? 'bi-bell');
    $allow_override = (int)($_POST['allow_frequency_override'] ?? 1);
    $default_freq   = trim($_POST['default_frequency'] ?? 'realtime');

    if (!$slug) { $errs['slug'] = 'Slug is required.'; }
    if (!$name) { $errs['name'] = 'Name is required.'; }
    if (!preg_match('/^[a-z0-9_]+$/', $slug)) { $errs['slug'] = 'Slug must be lowercase letters, numbers, and underscores only.'; }
    if (!in_array($default_freq, ['realtime', 'daily', 'weekly', 'off'])) {
        $errs['frequency'] = 'Invalid default frequency.';
    }

    if (count($errs) <= 0) {
        global $db;
        $s_slug  = sanitize($slug, SQL);
        $s_name  = sanitize($name, SQL);
        $s_desc  = sanitize($description, SQL);
        $s_icon  = sanitize($icon, SQL);
        $s_freq  = sanitize($default_freq, SQL);

        if ($category_id > 0) {
            // Update — don't allow changing slug of system categories
            $existing = db_fetch(db_query("SELECT * FROM notification_category WHERE category_id = '$category_id'"));
            if ($existing && $existing['is_system'] && $existing['slug'] !== $slug) {
                $errs['slug'] = 'Cannot change slug of a system category.';
            }

            if (count($errs) <= 0) {
                $r = db_query("UPDATE notification_category SET
                    slug = '$s_slug', name = '$s_name', description = '$s_desc',
                    icon = '$s_icon', allow_frequency_override = '$allow_override',
                    default_frequency = '$s_freq'
                    WHERE category_id = '$category_id'");
                if ($r) {
                    $_SESSION['success'] = 'Category updated.';
                } else {
                    $errs['db'] = db_error();
                }
            }
        } else {
            $r = db_query("INSERT INTO notification_category
                (slug, name, description, icon, is_system, allow_frequency_override, default_frequency)
                VALUES ('$s_slug', '$s_name', '$s_desc', '$s_icon', 0, '$allow_override', '$s_freq')");
            if ($r) {
                $data['category_id'] = db_insert_id();
                $_SESSION['success'] = 'Category created.';
            } else {
                $errs['db'] = db_error();
            }
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── DELETE CATEGORY ────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'deleteNotificationCategory') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $category_id = (int)($_POST['category_id'] ?? 0);
    if (!$category_id) { $errs['id'] = 'Category ID required.'; }

    if (count($errs) <= 0) {
        $cat = db_fetch(db_query("SELECT * FROM notification_category WHERE category_id = '$category_id'"));
        if (!$cat) {
            $errs['id'] = 'Category not found.';
        } elseif ($cat['is_system']) {
            $errs['system'] = 'System categories cannot be deleted.';
        }
    }

    if (count($errs) <= 0) {
        db_query("DELETE FROM notification_category WHERE category_id = '$category_id'");
        $_SESSION['success'] = 'Category deleted.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── SEND BROADCAST ─────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'sendBroadcastNotification') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $category_slug = trim($_POST['category_slug'] ?? 'admin_broadcast');
    $title         = trim($_POST['title'] ?? '');
    $body          = trim($_POST['body'] ?? '');
    $action_url    = trim($_POST['action_url'] ?? '');
    $action_label  = trim($_POST['action_label'] ?? '');

    if (!$title) { $errs['title'] = 'Title is required.'; }
    if (!$body)  { $errs['body'] = 'Message body is required.'; }

    if (count($errs) <= 0) {
        $opts = ['source_app' => 'admin'];
        if ($action_url)   { $opts['action_url'] = $action_url; }
        if ($action_label) { $opts['action_label'] = $action_label; }

        $count = broadcast_notification($category_slug, $title, $body, $opts);
        $data['sent_count'] = $count;
        $_SESSION['success'] = "Broadcast sent to $count users.";
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

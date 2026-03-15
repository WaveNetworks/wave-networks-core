<?php
/**
 * Branding Actions
 * Actions: saveBranding
 */

if (($action ?? null) == 'saveBranding') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $site_name       = trim($_POST['site_name'] ?? '');
    $site_short_name = trim($_POST['site_short_name'] ?? '');
    $site_description = trim($_POST['site_description'] ?? '');
    $theme_color     = trim($_POST['theme_color'] ?? '#212529');

    if (strlen($site_name) > 100)       { $errs['site_name'] = 'Site name must be 100 characters or fewer.'; }
    if (strlen($site_short_name) > 30)  { $errs['site_short_name'] = 'Short name must be 30 characters or fewer.'; }
    if (strlen($site_description) > 255) { $errs['site_description'] = 'Description must be 255 characters or fewer.'; }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_color)) { $errs['theme_color'] = 'Invalid theme color.'; }

    $allowed_image_types = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'];
    $max_file_size = 2 * 1024 * 1024; // 2 MB
    $uploads_dir = __DIR__ . '/../../../uploads';
    if (!is_dir($uploads_dir)) { mkdir($uploads_dir, 0755, true); }

    // Handle logo upload
    $logo_path = null;
    $logo_updated = false;
    if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        if (!in_array($_FILES['logo']['type'], $allowed_image_types)) {
            $errs['logo'] = 'Logo must be PNG, JPG, SVG, ICO, or WebP.';
        } elseif ($_FILES['logo']['size'] > $max_file_size) {
            $errs['logo'] = 'Logo must be under 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $logo_path = 'branding_logo.' . $ext;
            $logo_updated = true;
        }
    }

    // Handle dark mode logo upload
    $logo_dark_path = null;
    $logo_dark_updated = false;
    if (!empty($_POST['remove_logo_dark'])) {
        $logo_dark_updated = true;
    } elseif (!empty($_FILES['logo_dark']['name']) && $_FILES['logo_dark']['error'] === UPLOAD_ERR_OK) {
        if (!in_array($_FILES['logo_dark']['type'], $allowed_image_types)) {
            $errs['logo_dark'] = 'Dark logo must be PNG, JPG, SVG, ICO, or WebP.';
        } elseif ($_FILES['logo_dark']['size'] > $max_file_size) {
            $errs['logo_dark'] = 'Dark logo must be under 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['logo_dark']['name'], PATHINFO_EXTENSION));
            $logo_dark_path = 'branding_logo_dark.' . $ext;
            $logo_dark_updated = true;
        }
    }

    // Handle favicon upload
    $favicon_path = null;
    $favicon_updated = false;
    if (!empty($_FILES['favicon']['name']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        if (!in_array($_FILES['favicon']['type'], $allowed_image_types)) {
            $errs['favicon'] = 'Favicon must be PNG, JPG, SVG, ICO, or WebP.';
        } elseif ($_FILES['favicon']['size'] > $max_file_size) {
            $errs['favicon'] = 'Favicon must be under 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
            $favicon_path = 'branding_favicon.' . $ext;
            $favicon_updated = true;
        }
    }

    // Handle PWA screenshot uploads (raster only — PNG/JPG/WebP)
    $allowed_screenshot_types = ['image/png', 'image/jpeg', 'image/webp'];
    $screenshot_wide_path = null;
    $screenshot_wide_updated = false;
    if (!empty($_FILES['pwa_screenshot_wide']['name']) && $_FILES['pwa_screenshot_wide']['error'] === UPLOAD_ERR_OK) {
        if (!in_array($_FILES['pwa_screenshot_wide']['type'], $allowed_screenshot_types)) {
            $errs['pwa_screenshot_wide'] = 'Desktop screenshot must be PNG, JPG, or WebP.';
        } elseif ($_FILES['pwa_screenshot_wide']['size'] > $max_file_size) {
            $errs['pwa_screenshot_wide'] = 'Desktop screenshot must be under 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['pwa_screenshot_wide']['name'], PATHINFO_EXTENSION));
            $screenshot_wide_path = 'pwa_screenshot_wide.' . $ext;
            $screenshot_wide_updated = true;
        }
    }

    $screenshot_mobile_path = null;
    $screenshot_mobile_updated = false;
    if (!empty($_FILES['pwa_screenshot_mobile']['name']) && $_FILES['pwa_screenshot_mobile']['error'] === UPLOAD_ERR_OK) {
        if (!in_array($_FILES['pwa_screenshot_mobile']['type'], $allowed_screenshot_types)) {
            $errs['pwa_screenshot_mobile'] = 'Mobile screenshot must be PNG, JPG, or WebP.';
        } elseif ($_FILES['pwa_screenshot_mobile']['size'] > $max_file_size) {
            $errs['pwa_screenshot_mobile'] = 'Mobile screenshot must be under 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['pwa_screenshot_mobile']['name'], PATHINFO_EXTENSION));
            $screenshot_mobile_path = 'pwa_screenshot_mobile.' . $ext;
            $screenshot_mobile_updated = true;
        }
    }

    if (count($errs) <= 0) {
        // Move uploaded files
        if ($logo_updated) {
            foreach (glob($uploads_dir . '/branding_logo.*') as $old) { unlink($old); }
            move_uploaded_file($_FILES['logo']['tmp_name'], $uploads_dir . '/' . $logo_path);
        }
        if ($logo_dark_updated) {
            foreach (glob($uploads_dir . '/branding_logo_dark.*') as $old) { unlink($old); }
            if ($logo_dark_path) {
                move_uploaded_file($_FILES['logo_dark']['tmp_name'], $uploads_dir . '/' . $logo_dark_path);
            }
        }
        if ($favicon_updated) {
            foreach (glob($uploads_dir . '/branding_favicon.*') as $old) { unlink($old); }
            move_uploaded_file($_FILES['favicon']['tmp_name'], $uploads_dir . '/' . $favicon_path);
            // Auto-generate PWA icon PNGs from favicon
            if (function_exists('generate_pwa_icons')) {
                generate_pwa_icons($uploads_dir . '/' . $favicon_path, $uploads_dir);
            }
        }
        if ($screenshot_wide_updated) {
            foreach (glob($uploads_dir . '/pwa_screenshot_wide.*') as $old) { unlink($old); }
            move_uploaded_file($_FILES['pwa_screenshot_wide']['tmp_name'], $uploads_dir . '/' . $screenshot_wide_path);
        }
        if ($screenshot_mobile_updated) {
            foreach (glob($uploads_dir . '/pwa_screenshot_mobile.*') as $old) { unlink($old); }
            move_uploaded_file($_FILES['pwa_screenshot_mobile']['tmp_name'], $uploads_dir . '/' . $screenshot_mobile_path);
        }

        // Build UPDATE query
        $safe_name  = sanitize($site_name, SQL);
        $safe_short = sanitize($site_short_name, SQL);
        $safe_desc  = sanitize($site_description, SQL);
        $safe_color = sanitize($theme_color, SQL);

        $sql = "UPDATE auth_settings SET
            site_name = '$safe_name',
            site_short_name = '$safe_short',
            site_description = '$safe_desc',
            theme_color = '$safe_color'";

        if ($logo_updated) {
            $safe_logo = sanitize($logo_path, SQL);
            $sql .= ", logo_path = '$safe_logo'";
        }
        if ($logo_dark_updated) {
            if ($logo_dark_path) {
                $safe_logo_dark = sanitize($logo_dark_path, SQL);
                $sql .= ", logo_dark_path = '$safe_logo_dark'";
            } else {
                $sql .= ", logo_dark_path = NULL";
            }
        }
        if ($favicon_updated) {
            $safe_favicon = sanitize($favicon_path, SQL);
            $sql .= ", favicon_path = '$safe_favicon'";
        }
        if ($screenshot_wide_updated) {
            $safe_sw = sanitize($screenshot_wide_path, SQL);
            $sql .= ", pwa_screenshot_wide = '$safe_sw'";
        }
        if ($screenshot_mobile_updated) {
            $safe_sm = sanitize($screenshot_mobile_path, SQL);
            $sql .= ", pwa_screenshot_mobile = '$safe_sm'";
        }

        $sql .= " WHERE setting_id = 1";

        $r = db_query($sql);
        if (!$r) {
            $_SESSION['error'] = db_error();
        } else {
            $_SESSION['success'] = 'Branding settings saved.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

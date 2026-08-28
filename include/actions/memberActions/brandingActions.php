<?php
/**
 * Branding Actions
 * Actions: saveBranding
 */

if (($action ?? null) == 'saveBranding') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $site_name            = trim($_POST['site_name'] ?? '');
    $site_short_name      = trim($_POST['site_short_name'] ?? '');
    $site_description     = trim($_POST['site_description'] ?? '');
    $theme_color_light    = trim($_POST['theme_color_light'] ?? '#ffffff');
    $theme_color_dark     = trim($_POST['theme_color_dark'] ?? '#212529');
    $background_color_light = trim($_POST['background_color_light'] ?? '#ffffff');
    $background_color_dark  = trim($_POST['background_color_dark'] ?? '#212529');

    if (strlen($site_name) > 100)        { $errs['site_name'] = 'Site name must be 100 characters or fewer.'; }
    if (strlen($site_short_name) > 30)   { $errs['site_short_name'] = 'Short name must be 30 characters or fewer.'; }
    if (strlen($site_description) > 255) { $errs['site_description'] = 'Description must be 255 characters or fewer.'; }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_color_light))    { $errs['theme_color_light'] = 'Invalid light mode theme color.'; }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme_color_dark))     { $errs['theme_color_dark'] = 'Invalid dark mode theme color.'; }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $background_color_light)) { $errs['background_color_light'] = 'Invalid light mode background color.'; }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $background_color_dark))  { $errs['background_color_dark'] = 'Invalid dark mode background color.'; }

    $allowed_image_types = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'];
    $max_file_size = 2 * 1024 * 1024; // 2 MB
    $uploads_dir = rtrim($files_location, '/') . '/branding';
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

    $screenshot_tablet_path = null;
    $screenshot_tablet_updated = false;
    if (!empty($_FILES['pwa_screenshot_tablet']['name']) && $_FILES['pwa_screenshot_tablet']['error'] === UPLOAD_ERR_OK) {
        if (!in_array($_FILES['pwa_screenshot_tablet']['type'], $allowed_screenshot_types)) {
            $errs['pwa_screenshot_tablet'] = 'Tablet screenshot must be PNG, JPG, or WebP.';
        } elseif ($_FILES['pwa_screenshot_tablet']['size'] > $max_file_size) {
            $errs['pwa_screenshot_tablet'] = 'Tablet screenshot must be under 2 MB.';
        } else {
            $ext = strtolower(pathinfo($_FILES['pwa_screenshot_tablet']['name'], PATHINFO_EXTENSION));
            $screenshot_tablet_path = 'pwa_screenshot_tablet.' . $ext;
            $screenshot_tablet_updated = true;
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

    // ── Compliance (docs/Branding.md) ────────────────────────────────────────
    // The checks above ask "is this a plausible image file?". That is not enough: pwt
    // shipped a favicon that was a valid PNG of the right size, HTTP 200, and a blank
    // white square, and it passed every one of them. Validate the actual pixels before
    // anything is written, while the upload is still a temp file.
    //
    // Each asset is judged against the surface it will really sit on, which is what
    // catches a light mark uploaded as the light-mode logo.
    $branding_warnings = [];
    if (function_exists('branding_validate_asset')) {
        $to_check = [
            'logo'                  => ['slot' => 'logo_light', 'bg' => $background_color_light],
            'logo_dark'             => ['slot' => 'logo_dark',  'bg' => $background_color_dark],
            // The favicon is rasterised into every app icon, and iOS flattens home-screen
            // icons onto white — so white is the surface that decides whether it survives.
            'favicon'               => ['slot' => 'favicon',    'bg' => '#ffffff'],
            'pwa_screenshot_wide'   => ['slot' => 'pwa_screenshot_wide',   'bg' => null],
            'pwa_screenshot_tablet' => ['slot' => 'pwa_screenshot_tablet', 'bg' => null],
            'pwa_screenshot_mobile' => ['slot' => 'pwa_screenshot_mobile', 'bg' => null],
        ];
        foreach ($to_check as $field => $cfg) {
            if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) continue;
            if (isset($errs[$field])) continue;              // already rejected above
            $tmp = $_FILES[$field]['tmp_name'];
            if (!is_readable($tmp)) continue;

            // getimagesize() and the PNG header read need the real extension to reason
            // about format, and a temp upload has none.
            $ext   = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            $probe = $tmp . '.' . $ext;
            if (!@copy($tmp, $probe)) continue;

            $ctx = [];
            if (!empty($cfg['bg'])) $ctx['background'] = $cfg['bg'];
            $res = branding_validate_asset($cfg['slot'], $probe, $ctx);
            @unlink($probe);

            foreach ($res['violations'] as $v) {
                if ($v['level'] === BRANDING_BLOCK) {
                    // First block per field wins — stacking every rule on one upload is
                    // noise, and the first is the one to fix.
                    if (!isset($errs[$field])) $errs[$field] = $v['message'];
                } else {
                    $branding_warnings[] = $v['message'];
                }
            }
        }
    }

    // Store-tier slots (Apple / Play). File-discovered rather than a column per slot, so
    // adding a slot to the catalogue needs no migration — see branding_asset_filename().
    $store_uploads = [];
    if (function_exists('branding_slot_specs')) {
        foreach (branding_slot_specs('store') as $slot => $spec) {
            if (empty($_FILES[$slot]['name']) || ($_FILES[$slot]['error'] ?? 1) !== UPLOAD_ERR_OK) continue;
            if ($_FILES[$slot]['size'] > $max_file_size * 4) {   // store art is legitimately larger
                $errs[$slot] = $spec['label'] . ': file is too large.';
                continue;
            }
            $ext   = strtolower(pathinfo($_FILES[$slot]['name'], PATHINFO_EXTENSION));
            $probe = $_FILES[$slot]['tmp_name'] . '.' . $ext;
            if (!@copy($_FILES[$slot]['tmp_name'], $probe)) continue;
            $res = branding_validate_asset($slot, $probe, []);
            @unlink($probe);
            foreach ($res['violations'] as $v) {
                if ($v['level'] === BRANDING_BLOCK) {
                    if (!isset($errs[$slot])) $errs[$slot] = $v['message'];
                } else {
                    $branding_warnings[] = $v['message'];
                }
            }
            if (!isset($errs[$slot])) $store_uploads[$slot] = ['tmp' => $_FILES[$slot]['tmp_name'], 'ext' => $ext];
        }
    }

    if (count($errs) <= 0) {
        // Store-tier assets: one file per slot, previous extension cleared first so a
        // PNG replacing a JPG does not leave both on disk for the glob to find.
        foreach ($store_uploads as $slot => $u) {
            foreach (glob($uploads_dir . '/' . branding_asset_filename($slot, '*')) as $old_file) { unlink($old_file); }
            move_uploaded_file($u['tmp'], $uploads_dir . '/' . branding_asset_filename($slot, $u['ext']));
        }

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
        }

        // Regenerate the PWA icons on EVERY save that has a favicon, not only when a
        // new one is uploaded. Gating on the upload meant a bad set of icons could
        // never be repaired from the UI: pwt's were a blank white square (Imagick
        // renders SVG on opaque white — see generate_pwa_icons) and the only way to
        // refresh them was to re-upload an identical favicon. Rasterising is cheap
        // and idempotent, so "Save" is now the repair path.
        if (function_exists('generate_pwa_icons')) {
            $favicon_on_disk = $favicon_updated && $favicon_path
                ? $uploads_dir . '/' . $favicon_path
                : (glob($uploads_dir . '/branding_favicon.*')[0] ?? null);
            if ($favicon_on_disk && is_readable($favicon_on_disk)) {
                generate_pwa_icons($favicon_on_disk, $uploads_dir);
            }
        }
        if ($screenshot_wide_updated) {
            foreach (glob($uploads_dir . '/pwa_screenshot_wide.*') as $old) { unlink($old); }
            move_uploaded_file($_FILES['pwa_screenshot_wide']['tmp_name'], $uploads_dir . '/' . $screenshot_wide_path);
        }
        if ($screenshot_tablet_updated) {
            foreach (glob($uploads_dir . '/pwa_screenshot_tablet.*') as $old) { unlink($old); }
            move_uploaded_file($_FILES['pwa_screenshot_tablet']['tmp_name'], $uploads_dir . '/' . $screenshot_tablet_path);
        }
        if ($screenshot_mobile_updated) {
            foreach (glob($uploads_dir . '/pwa_screenshot_mobile.*') as $old) { unlink($old); }
            move_uploaded_file($_FILES['pwa_screenshot_mobile']['tmp_name'], $uploads_dir . '/' . $screenshot_mobile_path);
        }

        // Build UPDATE query
        $safe_name        = sanitize($site_name, SQL);
        $safe_short       = sanitize($site_short_name, SQL);
        $safe_desc        = sanitize($site_description, SQL);
        $safe_color_light = sanitize($theme_color_light, SQL);
        $safe_color_dark  = sanitize($theme_color_dark, SQL);
        $safe_bg_light    = sanitize($background_color_light, SQL);
        $safe_bg_dark     = sanitize($background_color_dark, SQL);

        $sql = "UPDATE auth_settings SET
            site_name = '$safe_name',
            site_short_name = '$safe_short',
            site_description = '$safe_desc',
            theme_color = '$safe_color_light',
            theme_color_light = '$safe_color_light',
            theme_color_dark = '$safe_color_dark',
            background_color_light = '$safe_bg_light',
            background_color_dark = '$safe_bg_dark'";

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
        if ($screenshot_tablet_updated) {
            $safe_st = sanitize($screenshot_tablet_path, SQL);
            $sql .= ", pwa_screenshot_tablet = '$safe_st'";
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
            // Saved, but not silently: a WARN is something that will look wrong on a
            // device rather than be refused by a store, so it has to be said out loud
            // or it is indistinguishable from a clean save.
            if (!empty($branding_warnings)) {
                $_SESSION['warning'] = implode('<br>', array_unique($branding_warnings));
            }
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

<?php
/**
 * brandingApiActions.php — Admin › Branding over the service API (nokemo task #2816).
 *
 * get_branding() reads auth_settings row 1, which provisioning never filled, so every new app
 * showed the core defaults: "Admin" on the login page, page titles, footer and PWA manifest.
 * nokemo's provisioning runner (scripts/app_branding.py) seeds it from the app repo here.
 *
 *   apiGetBranding (branding:admin) — the branding row (no files) + is_default (site name still core's).
 *   apiSetBranding (branding:admin) — any of site_name, site_short_name, site_description, theme_color
 *       (= light), theme_color_dark, background_color_light, background_color_dark; files as base64:
 *       logo_b64 + logo_name, logo_dark_b64 + logo_dark_name, favicon_b64 + favicon_name.
 *       Omitted fields keep their value. only_if_default=1 changes nothing unless the site name is
 *       still the core default, so a sweep can never overwrite what an admin chose (data.skipped).
 *       Same rules as saveBranding: 2 MB, PNG/JPG/SVG/ICO/WebP, branding_validate_asset (a BLOCK
 *       refuses the whole call, WARNs come back in data.warnings), PWA icons regenerated.
 */

if (!function_exists('branding_api_row')) {
    function branding_api_row() {
        $r = db_query("SELECT site_name, site_short_name, site_description, theme_color, theme_color_light,
                              theme_color_dark, background_color_light, background_color_dark,
                              logo_path, logo_dark_path, favicon_path
                         FROM auth_settings WHERE setting_id = 1");
        return $r ? (db_fetch($r) ?: null) : null;
    }

    function branding_api_is_default($row) {
        $n = trim((string) ($row['site_name'] ?? ''));
        return $n === '' || $n === 'Admin';
    }
}

if (($action ?? null) == 'apiGetBranding') {
    if (require_api_scope('branding:admin')) {
        $row = branding_api_row();
        $data['branding']   = $row;
        $data['is_default'] = $row ? branding_api_is_default($row) : true;
        $_SESSION['success'] = 'OK';
    }
}

if (($action ?? null) == 'apiSetBranding') {
    if (require_api_scope('branding:admin')) {
        $errs = [];
        $row  = branding_api_row();
        if (!$row) { $errs[] = 'auth_settings row 1 is missing (core migrations not run).'; }

        if (!$errs && !empty($_POST['only_if_default']) && !branding_api_is_default($row)) {
            $data['skipped']  = true;
            $data['branding'] = $row;
            $_SESSION['success'] = 'Branding already set on this app; nothing changed.';
        } elseif (!$errs) {
            $set = [];
            $text = ['site_name' => 100, 'site_short_name' => 30, 'site_description' => 255];
            foreach ($text as $f => $max) {
                if (!isset($_POST[$f])) continue;
                $v = trim((string) $_POST[$f]);
                if (mb_strlen($v) > $max) { $errs[] = "$f must be $max characters or fewer."; continue; }
                $set[$f] = $v;
            }
            $colors = ['theme_color', 'theme_color_dark', 'background_color_light', 'background_color_dark'];
            foreach ($colors as $f) {
                if (!isset($_POST[$f]) || $_POST[$f] === '') continue;
                $v = trim((string) $_POST[$f]);
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) { $errs[] = "$f must be #rrggbb."; continue; }
                $set[$f] = $v;
            }
            if (isset($set['theme_color'])) { $set['theme_color_light'] = $set['theme_color']; }

            $bg_light = $set['background_color_light'] ?? ($row['background_color_light'] ?: '#ffffff');
            $bg_dark  = $set['background_color_dark']  ?? ($row['background_color_dark']  ?: '#212529');
            $files = [
                'logo'      => ['column' => 'logo_path',      'base' => 'branding_logo',      'slot' => 'logo_light', 'bg' => $bg_light],
                'logo_dark' => ['column' => 'logo_dark_path', 'base' => 'branding_logo_dark', 'slot' => 'logo_dark',  'bg' => $bg_dark],
                'favicon'   => ['column' => 'favicon_path',   'base' => 'branding_favicon',   'slot' => 'favicon',    'bg' => '#ffffff'],
            ];
            $allowed_ext = ['png', 'jpg', 'jpeg', 'svg', 'ico', 'webp'];
            $allowed_mime = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'];
            $uploads_dir = rtrim($GLOBALS['files_location'] ?? (__DIR__ . '/../../../../files/'), '/') . '/branding';
            if (!is_dir($uploads_dir)) { @mkdir($uploads_dir, 0755, true); }
            $staged = [];
            $warnings = [];
            foreach ($files as $field => $cfg) {
                $b64 = (string) ($_POST[$field . '_b64'] ?? '');
                if ($b64 === '') continue;
                $name = (string) ($_POST[$field . '_name'] ?? '');
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $bytes = base64_decode($b64, true);
                if (!in_array($ext, $allowed_ext, true)) { $errs[] = "$field: must be PNG, JPG, SVG, ICO or WebP ({$field}_name with its extension)."; continue; }
                if ($bytes === false || $bytes === '') { $errs[] = "$field: not valid base64."; continue; }
                if (strlen($bytes) > 2 * 1024 * 1024) { $errs[] = "$field: must be under 2 MB."; continue; }
                $tmp = tempnam(sys_get_temp_dir(), 'wnbrand') ;
                $probe = $tmp . '.' . $ext;
                @unlink($tmp);
                if (@file_put_contents($probe, $bytes) === false) { $errs[] = "$field: could not stage the file."; continue; }
                $mime = function_exists('mime_content_type') ? (string) @mime_content_type($probe) : '';
                $is_svg = $ext === 'svg' && stripos(substr($bytes, 0, 2048), '<svg') !== false;
                if (!$is_svg && $mime !== '' && !in_array($mime, $allowed_mime, true)) {
                    @unlink($probe);
                    $errs[] = "$field: the file content is $mime, not an image.";
                    continue;
                }
                if (function_exists('branding_validate_asset')) {
                    $res = branding_validate_asset($cfg['slot'], $probe, ['background' => $cfg['bg']]);
                    $blocked = false;
                    foreach ($res['violations'] as $v) {
                        if ($v['level'] === BRANDING_BLOCK) {
                            if (!$blocked) { $errs[] = $v['message']; }
                            $blocked = true;
                        } else {
                            $warnings[] = $v['message'];
                        }
                    }
                    if ($blocked) { @unlink($probe); continue; }
                }
                $staged[$field] = ['probe' => $probe, 'file' => $cfg['base'] . '.' . $ext, 'cfg' => $cfg];
            }

            if ($errs) {
                foreach ($staged as $s) { @unlink($s['probe']); }
            } elseif (!$set && !$staged) {
                $errs[] = 'Nothing to set.';
            } else {
                foreach ($staged as $field => $s) {
                    foreach (glob($uploads_dir . '/' . $s['cfg']['base'] . '.*') ?: [] as $old) { @unlink($old); }
                    if (!@rename($s['probe'], $uploads_dir . '/' . $s['file'])) {
                        @copy($s['probe'], $uploads_dir . '/' . $s['file']);
                        @unlink($s['probe']);
                    }
                    $set[$s['cfg']['column']] = $s['file'];
                }
                // Same repair path as saveBranding: icons follow whatever favicon is on disk.
                if (function_exists('generate_pwa_icons')) {
                    $fav = glob($uploads_dir . '/branding_favicon.*')[0] ?? null;
                    if ($fav && is_readable($fav)) { generate_pwa_icons($fav, $uploads_dir); }
                }
                $parts = [];
                foreach ($set as $col => $v) { $parts[] = "$col = '" . sanitize($v, SQL) . "'"; }
                if (!db_query("UPDATE auth_settings SET " . implode(', ', $parts) . " WHERE setting_id = 1")) {
                    $errs[] = 'Could not save the branding settings.';
                } else {
                    $data['branding'] = branding_api_row();
                    $data['updated']  = array_keys($set);
                    $data['warnings'] = array_values(array_unique($warnings));
                    $_SESSION['success'] = 'Branding saved.';
                }
            }
            if ($errs) { $_SESSION['error'] = implode(' ', $errs); }
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

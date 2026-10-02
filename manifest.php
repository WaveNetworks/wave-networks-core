<?php
/**
 * manifest.php
 * Dynamic PWA manifest generated from branding settings.
 */
header('Content-Type: application/manifest+json');

require_once __DIR__ . '/vendor/autoload.php';

// Load config
$configFile = __DIR__ . '/config/config.php';
if (file_exists($configFile)) {
    include($configFile);
} else {
    $dbHostSpec = getenv('DB_HOST_MAIN') ?: 'localhost';
    $dbInstance = getenv('DB_NAME_MAIN') ?: 'wncore_main';
    $dbUserName = getenv('DB_USER')     ?: 'root';
    $dbPassword = getenv('DB_PASSWORD') ?: '';
}

// Connect
try {
    $db = new PDO("mysql:host=$dbHostSpec;dbname=$dbInstance;charset=utf8mb4", $dbUserName, $dbPassword,
        [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"]);   // one clock: UTC
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['name' => 'Admin']);
    exit;
}

// Load helpers needed for branding
foreach (glob(__DIR__ . '/include/common/*.php') as $f) { include_once($f); }

$b = get_branding();

$manifest = [
    'name'             => $b['site_name'],
    'short_name'       => $b['site_short_name'],
    'description'      => $b['site_description'],
    'id'               => './app/index.php',
    'start_url'        => 'app/index.php',
    'display'          => 'standalone',
    'theme_color'      => $b['theme_color_light'] ?? $b['theme_color'] ?? '#ffffff',
    'background_color' => $b['background_color_light'] ?? '#ffffff',
];

// Icons — prefer generated PNGs with explicit sizes, keep SVG as fallback
$icons = [];
$branding_dir = rtrim($files_location ?? '', '/') . '/branding';

// Auto-generated square PNGs (created by saveBranding action)
foreach ([192, 512] as $size) {
    $png = $branding_dir . "/pwa_icon_{$size}.png";
    if (file_exists($png)) {
        $icons[] = [
            'src'     => "branding/pwa_icon_{$size}.png",
            'sizes'   => "{$size}x{$size}",
            'type'    => 'image/png',
            'purpose' => 'any',
        ];
    }
}

// Original favicon (SVG or raster) as "any" size fallback
if (!empty($b['favicon_path'])) {
    $fav_full = $branding_dir . '/' . $b['favicon_path'];
    $fav_type = function_exists('get_image_mime') ? get_image_mime($fav_full) : 'image/png';
    $icons[] = [
        'src'   => 'branding/' . $b['favicon_path'],
        'sizes' => 'any',
        'type'  => $fav_type,
    ];
}

if (!empty($icons)) {
    $manifest['icons'] = $icons;
}

// Screenshots — for richer PWA install UI
$screenshots = [];
if (!empty($b['pwa_screenshot_wide'])) {
    $sw_path = $branding_dir . '/' . $b['pwa_screenshot_wide'];
    if (file_exists($sw_path)) {
        $sw_info = @getimagesize($sw_path);
        $screenshots[] = [
            'src'         => 'branding/' . $b['pwa_screenshot_wide'],
            'sizes'       => $sw_info ? ($sw_info[0] . 'x' . $sw_info[1]) : '1280x720',
            'type'        => function_exists('get_image_mime') ? get_image_mime($sw_path) : 'image/png',
            'form_factor' => 'wide',
            'label'       => $b['site_name'] . ' — Desktop',
        ];
    }
}
if (!empty($b['pwa_screenshot_tablet'])) {
    $st_path = $branding_dir . '/' . $b['pwa_screenshot_tablet'];
    if (file_exists($st_path)) {
        $st_info = @getimagesize($st_path);
        $screenshots[] = [
            'src'         => 'branding/' . $b['pwa_screenshot_tablet'],
            'sizes'       => $st_info ? ($st_info[0] . 'x' . $st_info[1]) : '1024x768',
            'type'        => function_exists('get_image_mime') ? get_image_mime($st_path) : 'image/png',
            'form_factor' => 'wide',
            'label'       => $b['site_name'] . ' — Tablet',
        ];
    }
}
if (!empty($b['pwa_screenshot_mobile'])) {
    $sm_path = $branding_dir . '/' . $b['pwa_screenshot_mobile'];
    if (file_exists($sm_path)) {
        $sm_info = @getimagesize($sm_path);
        $screenshots[] = [
            'src'         => 'branding/' . $b['pwa_screenshot_mobile'],
            'sizes'       => $sm_info ? ($sm_info[0] . 'x' . $sm_info[1]) : '390x844',
            'type'        => function_exists('get_image_mime') ? get_image_mime($sm_path) : 'image/png',
            'form_factor' => 'narrow',
            'label'       => $b['site_name'] . ' — Mobile',
        ];
    }
}
if (!empty($screenshots)) {
    $manifest['screenshots'] = $screenshots;
}

// ── App-provided pwa.json merge ───────────────────────────────────────────
// A child app may ship pwa.json at its repo root (deployed at
// public_html/<slug>/pwa.json) to enrich Chrome's install UI far beyond what
// branding gives: many screenshots per form factor, maskable icons, shortcuts,
// categories. Merge it OVER the branding values.
//
// manifest.php is served from /admin/, but pwa.json's src/url are relative to
// the app folder (/<slug>/), so rewrite them to ../<slug>/… — correct relative
// to the manifest's base. start_url/id/scope stay admin's, untouched.
$webroot  = dirname(__DIR__);   // public_html/ (admin/ is a child of it)
$pwa      = null;
$pwa_slug = null;
foreach (glob($webroot . '/*/pwa.json') ?: [] as $pj) {
    $slug = basename(dirname($pj));
    if ($slug === 'admin') continue;
    $decoded = json_decode(@file_get_contents($pj), true);
    if (!is_array($decoded)) continue;
    if ($pwa !== null) {
        error_log("manifest.php: multiple pwa.json found; using '$pwa_slug', ignoring '$slug'");
        continue;
    }
    $pwa      = $decoded;
    $pwa_slug = $slug;
}

if ($pwa !== null) {
    $app_dir = $webroot . '/' . $pwa_slug;
    // Rewrite an app-relative src/url to be correct from /admin/. Absolute paths
    // (/… or http(s)://…) are left as the app declared them.
    $rw = function ($src) use ($pwa_slug) {
        $src = (string)$src;
        if ($src === '' || preg_match('#^(https?:)?/#i', $src)) return $src;
        return '../' . $pwa_slug . '/' . ltrim($src, './');
    };

    if (!empty($pwa['description']))           $manifest['description']      = $pwa['description'];
    if (!empty($pwa['categories']) && is_array($pwa['categories']))
                                               $manifest['categories']       = array_values($pwa['categories']);
    if (!empty($pwa['display_override']) && is_array($pwa['display_override']))
                                               $manifest['display_override'] = array_values($pwa['display_override']);
    if (!empty($pwa['launch_handler']) && is_array($pwa['launch_handler']))
                                               $manifest['launch_handler']   = $pwa['launch_handler'];

    // Icons — append the app's (incl. maskable) entries; branding icons stay.
    if (!empty($pwa['icons']) && is_array($pwa['icons'])) {
        $merged_icons = $manifest['icons'] ?? [];
        foreach ($pwa['icons'] as $ic) {
            if (empty($ic['src'])) continue;
            $ic['src'] = $rw($ic['src']);
            $merged_icons[] = $ic;
        }
        if (!empty($merged_icons)) $manifest['icons'] = $merged_icons;
    }

    // Screenshots — replace branding fallback with the app's set. Validate each:
    // drop if the file is unreadable, if its real pixels disagree with 'sizes',
    // or if its longer side exceeds 2.3× the shorter (Chrome's aspect-ratio rule).
    // Cap at 8 per form factor. Log every drop at info level.
    if (!empty($pwa['screenshots']) && is_array($pwa['screenshots'])) {
        $kept  = [];
        $perff = [];   // count kept per form_factor
        foreach ($pwa['screenshots'] as $sc) {
            if (empty($sc['src'])) continue;
            $label = $sc['src'];
            $disk  = $app_dir . '/' . ltrim((string)$sc['src'], './');
            $info  = @getimagesize($disk);
            if (!$info) {
                error_log("manifest.php: pwa screenshot dropped (unreadable): $pwa_slug/$label");
                continue;
            }
            $actual = $info[0] . 'x' . $info[1];
            if (!empty($sc['sizes']) && $sc['sizes'] !== $actual) {
                error_log("manifest.php: pwa screenshot dropped (sizes '{$sc['sizes']}' != actual $actual): $pwa_slug/$label");
                continue;
            }
            $long  = max($info[0], $info[1]);
            $short = min($info[0], $info[1]);
            if ($short > 0 && ($long / $short) > 2.3) {
                error_log("manifest.php: pwa screenshot dropped (aspect " . round($long / $short, 2) . " > 2.3): $pwa_slug/$label");
                continue;
            }
            $ff = (isset($sc['form_factor']) && $sc['form_factor'] === 'wide') ? 'wide' : 'narrow';
            $perff[$ff] = ($perff[$ff] ?? 0) + 1;
            if ($perff[$ff] > 8) {
                error_log("manifest.php: pwa screenshot dropped (>8 for form_factor $ff): $pwa_slug/$label");
                continue;
            }
            $sc['src']   = $rw($sc['src']);
            $sc['sizes'] = $actual;   // trust the real pixels
            $kept[]      = $sc;
        }
        if (!empty($kept)) {
            $manifest['screenshots'] = $kept;
        }
    }

    // Shortcuts — rewrite each url and any nested icon src.
    if (!empty($pwa['shortcuts']) && is_array($pwa['shortcuts'])) {
        $shortcuts = [];
        foreach ($pwa['shortcuts'] as $sh) {
            if (empty($sh['name']) || empty($sh['url'])) continue;
            $sh['url'] = $rw($sh['url']);
            if (!empty($sh['icons']) && is_array($sh['icons'])) {
                foreach ($sh['icons'] as &$shic) {
                    if (!empty($shic['src'])) $shic['src'] = $rw($shic['src']);
                }
                unset($shic);
            }
            $shortcuts[] = $sh;
        }
        if (!empty($shortcuts)) $manifest['shortcuts'] = $shortcuts;
    }
}

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

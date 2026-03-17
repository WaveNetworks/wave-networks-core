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
    $db = new PDO("mysql:host=$dbHostSpec;dbname=$dbInstance;charset=utf8mb4", $dbUserName, $dbPassword);
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
    'theme_color'      => $b['theme_color'],
    'background_color' => '#ffffff',
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
if (!empty($b['pwa_screenshot_mobile'])) {
    $sm_path = $branding_dir . '/' . $b['pwa_screenshot_mobile'];
    if (file_exists($sm_path)) {
        $sm_info = @getimagesize($sm_path);
        $screenshots[] = [
            'src'   => 'branding/' . $b['pwa_screenshot_mobile'],
            'sizes' => $sm_info ? ($sm_info[0] . 'x' . $sm_info[1]) : '750x1334',
            'type'  => function_exists('get_image_mime') ? get_image_mime($sm_path) : 'image/png',
            'label' => $b['site_name'] . ' — Mobile',
        ];
    }
}
if (!empty($screenshots)) {
    $manifest['screenshots'] = $screenshots;
}

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

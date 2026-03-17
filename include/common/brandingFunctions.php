<?php
/**
 * brandingFunctions.php
 * Loads and caches branding settings from auth_settings.
 */

/**
 * Get all branding settings. Cached per request.
 *
 * @return array
 */
function get_branding() {
    static $branding = null;
    if ($branding !== null) return $branding;

    $defaults = [
        'site_name'             => 'Admin',
        'site_short_name'       => 'Admin',
        'site_description'      => '',
        'theme_color'           => '#212529',
        'logo_path'             => null,
        'logo_dark_path'        => null,
        'favicon_path'          => null,
        'pwa_screenshot_wide'   => null,
        'pwa_screenshot_mobile' => null,
    ];

    $row = db_fetch(db_query(
        "SELECT site_name, site_short_name, site_description, theme_color,
                logo_path, logo_dark_path, favicon_path, pwa_screenshot_wide, pwa_screenshot_mobile
         FROM auth_settings WHERE setting_id = 1"
    ));

    if ($row) {
        $branding = array_merge($defaults, array_filter($row, function($v) { return $v !== null && $v !== ''; }));
    } else {
        $branding = $defaults;
    }

    // Append cache-busting version param to branding file paths
    $file_fields = ['logo_path', 'logo_dark_path', 'favicon_path'];
    $branding_dir = rtrim($GLOBALS['files_location'] ?? (__DIR__ . '/../../../files/'), '/') . '/branding/';
    foreach ($file_fields as $field) {
        if (!empty($branding[$field])) {
            $full_path = $branding_dir . $branding[$field];
            $mtime = @filemtime($full_path);
            if ($mtime) {
                $branding[$field] .= '?v=' . $mtime;
            }
        }
    }

    return $branding;
}

/**
 * Get the configured site name.
 *
 * @return string
 */
function site_name() {
    return get_branding()['site_name'];
}

/**
 * Generate square PWA icon PNGs (192x192 and 512x512) from a source image.
 * Supports PNG, JPG, WebP, GIF natively via GD. SVG requires Imagick.
 *
 * @param string $source_path  Absolute path to the source image file
 * @param string $uploads_dir  Absolute path to the uploads directory
 * @return array  List of generated icon filenames (relative to uploads/)
 */
function generate_pwa_icons($source_path, $uploads_dir) {
    $sizes = [192, 512];
    $generated = [];
    $ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));

    // Load source image
    $src = null;
    if ($ext === 'svg') {
        if (extension_loaded('imagick')) {
            try {
                $im = new Imagick();
                $im->readImage($source_path);
                $im->setImageFormat('png');
                $tmp = $uploads_dir . '/pwa_icon_tmp.png';
                $im->writeImage($tmp);
                $im->clear();
                $im->destroy();
                $src = imagecreatefrompng($tmp);
                @unlink($tmp);
            } catch (Exception $e) {
                error_log('generate_pwa_icons: Imagick SVG failed: ' . $e->getMessage());
                return $generated;
            }
        } else {
            // No Imagick — can't rasterize SVG with GD alone
            error_log('generate_pwa_icons: SVG requires Imagick extension');
            return $generated;
        }
    } elseif ($ext === 'png') {
        $src = @imagecreatefrompng($source_path);
    } elseif (in_array($ext, ['jpg', 'jpeg'])) {
        $src = @imagecreatefromjpeg($source_path);
    } elseif ($ext === 'webp') {
        $src = @imagecreatefromwebp($source_path);
    } elseif ($ext === 'gif') {
        $src = @imagecreatefromgif($source_path);
    }

    if (!$src) return $generated;

    $src_w = imagesx($src);
    $src_h = imagesy($src);

    foreach ($sizes as $size) {
        $dst = imagecreatetruecolor($size, $size);
        // Preserve transparency
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $src_w, $src_h);

        $filename = "pwa_icon_{$size}.png";
        $out_path = $uploads_dir . '/' . $filename;
        imagepng($dst, $out_path);
        imagedestroy($dst);
        $generated[] = $filename;
    }

    imagedestroy($src);
    return $generated;
}

/**
 * Get MIME type for an image file based on extension (more reliable than mime_content_type for SVG).
 *
 * @param string $path  File path
 * @return string
 */
function get_image_mime($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
    ];
    return $map[$ext] ?? (mime_content_type($path) ?: 'image/png');
}

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
        'theme_color_light'     => '#ffffff',
        'theme_color_dark'      => '#212529',
        'background_color_light' => '#ffffff',
        'background_color_dark'  => '#212529',
        'logo_path'             => null,
        'logo_dark_path'        => null,
        'favicon_path'          => null,
        'pwa_screenshot_wide'   => null,
        'pwa_screenshot_mobile' => null,
    ];

    $row = db_fetch(db_query(
        "SELECT * FROM auth_settings WHERE setting_id = 1"
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
                // MUST precede readImage(). Imagick renders SVG onto an OPAQUE WHITE
                // canvas by default, which silently destroys any icon drawn in white
                // or in strokes on transparency — the result is a uniform white square
                // that still writes a valid PNG and still serves HTTP 200, so nothing
                // downstream notices. pwt shipped exactly that: a 512x512 icon with
                // ONE unique colour. Reproduced with `convert in.svg out.png` (blank)
                // vs `convert -background none in.svg out.png` (256 colours).
                $im->setBackgroundColor(new ImagickPixel('transparent'));
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

    // A PWA icon must be OPAQUE. iOS flattens home-screen icons onto white, and
    // Android draws them on whatever the launcher uses — so a transparent icon is
    // at the mercy of the surface. A brand mark drawn in white then disappears
    // completely, which is what pwt shipped: its favicon is the DARK-MODE logo
    // (fill:#fff), so the icon was white-on-white. Measured on the real assets:
    //
    //     branding_logo.svg      (fill #231f20) on light -> 245 colours
    //     branding_logo_dark.svg (fill #fff)    on dark  -> 254 colours
    //     branding_logo_dark.svg (fill #fff)    on light ->   1 colour  (invisible)
    //
    // So pick the backdrop from the artwork itself rather than from branding's
    // colour fields, which are frequently left at their #ffffff default and would
    // reintroduce exactly this failure. Mean luminance over the OPAQUE pixels
    // separates the two cleanly (0.016 vs 0.125 on the assets above).
    $lumSum = 0.0; $lumN = 0;
    $stepX = max(1, (int) ($src_w / 64));
    $stepY = max(1, (int) ($src_h / 64));
    for ($y = 0; $y < $src_h; $y += $stepY) {
        for ($x = 0; $x < $src_w; $x += $stepX) {
            $c = imagecolorat($src, $x, $y);
            if ((($c >> 24) & 0x7F) > 100) continue;         // effectively transparent
            $lumSum += (0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF)) / 255;
            $lumN++;
        }
    }
    $artworkIsLight = $lumN > 0 && ($lumSum / $lumN) > 0.5;
    $bg = $artworkIsLight ? [10, 10, 26] : [255, 255, 255];  // #0a0a1a matches the native app icons

    foreach ($sizes as $size) {
        $dst = imagecreatetruecolor($size, $size);
        // Opaque backdrop, then composite the artwork ONTO it — so alphablending
        // must be on for the copy (the old code copied source alpha verbatim).
        $bgColor = imagecolorallocate($dst, $bg[0], $bg[1], $bg[2]);
        imagefilledrectangle($dst, 0, 0, $size, $size, $bgColor);
        imagealphablending($dst, true);
        imagesavealpha($dst, false);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $src_w, $src_h);

        $filename = "pwa_icon_{$size}.png";
        $out_path = $uploads_dir . '/' . $filename;
        imagepng($dst, $out_path);

        // A blank icon is a valid PNG served with HTTP 200 — the only way to notice it
        // is to look at the pixels. Sample a grid; if every sample is identical the
        // rasteriser produced a flat square and the branding upload needs attention.
        $seen = null; $uniform = true;
        for ($sy = 0; $sy < $size && $uniform; $sy += max(1, (int)($size / 16))) {
            for ($sx = 0; $sx < $size; $sx += max(1, (int)($size / 16))) {
                $px = imagecolorat($dst, $sx, $sy);
                if ($seen === null) { $seen = $px; continue; }
                if ($px !== $seen) { $uniform = false; break; }
            }
        }
        if ($uniform) {
            error_log("generate_pwa_icons: {$filename} is a single flat colour — the source "
                    . "(" . basename($source_path) . ") did not rasterise. A white-on-transparent "
                    . "or stroke-only SVG needs an opaque background baked in.");
        }

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
    // Strip query string (e.g. ?v=123) before resolving extension/mime
    $cleanPath = strtok($path, '?');
    $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
    $map = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
    ];
    return $map[$ext] ?? (file_exists($cleanPath) ? (mime_content_type($cleanPath) ?: 'image/png') : 'image/png');
}

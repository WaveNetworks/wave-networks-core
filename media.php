<?php
/**
 * media.php
 * Serves media-library assets from $files_location/media/.
 * Lightweight — loads only config, no session/auth/helpers (assets are public,
 * embedded in apps/ads). Mirrors branding.php.
 *
 * URL: /admin/media/{filename} (rewritten by .htaccess)
 */

$file = $_GET['f'] ?? '';

if (!$file || preg_match('/[\/\\\\]|\.\./u', $file)) {
    http_response_code(400);
    exit;
}

/**
 * Produce (and disk-cache) a resized / re-encoded variant of a raster image so
 * callers can request an asset at the size they actually display it, instead of
 * shipping the full-resolution original. `?w=<px>` caps the width; `?fmt=webp`
 * re-encodes to WebP. Returns null (→ serve original) on any missing-GD /
 * decode / write failure, so this is always safe to attempt.
 */
function media_image_variant(string $src, string $ext, int $maxW, string $fmt): ?array
{
    $outExt = ($fmt === 'webp' && function_exists('imagewebp')) ? 'webp' : $ext;
    $size   = @getimagesize($src);
    if (!$size) { return null; }
    [$w, $h] = $size;
    if ($w < 1 || $h < 1) { return null; }

    $targetW = ($maxW > 0 && $maxW < $w) ? $maxW : $w;
    // No downscale needed and no format change → let the caller serve the original.
    if ($targetW === $w && $outExt === $ext) { return null; }

    $cacheDir = dirname($src) . '/cache';
    $key      = md5($src . '|' . filemtime($src) . '|' . $targetW . '|' . $outExt);
    $out      = $cacheDir . '/' . $key . '.' . $outExt;
    if (is_file($out)) { return ['path' => $out, 'ext' => $outExt]; }
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true)) { return null; }

    switch ($ext) {
        case 'jpg': case 'jpeg': $img = @imagecreatefromjpeg($src); break;
        case 'png':  $img = @imagecreatefrompng($src);  break;
        case 'webp': $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
        case 'gif':  $img = @imagecreatefromgif($src);  break;
        default:     $img = false;
    }
    if (!$img) { return null; }

    $targetH = (int)round($h * ($targetW / $w));
    $dst     = imagecreatetruecolor($targetW, $targetH);
    if (in_array($outExt, ['png', 'webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $targetW, $targetH, $w, $h);

    if ($outExt === 'webp')      { $ok = @imagewebp($dst, $out, 82); }
    elseif ($outExt === 'png')   { $ok = @imagepng($dst, $out, 6); }
    else                         { $ok = @imagejpeg($dst, $out, 82); }
    imagedestroy($img);
    imagedestroy($dst);
    if (!$ok) { return null; }

    return ['path' => $out, 'ext' => $outExt];
}

// Load config to get $files_location
$configFile = __DIR__ . '/config/config.php';
if (file_exists($configFile)) {
    include($configFile);
} elseif (getenv('FILES_LOCATION')) {
    $files_location = getenv('FILES_LOCATION');
} else {
    $files_location = __DIR__ . '/../../files/';
}

if (empty($files_location)) {
    http_response_code(500);
    exit;
}

$path = rtrim($files_location, '/') . '/media/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime_map = [
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'ico'  => 'image/x-icon',
    'pdf'  => 'application/pdf',
    'mp4'  => 'video/mp4',
];
// Optional on-the-fly resize / WebP conversion for raster images. Falls back to
// the original on any failure (see media_image_variant).
$reqW   = isset($_GET['w']) ? (int)$_GET['w'] : 0;
$reqFmt = strtolower($_GET['fmt'] ?? '');
if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
    && function_exists('imagecreatetruecolor')
    && ($reqW > 0 || $reqFmt === 'webp')) {
    $variant = media_image_variant($path, $ext, $reqW, $reqFmt);
    if ($variant) {
        $path = $variant['path'];
        $ext  = $variant['ext'];
    }
}

$mime = $mime_map[$ext] ?? (@mime_content_type($path) ?: 'application/octet-stream');

$etag = '"' . md5($path . filemtime($path)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=86400, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
readfile($path);

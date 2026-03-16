<?php
/**
 * profileImageFunctions.php
 * Profile photo upload, thumbnail generation, serving, and deletion.
 *
 * Photos are stored in the user's homedir (outside webroot) under a profile/ subdirectory.
 * A PHP proxy (serveProfileImage action) streams the image with correct headers.
 *
 * Depends on: hashCodeFunctions.php (create_home_dir_id), brandingFunctions.php (get_image_mime),
 *             userFunctions.php (get_user_profile)
 */

/**
 * Upload and process a profile image.
 *
 * @param int    $user_id
 * @param string $shard_id
 * @param array  $file  $_FILES['profile_image']
 * @return array ['success' => bool, 'error' => string]
 */
function upload_profile_image($user_id, $shard_id, $file) {
    // Validate upload
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        $err_map = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        $msg = $err_map[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Upload failed.';
        return ['success' => false, 'error' => $msg];
    }

    // Validate MIME type
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mimes)) {
        return ['success' => false, 'error' => 'Only JPEG, PNG, WebP, and GIF images are allowed.'];
    }

    // Validate file size (2 MB max)
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Image must be 2 MB or smaller.'];
    }

    // Ensure homedir exists
    $homedir = $_SESSION['homedir'] ?? '';
    if (!$homedir || !is_dir($homedir)) {
        $homedir = create_home_dir_id($user_id);
        if ($homedir) {
            $_SESSION['homedir'] = $homedir;
        }
    }
    if (!$homedir) {
        return ['success' => false, 'error' => 'Could not create user directory.'];
    }

    // Create profile subdirectory
    $profile_dir = $homedir . 'profile/';
    if (!is_dir($profile_dir)) {
        mkdir($profile_dir, 0755, true);
    }

    // Determine extension from MIME
    $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $ext = $ext_map[$mime] ?? 'jpg';

    // Save original
    $original_path = $profile_dir . 'profile_original.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $original_path)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file.'];
    }

    // Generate thumbnail (200px square, center-cropped)
    $thumb_filename = generate_profile_thumbnail($original_path, $profile_dir, 200);
    if (!$thumb_filename) {
        @unlink($original_path);
        return ['success' => false, 'error' => 'Failed to process image. Please try a different file.'];
    }

    // Update shard DB
    $relative_path = 'profile/' . $thumb_filename;
    $safe_path = sanitize($relative_path, SQL);
    $safe_uid = (int) $user_id;
    prime_shard($shard_id);
    db_query_shard($shard_id, "UPDATE user_profile SET profile_image = '$safe_path' WHERE user_id = '$safe_uid'");

    // Update session
    $_SESSION['profile_image'] = $relative_path;

    return ['success' => true];
}

/**
 * Generate a center-cropped square JPEG thumbnail from a source image.
 *
 * @param string $source_path  Absolute path to source image
 * @param string $dest_dir     Absolute path to destination directory
 * @param int    $size         Output dimension (square)
 * @return string|false        Generated filename, or false on failure
 */
function generate_profile_thumbnail($source_path, $dest_dir, $size = 200) {
    $ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));

    // Load source image via GD
    $src = null;
    if ($ext === 'png') {
        $src = @imagecreatefrompng($source_path);
    } elseif (in_array($ext, ['jpg', 'jpeg'])) {
        $src = @imagecreatefromjpeg($source_path);
    } elseif ($ext === 'webp') {
        $src = @imagecreatefromwebp($source_path);
    } elseif ($ext === 'gif') {
        $src = @imagecreatefromgif($source_path);
    }

    if (!$src) return false;

    $src_w = imagesx($src);
    $src_h = imagesy($src);

    // Center-crop to square
    $crop_size = min($src_w, $src_h);
    $crop_x = (int) (($src_w - $crop_size) / 2);
    $crop_y = (int) (($src_h - $crop_size) / 2);

    // Create destination image
    $dst = imagecreatetruecolor($size, $size);
    imagecopyresampled($dst, $src, 0, 0, $crop_x, $crop_y, $size, $size, $crop_size, $crop_size);

    // Save as JPEG (good size/quality balance for photos)
    $filename = "avatar_{$size}.jpg";
    $out_path = rtrim($dest_dir, '/') . '/' . $filename;
    $result = imagejpeg($dst, $out_path, 85);

    imagedestroy($src);
    imagedestroy($dst);

    return $result ? $filename : false;
}

/**
 * Get the absolute filesystem path to a user's profile image.
 *
 * @param int    $user_id
 * @param string $shard_id
 * @return string|false  Absolute path, or false if no image
 */
function get_profile_image_path($user_id, $shard_id) {
    $profile = get_user_profile($user_id, $shard_id);
    if (!$profile || empty($profile['profile_image']) || empty($profile['homedir'])) {
        return false;
    }

    $path = $profile['homedir'] . $profile['profile_image'];
    return file_exists($path) ? $path : false;
}

/**
 * Stream a user's profile image to the browser with correct headers.
 *
 * @param int    $user_id
 * @param string $shard_id
 */
function serve_profile_image($user_id, $shard_id) {
    $path = get_profile_image_path($user_id, $shard_id);

    if (!$path) {
        http_response_code(404);
        exit;
    }

    $mime = get_image_mime($path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    header('ETag: "' . md5_file($path) . '"');
    readfile($path);
    exit;
}

/**
 * Delete a user's profile image files and clear the DB value.
 *
 * @param int    $user_id
 * @param string $shard_id
 * @return bool
 */
function delete_profile_image($user_id, $shard_id) {
    $profile = get_user_profile($user_id, $shard_id);
    if (!$profile || empty($profile['homedir'])) return false;

    $profile_dir = $profile['homedir'] . 'profile/';

    // Remove all files in the profile directory
    if (is_dir($profile_dir)) {
        $files = glob($profile_dir . '*');
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    // Clear DB
    $safe_uid = (int) $user_id;
    prime_shard($shard_id);
    db_query_shard($shard_id, "UPDATE user_profile SET profile_image = NULL WHERE user_id = '$safe_uid'");

    // Clear session
    $_SESSION['profile_image'] = null;

    return true;
}

/**
 * Get the URL to serve a user's profile image.
 * Returns null if no profile image is set (caller should render initials fallback).
 *
 * @param int $user_id
 * @return string|null
 */
function get_profile_image_url($user_id = null) {
    $image = $_SESSION['profile_image'] ?? null;
    if (!$image) return null;

    $uid = $user_id ?: ($_SESSION['user_id'] ?? 0);
    // Cache-bust with filemtime via session homedir
    $homedir = $_SESSION['homedir'] ?? '';
    $v = '';
    if ($homedir && file_exists($homedir . $image)) {
        $v = '&v=' . filemtime($homedir . $image);
    }

    return 'index.php?action=serveProfileImage&uid=' . (int) $uid . $v;
}

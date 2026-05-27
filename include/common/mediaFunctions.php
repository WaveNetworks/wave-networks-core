<?php
/**
 * mediaFunctions.php — media library helpers.
 *
 * Media assets are uploaded files served from $files_location/media/ via media.php
 * (URL /admin/media/{filename}). Per-deployment: each app's admin has its own DB +
 * files_location, so a deployment's media library is that app's media.
 */

// Allowed upload MIME types => canonical extension.
function media_allowed_types(): array
{
    return [
        'image/png'                 => 'png',
        'image/jpeg'                => 'jpg',
        'image/gif'                 => 'gif',
        'image/webp'                => 'webp',
        'image/svg+xml'             => 'svg',
        'image/x-icon'              => 'ico',
        'image/vnd.microsoft.icon'  => 'ico',
        'application/pdf'           => 'pdf',
        'video/mp4'                 => 'mp4',
    ];
}

// Absolute path to the media storage dir (created if missing).
function media_dir(): string
{
    global $files_location;
    $base = !empty($files_location) ? $files_location : (getenv('FILES_LOCATION') ?: (__DIR__ . '/../../../files/'));
    $dir  = rtrim($base, '/') . '/media';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

// Public URL for a stored media filename (this deployment's host).
function media_public_url(string $filename): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    return $scheme . '://' . $host . '/admin/media/' . rawurlencode($filename);
}

// Guarantee the media_asset table exists (autocommit; runs outside the migration
// runner so the feature works immediately regardless of migration/opcache timing).
function ensure_media_table(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    db_query(
        "CREATE TABLE IF NOT EXISTS `media_asset` (
            `asset_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `filename`      VARCHAR(255) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `title`         VARCHAR(255) NULL DEFAULT NULL,
            `mime_type`     VARCHAR(100) NOT NULL,
            `ext`           VARCHAR(16) NOT NULL,
            `file_size`     INT UNSIGNED NOT NULL DEFAULT 0,
            `width`         INT UNSIGNED NULL DEFAULT NULL,
            `height`        INT UNSIGNED NULL DEFAULT NULL,
            `uploaded_by`   INT UNSIGNED NULL DEFAULT NULL,
            `created`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`asset_id`),
            KEY `idx_created` (`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

-- Migration 4.5 for Main Database
-- Media library: app-servable uploaded media assets (branding elements, ad images,
-- e.g. transparent PNGs for community ads). Per-deployment (each app's admin has its
-- own DB + files_location), so media is naturally scoped to that app.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 4.5;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
CREATE TABLE IF NOT EXISTS `media_asset` (
    `asset_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename`      VARCHAR(255) NOT NULL COMMENT 'stored filename in files_location/media/',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

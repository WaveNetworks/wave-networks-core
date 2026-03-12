-- Migration 1.2 for Main Database
-- Add branding columns to auth_settings for configurable site identity and PWA manifest
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.2;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE `auth_settings`
  ADD COLUMN `site_name` varchar(100) DEFAULT 'Admin',
  ADD COLUMN `site_short_name` varchar(30) DEFAULT 'Admin',
  ADD COLUMN `site_description` varchar(255) DEFAULT '',
  ADD COLUMN `theme_color` varchar(7) DEFAULT '#212529',
  ADD COLUMN `logo_path` varchar(255) DEFAULT NULL,
  ADD COLUMN `favicon_path` varchar(255) DEFAULT NULL;

COMMIT;

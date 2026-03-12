-- Migration 1.8 for Main Database
-- Add PWA screenshot columns to auth_settings for richer install UI
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.8;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE `auth_settings` ADD COLUMN `pwa_screenshot_wide` varchar(255) DEFAULT NULL;
ALTER TABLE `auth_settings` ADD COLUMN `pwa_screenshot_mobile` varchar(255) DEFAULT NULL;

COMMIT;

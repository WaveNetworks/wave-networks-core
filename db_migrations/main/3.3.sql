-- Migration 3.3 for Main Database
-- Add chrome color columns to auth_settings for PWA theme/background colors
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.3;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE `auth_settings` ADD COLUMN `theme_color_light` varchar(7) DEFAULT '#ffffff' AFTER `theme_color`;
ALTER TABLE `auth_settings` ADD COLUMN `theme_color_dark` varchar(7) DEFAULT '#212529' AFTER `theme_color_light`;
ALTER TABLE `auth_settings` ADD COLUMN `background_color_light` varchar(7) DEFAULT '#ffffff' AFTER `theme_color_dark`;
ALTER TABLE `auth_settings` ADD COLUMN `background_color_dark` varchar(7) DEFAULT '#212529' AFTER `background_color_light`;

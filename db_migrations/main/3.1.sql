-- Migration 3.1 for Main Database
-- Add light/dark mode chrome colors and tablet screenshot to PWA branding
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.1;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE `auth_settings`
    ADD COLUMN `theme_color_light`      varchar(7) DEFAULT '#ffffff'  AFTER `theme_color`,
    ADD COLUMN `theme_color_dark`       varchar(7) DEFAULT '#212529'  AFTER `theme_color_light`,
    ADD COLUMN `background_color_light` varchar(7) DEFAULT '#ffffff'  AFTER `theme_color_dark`,
    ADD COLUMN `background_color_dark`  varchar(7) DEFAULT '#212529'  AFTER `background_color_light`,
    ADD COLUMN `pwa_screenshot_tablet`  varchar(255) DEFAULT NULL      AFTER `pwa_screenshot_wide`;

COMMIT;

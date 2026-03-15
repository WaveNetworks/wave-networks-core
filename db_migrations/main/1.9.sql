-- Migration 1.9 for Main Database
-- Add dark mode logo support to branding
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.9;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE `auth_settings` ADD COLUMN `logo_dark_path` varchar(255) DEFAULT NULL AFTER `logo_path`;

COMMIT;

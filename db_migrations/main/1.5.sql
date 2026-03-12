-- Migration 1.5 for Main Database
-- Add salt_position column to migration_source for prepend/append salt handling
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.5;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE `migration_source`
  ADD COLUMN `salt_position` enum('append','prepend') NOT NULL DEFAULT 'append' AFTER `password_salt`;

COMMIT;

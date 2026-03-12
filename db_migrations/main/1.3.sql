-- Migration 1.3 for Main Database
-- Add soft-delete columns to user table for churn/cancellation tracking
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.3;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE `user`
  ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN `deactivated_date` datetime DEFAULT NULL,
  ADD INDEX `idx_is_active` (`is_active`),
  ADD INDEX `idx_deactivated_date` (`deactivated_date`);

COMMIT;

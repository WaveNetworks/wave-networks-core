-- Migration 2.2 for Main Database
-- Add resolved tracking to error_log
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.2;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE `error_log`
  ADD COLUMN `resolved_at` datetime DEFAULT NULL AFTER `created`,
  ADD COLUMN `resolved_by` int(11) DEFAULT NULL AFTER `resolved_at`,
  ADD KEY `idx_resolved` (`resolved_at`);

COMMIT;

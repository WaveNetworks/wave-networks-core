-- Migration 3.5 for Main Database
-- Add resolution_reason + resolution_notes to error_log so the nokemo builder
-- (and humans) can record why an error was closed: fixed, already_fixed,
-- cant_fix, noise, wont_fix. Enables the autonomous agent to move on cleanly
-- when a fix isn't possible instead of churning on the same error forever.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.5;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE error_log
    ADD COLUMN resolution_reason ENUM('fixed','already_fixed','cant_fix','noise','wont_fix') NULL DEFAULT NULL AFTER resolved_by,
    ADD COLUMN resolution_notes VARCHAR(500) NULL DEFAULT NULL AFTER resolution_reason;

ALTER TABLE error_log ADD INDEX idx_resolution_reason (resolution_reason);

-- Migration 2.4 for Main Database
-- Add occurrence tracking columns to error_log and error_hash index for JS error deduplication
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.4;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE error_log
    ADD COLUMN occurrence_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER memory_usage,
    ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL AFTER occurrence_count,
    ADD COLUMN error_hash CHAR(32) NULL DEFAULT NULL AFTER last_seen_at;

ALTER TABLE error_log ADD INDEX idx_error_hash_resolved (error_hash, resolved_at);

COMMIT;

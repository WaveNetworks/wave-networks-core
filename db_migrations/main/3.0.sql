-- Migration 3.0 for Main Database
-- Add vendor column to cost_entry for per-vendor cost tracking
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE cost_entry ADD COLUMN vendor VARCHAR(200) DEFAULT NULL AFTER source_app;
ALTER TABLE cost_entry ADD INDEX idx_vendor (vendor);

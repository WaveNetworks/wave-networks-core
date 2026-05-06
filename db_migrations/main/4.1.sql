-- Migration 4.1 for Main Database
-- Feedback widget: screenshot + lightweight context columns
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 4.1;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Add screenshot + page-context columns to feedback.
-- context_json already exists (JSON) so we re-use it for the merged context bundle.
ALTER TABLE feedback ADD COLUMN screenshot_path VARCHAR(500) DEFAULT NULL;
ALTER TABLE feedback ADD COLUMN viewport_w SMALLINT UNSIGNED DEFAULT NULL;
ALTER TABLE feedback ADD COLUMN viewport_h SMALLINT UNSIGNED DEFAULT NULL;
ALTER TABLE feedback ADD COLUMN capture_url VARCHAR(500) DEFAULT NULL;

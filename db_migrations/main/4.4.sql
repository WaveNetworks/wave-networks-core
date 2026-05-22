-- Migration 4.4 for Main Database
-- Onboarding tour: optional welcome video shown in the welcome modal.
-- Accepts a YouTube URL, Google Drive URL, or a direct/uploaded video file URL.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 4.4;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE onboarding_tour
    ADD COLUMN welcome_video_url VARCHAR(500) NOT NULL DEFAULT '' AFTER welcome_body_md;

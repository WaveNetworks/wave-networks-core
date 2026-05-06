-- Migration 1.3 for Shard Database
-- Per-user onboarding tour state (status, progress).
-- ⚠️ REMINDER: Update admin/include/common.php $shard_version = 1.3;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS onboarding_tour_state (
    user_id      INT UNSIGNED NOT NULL,
    tour_slug    VARCHAR(100) NOT NULL,
    status       ENUM('not_started','in_progress','skipped','completed') NOT NULL DEFAULT 'not_started',
    current_step INT NOT NULL DEFAULT 0,
    started_at   DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (user_id, tour_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

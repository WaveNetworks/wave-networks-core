-- Migration 1.2 for Shard Database
-- Add user_action_log and user_action_summary tables for per-user action tracking
-- ⚠️ REMINDER: Update admin/include/common.php $shard_version = 1.2;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS user_action_log (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NULL,
  session_id VARCHAR(64) NULL,
  source_app VARCHAR(50) NOT NULL,
  page VARCHAR(100) NULL,
  action VARCHAR(100) NULL,
  params_json JSON NULL,
  result ENUM('success','error','redirect') NOT NULL DEFAULT 'success',
  duration_ms INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created DATETIME NOT NULL,
  expires_at DATETIME NULL,
  INDEX idx_user (user_id, created),
  INDEX idx_app_action (source_app, page, action),
  INDEX idx_session (session_id),
  INDEX idx_device (device_id),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_action_summary (
  summary_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NULL,
  source_app VARCHAR(50) NOT NULL,
  page VARCHAR(100) NULL,
  action VARCHAR(100) NULL,
  day DATE NOT NULL,
  event_count INT UNSIGNED NOT NULL DEFAULT 0,
  avg_duration_ms INT UNSIGNED NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  terminal_action_count INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_user_action_day (user_id, source_app, page, action, day),
  INDEX idx_user_day (user_id, day),
  INDEX idx_app_day (source_app, day)
) ENGINE=InnoDB;

-- Migration 3.4 for Main Database
-- Phase1-T2: device_action_log, is_test_account, feature_metric_daily, use_case, use_case_test_run
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.4;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- A. Add is_test_account flag to user table.
-- NOTE: Appended at end of table (no AFTER clause). Earlier version of this
-- file used "AFTER role" but the user table uses multiple boolean role
-- columns (is_admin, is_owner, is_manager, is_employee), not a single
-- `role` column. That caused 1054 Unknown column on every page load and
-- blocked the entire 3.4 migration. Fixed 2026-04-14.
ALTER TABLE `user` ADD COLUMN `is_test_account` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;

-- B. Anonymous device action log (security/attack-surface event source)
CREATE TABLE IF NOT EXISTS device_action_log (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
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
  INDEX idx_device (device_id, created),
  INDEX idx_app_action (source_app, page, action),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- C. Cross-shard rolled-up feature metrics for analytics consumers
CREATE TABLE IF NOT EXISTS feature_metric_daily (
  metric_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_app VARCHAR(50) NOT NULL,
  page VARCHAR(100) NULL,
  action VARCHAR(100) NULL,
  day DATE NOT NULL,
  user_count INT UNSIGNED NOT NULL DEFAULT 0,
  device_count INT UNSIGNED NOT NULL DEFAULT 0,
  event_count INT UNSIGNED NOT NULL DEFAULT 0,
  avg_duration_ms INT UNSIGNED NULL,
  terminal_action_count INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_metric (source_app, page, action, day),
  INDEX idx_app_day (source_app, day)
) ENGINE=InnoDB;

-- D. Use case definitions (testing framework Phase 2 will populate)
CREATE TABLE IF NOT EXISTS use_case (
  use_case_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_app VARCHAR(50) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  name VARCHAR(255) NULL,
  description TEXT NULL,
  requires_login TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  starting_page VARCHAR(100) NULL,
  ending_action VARCHAR(100) NULL,
  action_path JSON NULL,
  test_category ENUM('preflight','auth','smoke','feature','accessibility') NOT NULL DEFAULT 'feature',
  test_status ENUM('pending','passing','failing','flaky','disabled') NOT NULL DEFAULT 'pending',
  derived_from_log_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL,
  last_test_run_id INT UNSIGNED NULL,
  created DATETIME NOT NULL,
  updated DATETIME NOT NULL,
  UNIQUE KEY uq_app_slug (source_app, slug),
  INDEX idx_app_status (source_app, test_status),
  INDEX idx_category (test_category)
) ENGINE=InnoDB;

-- E. Test run results (one row per use case per permutation per run)
CREATE TABLE IF NOT EXISTS use_case_test_run (
  run_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  use_case_id INT UNSIGNED NOT NULL,
  run_at DATETIME NOT NULL,
  permutation VARCHAR(50) NOT NULL,
  status ENUM('pass','fail','flaky','skipped') NOT NULL,
  duration_ms INT UNSIGNED NULL,
  screenshot_paths JSON NULL,
  axe_violations_json JSON NULL,
  console_errors_json JSON NULL,
  fail_reason TEXT NULL,
  INDEX idx_use_case (use_case_id, run_at),
  INDEX idx_status (status, run_at)
) ENGINE=InnoDB;

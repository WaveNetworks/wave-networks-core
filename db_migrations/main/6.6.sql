-- Migration 6.6 for Main Database
-- use_case gains audience + manual_section (owner 2026-10-20: "with the catalogue we should be able to
-- produce an extensive user manual on the website and keep it up to date").
-- audience: who the journey is for. 'user' journeys (registered by an app's E2E scenarios) are what the
-- help desk's user manual and ticket answers are built from; 'admin' = the app's admin screens;
-- 'internal' = the code inventory static derivation registers (every row before this migration).
-- manual_section: the manual chapter a user journey belongs in (free text, e.g. "Map", "Import").
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.6;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE use_case
  ADD COLUMN audience ENUM('internal','user','admin') NOT NULL DEFAULT 'internal' AFTER test_category,
  ADD COLUMN manual_section VARCHAR(80) NULL DEFAULT NULL AFTER audience,
  ADD INDEX idx_app_audience (source_app, audience);

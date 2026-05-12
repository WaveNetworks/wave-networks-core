-- Migration 4.2 for Main Database
-- mobile_parity table: per-feature desktop↔mobile gap inventory
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 4.2;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- One row per (source_app, category, feature_key). Populated by
-- admin/scripts/audit_mobile_parity.py on the nightly refresh; consumed
-- by views/mobile_parity.php and by the builder when scoping mobile
-- shell-parity tasks.
--
-- category enumerates the FIVE places a feature can live on the
-- desktop side:
--   page    — views/<name>.php — a top-level routed screen
--   action  — `if ($_POST['action'] == 'X')` handlers
--   script  — <script src> tags in views/template.php (global JS)
--   snippet — <?php include 'snippets/X.php' ?> in template.php
--   widget  — sidebar / header elements seeded from a manual allowlist
--             (token balance, notification badge, color-mode toggle, …)
--
-- mobile_status reflects whether the same feature is present in the
-- corresponding mobile SPA (we look in mobile/index.html + mobile/js/).
--
-- desktop_source / mobile_source are repo-relative paths so the admin
-- view can deep-link straight to the file.
CREATE TABLE IF NOT EXISTS mobile_parity (
  parity_id      INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_app     VARCHAR(50)   NOT NULL,
  category       ENUM('page','action','script','snippet','widget') NOT NULL,
  feature_key    VARCHAR(255)  NOT NULL,
  feature_name   VARCHAR(255)  NULL,
  desktop_source VARCHAR(500)  NULL,
  mobile_source  VARCHAR(500)  NULL,
  mobile_status  ENUM('missing','partial','wired','n_a') NOT NULL DEFAULT 'missing',
  priority       ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  notes          TEXT          NULL,
  last_checked   DATETIME      NULL,
  created        DATETIME      NOT NULL,
  updated        DATETIME      NOT NULL,
  UNIQUE KEY uq_app_cat_key (source_app, category, feature_key),
  INDEX idx_app_status (source_app, mobile_status),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 5.4 for Main Database
-- use_case_asset: link Media library assets (views/media.php, media_asset) to a
-- use_case so a use case OWNS the marketing/onboarding graphics that depict it.
-- The daily Playwright runs already capture real screenshots of the same use case
-- (use_case_test_run.screenshot_paths), so linked graphics can be reviewed BESIDE
-- the latest passing run for parity, and flagged when they drift.
-- An asset can depict several use cases; the asset itself lives in media_asset.
-- ⚠️ REMINDER: $db_version = 5.4 in include/bootstrap.php.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `use_case_asset` (
    `link_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `use_case_id`        INT UNSIGNED NOT NULL,
    `asset_id`           INT UNSIGNED NOT NULL COMMENT 'media_asset.asset_id',
    `role`               ENUM('vignette','vignette_phone','store_shot','onboarding_slide','preview_video','doc_image') NOT NULL DEFAULT 'vignette',
    `variant`            VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'light/dark, locale, etc.',
    `source_hash`        CHAR(64) NULL DEFAULT NULL COMMENT 'sha256 of the source graphic the build uploaded',
    -- Drift tracking: what was true when an admin last approved this link.
    `review_status`      ENUM('pending','approved','needs_review') NOT NULL DEFAULT 'pending',
    `review_reason`      VARCHAR(255) NULL DEFAULT NULL,
    `approved_run_id`    INT UNSIGNED NULL DEFAULT NULL COMMENT 'use_case_test_run blessed at approval',
    `approved_path_hash` CHAR(64) NULL DEFAULT NULL COMMENT 'sha256 of normalized action_path at approval',
    `approved_phash`     VARCHAR(32) NULL DEFAULT NULL COMMENT 'perceptual aHash of blessed screenshot (hex)',
    `last_reviewed_at`   DATETIME NULL DEFAULT NULL,
    `created`            DATETIME NOT NULL,
    `updated`            DATETIME NOT NULL,
    PRIMARY KEY (`link_id`),
    UNIQUE KEY `uq_link` (`use_case_id`, `asset_id`, `role`, `variant`),
    KEY `idx_use_case` (`use_case_id`),
    KEY `idx_asset` (`asset_id`),
    KEY `idx_review` (`review_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

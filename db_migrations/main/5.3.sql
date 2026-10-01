-- Migration 5.3 for Main Database
-- Legal documents: versioned Privacy Policy / Terms of Service with history and drafts.
--
-- consent_version (2.5/2.6) already held one row per published version. This makes each
-- row a complete, immutable published document: per-app scope, who published it and when,
-- whether it asks existing users to accept again, and a SHA-256 of the text so any later
-- change to a published row is visible (include/common/legalFunctions.php never updates or
-- deletes a consent_version row; scripts/legal-versioning-probe.php holds it to that).
-- Drafts live in their own table so a published row never changes.
-- user_consent gains a hashed IP and the door the acceptance came through.
--
-- Additive and idempotent only. Existing rows keep requires_reacceptance = 1, which is
-- what check_reconsent_needed() has always assumed of every new version.
-- REMINDER: $db_version = 5.3 in include/bootstrap.php.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE `consent_version`
    ADD COLUMN IF NOT EXISTS `content` LONGTEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `app_slug` VARCHAR(64) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS `title` VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `requires_reacceptance` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `content_sha256` CHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `published_by` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `published_by_name` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `published_at` DATETIME DEFAULT NULL;

ALTER TABLE `consent_version`
    ADD INDEX IF NOT EXISTS `idx_type_app` (`consent_type`, `app_slug`, `version_id`);

CREATE TABLE IF NOT EXISTS `consent_version_draft` (
    `draft_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consent_type` VARCHAR(50) NOT NULL,
    `app_slug` VARCHAR(64) NOT NULL DEFAULT '',
    `title` VARCHAR(200) DEFAULT NULL,
    `content` LONGTEXT DEFAULT NULL,
    `summary` TEXT DEFAULT NULL,
    `requires_reacceptance` TINYINT(1) NOT NULL DEFAULT 0,
    `effective_date` DATE DEFAULT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_by_name` VARCHAR(255) DEFAULT NULL,
    `updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`draft_id`),
    UNIQUE KEY `uniq_type_app` (`consent_type`, `app_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `user_consent`
    ADD COLUMN IF NOT EXISTS `ip_hash` CHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `source` VARCHAR(32) DEFAULT NULL;

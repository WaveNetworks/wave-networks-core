-- Migration 3.9 for Main Database
-- Onboarding tour framework: welcome modal + guided tour with DB-editable steps.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.9;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS onboarding_tour (
    tour_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug                 VARCHAR(100) NOT NULL,
    name                 VARCHAR(200) NOT NULL,
    welcome_title        VARCHAR(200) NOT NULL DEFAULT '',
    welcome_body_md      TEXT,
    welcome_cta_primary  VARCHAR(100) NOT NULL DEFAULT 'Take the tour',
    welcome_cta_secondary VARCHAR(100) NOT NULL DEFAULT 'Explore on my own',
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    created_by_app       VARCHAR(50) NOT NULL DEFAULT 'core',
    created              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tour_id),
    UNIQUE KEY uk_onboarding_tour_slug (slug),
    KEY idx_onboarding_tour_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS onboarding_tour_step (
    step_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tour_id          INT UNSIGNED NOT NULL,
    step_order       INT NOT NULL DEFAULT 0,
    selector         VARCHAR(255) NOT NULL DEFAULT '',
    title            VARCHAR(200) NOT NULL DEFAULT '',
    body_md          TEXT,
    position         ENUM('top','bottom','left','right','center') NOT NULL DEFAULT 'bottom',
    action           VARCHAR(50) DEFAULT NULL,
    visible_if_role  VARCHAR(50) DEFAULT NULL,
    PRIMARY KEY (step_id),
    KEY idx_onboarding_tour_step_order (tour_id, step_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

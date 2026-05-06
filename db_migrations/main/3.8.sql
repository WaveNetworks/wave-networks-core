-- Migration 3.8 for Main Database
-- Onboarding email infrastructure: scheduled sends, drip campaigns, templates,
-- and an extensible trigger-event reference table. Sits on top of the existing
-- email_queue / queue_email() / cron pipeline (queue_email() unchanged).
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.8;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Reusable email templates (Mustache-style {{var}} interpolation)
CREATE TABLE IF NOT EXISTS email_template (
    template_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(100) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    subject_tpl     VARCHAR(500) NOT NULL DEFAULT '',
    body_tpl        LONGTEXT,
    body_format     ENUM('html','markdown') NOT NULL DEFAULT 'html',
    created_by_app  VARCHAR(50) NOT NULL DEFAULT 'core',
    created         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id),
    UNIQUE KEY uk_email_template_slug (slug),
    KEY idx_email_template_app (created_by_app)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Drip campaigns enrol users into ordered sequences of templated emails
CREATE TABLE IF NOT EXISTS email_drip_campaign (
    campaign_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(100) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    description     TEXT,
    trigger_event   VARCHAR(100) NOT NULL DEFAULT '',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by_app  VARCHAR(50) NOT NULL DEFAULT 'core',
    created         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id),
    UNIQUE KEY uk_email_drip_campaign_slug (slug),
    KEY idx_email_drip_campaign_active (is_active),
    KEY idx_email_drip_campaign_trigger (trigger_event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Steps inside a drip campaign — ordered, each tied to a template + delay
-- send_condition_event (optional): if user has fired this event since being
-- enrolled, the step is auto-cancelled (e.g. skip 'add a coach' email if
-- coach_added fired).
CREATE TABLE IF NOT EXISTS email_drip_step (
    step_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id          INT UNSIGNED NOT NULL,
    step_order           INT NOT NULL DEFAULT 0,
    delay_minutes        INT NOT NULL DEFAULT 0,
    template_slug        VARCHAR(100) NOT NULL,
    send_condition_event VARCHAR(100) DEFAULT NULL,
    created              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (step_id),
    KEY idx_email_drip_step_campaign (campaign_id, step_order),
    KEY idx_email_drip_step_template (template_slug),
    KEY idx_email_drip_step_skip (send_condition_event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per user enrolled in a campaign. current_step is 0 before the
-- first send, 1 after step 1 is scheduled, etc.
CREATE TABLE IF NOT EXISTS email_drip_enrollment (
    enrollment_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    campaign_slug   VARCHAR(100) NOT NULL,
    current_step    INT NOT NULL DEFAULT 0,
    next_send_at    DATETIME DEFAULT NULL,
    status          ENUM('active','paused','completed','unenrolled') NOT NULL DEFAULT 'active',
    context_json    JSON,
    enrolled_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME DEFAULT NULL,
    PRIMARY KEY (enrollment_id),
    UNIQUE KEY uk_email_drip_enrollment_user_campaign (user_id, campaign_slug),
    KEY idx_email_drip_enrollment_status (status, next_send_at),
    KEY idx_email_drip_enrollment_campaign (campaign_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generic scheduled-email rows. The cron picks up status='pending' rows
-- where scheduled_for <= NOW(), renders the template, and calls queue_email().
-- enrollment_id links back to the drip enrollment when this row was scheduled
-- by a campaign step (so the cron can advance the enrollment after sending).
CREATE TABLE IF NOT EXISTS scheduled_email (
    scheduled_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    template_slug   VARCHAR(100) NOT NULL,
    scheduled_for   DATETIME NOT NULL,
    context_json    JSON,
    enrollment_id   BIGINT UNSIGNED DEFAULT NULL,
    drip_step_id    INT UNSIGNED DEFAULT NULL,
    skip_event      VARCHAR(100) DEFAULT NULL,
    status          ENUM('pending','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
    sent_at         DATETIME DEFAULT NULL,
    queue_id        INT UNSIGNED DEFAULT NULL,
    error_message   VARCHAR(500) DEFAULT NULL,
    created         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (scheduled_id),
    KEY idx_scheduled_email_pending (status, scheduled_for),
    KEY idx_scheduled_email_user (user_id, status),
    KEY idx_scheduled_email_enrollment (enrollment_id),
    KEY idx_scheduled_email_skip (skip_event, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reference table: the canonical list of trigger events. Child apps register
-- new events at bootstrap via add_email_trigger_event($slug, $label).
CREATE TABLE IF NOT EXISTS email_trigger_event (
    event_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(100) NOT NULL,
    label       VARCHAR(200) NOT NULL DEFAULT '',
    description VARCHAR(500) NOT NULL DEFAULT '',
    created_by_app VARCHAR(50) NOT NULL DEFAULT 'core',
    created     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    UNIQUE KEY uk_email_trigger_event_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the initial event set
INSERT INTO email_trigger_event (slug, label, created_by_app) VALUES
    ('user_registered',      'User registered',          'core'),
    ('first_login',          'First login',              'core'),
    ('tour_started',         'Tour started',             'core'),
    ('tour_completed',       'Tour completed',           'core'),
    ('tour_skipped',         'Tour skipped',             'core'),
    ('assessment_completed', 'Assessment completed',     'core'),
    ('coach_added',          'Coach added',              'core'),
    ('content_viewed',       'Content viewed',           'core'),
    ('weekly_digest',        'Weekly digest',            'core')
ON DUPLICATE KEY UPDATE label = VALUES(label);

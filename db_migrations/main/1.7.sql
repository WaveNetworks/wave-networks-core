-- Migration 1.7 for Main Database
-- Email settings, allowed senders, and email queue tables
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.7;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Single-row email configuration (like auth_settings)
CREATE TABLE IF NOT EXISTS `email_settings` (
  `setting_id`         int(11) NOT NULL AUTO_INCREMENT,
  `smtp_host`          varchar(255) DEFAULT NULL,
  `smtp_port`          int(11) DEFAULT 587,
  `smtp_user`          varchar(255) DEFAULT NULL,
  `smtp_pass`          varchar(500) DEFAULT NULL,
  `smtp_encryption`    enum('tls','ssl','none') NOT NULL DEFAULT 'tls',
  `default_from_email` varchar(255) DEFAULT NULL,
  `default_from_name`  varchar(255) DEFAULT NULL,
  `default_reply_to`   varchar(255) DEFAULT NULL,
  `throttle_per_minute` int(11) NOT NULL DEFAULT 10,
  `throttle_per_hour`   int(11) NOT NULL DEFAULT 200,
  `max_attempts`        int(11) NOT NULL DEFAULT 3,
  `updated`            datetime DEFAULT NULL,
  PRIMARY KEY (`setting_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `email_settings` (`setting_id`) VALUES (1)
  ON DUPLICATE KEY UPDATE `setting_id` = 1;

-- Whitelist of allowed sender email addresses
CREATE TABLE IF NOT EXISTS `email_allowed_sender` (
  `sender_id`    int(11) NOT NULL AUTO_INCREMENT,
  `email_address` varchar(255) NOT NULL,
  `display_name`  varchar(255) DEFAULT NULL,
  `is_default`    tinyint(1) NOT NULL DEFAULT 0,
  `created`       datetime DEFAULT NOW(),
  PRIMARY KEY (`sender_id`),
  UNIQUE KEY `idx_email` (`email_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outbound email queue with throttling support
CREATE TABLE IF NOT EXISTS `email_queue` (
  `queue_id`      int(11) NOT NULL AUTO_INCREMENT,
  `from_email`    varchar(255) NOT NULL,
  `from_name`     varchar(255) DEFAULT NULL,
  `reply_to`      varchar(255) DEFAULT NULL,
  `to_email`      varchar(255) NOT NULL,
  `to_name`       varchar(255) DEFAULT NULL,
  `subject`       varchar(500) NOT NULL,
  `body_html`     mediumtext NOT NULL,
  `body_text`     mediumtext DEFAULT NULL,
  `status`        enum('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  `attempts`      int(11) NOT NULL DEFAULT 0,
  `max_attempts`  int(11) NOT NULL DEFAULT 3,
  `error_message` text DEFAULT NULL,
  `source_app`    varchar(50) DEFAULT 'core',
  `created`       datetime DEFAULT NOW(),
  `scheduled_at`  datetime DEFAULT NOW(),
  `sent_at`       datetime DEFAULT NULL,
  PRIMARY KEY (`queue_id`),
  KEY `idx_status_scheduled` (`status`, `scheduled_at`),
  KEY `idx_source_app` (`source_app`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

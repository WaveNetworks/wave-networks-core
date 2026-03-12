-- Migration 1.6 for Main Database
-- Notification categories table — global reference data for the notification system
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.6;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `notification_category` (
  `category_id`              int(11) NOT NULL AUTO_INCREMENT,
  `slug`                     varchar(50) NOT NULL,
  `name`                     varchar(100) NOT NULL,
  `description`              text DEFAULT NULL,
  `icon`                     varchar(50) NOT NULL DEFAULT 'bi-bell',
  `is_system`                tinyint(1) NOT NULL DEFAULT 0,
  `allow_frequency_override` tinyint(1) NOT NULL DEFAULT 1,
  `default_frequency`        enum('realtime','daily','weekly','off') NOT NULL DEFAULT 'realtime',
  `created_by_app`           varchar(50) DEFAULT NULL,
  `created`                  datetime DEFAULT NOW(),
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB;

-- Default categories
INSERT INTO `notification_category` (`slug`, `name`, `description`, `icon`, `is_system`, `allow_frequency_override`, `default_frequency`)
VALUES ('security', 'Security Alerts', 'Login alerts, password changes, and security events', 'bi-shield-lock', 1, 0, 'realtime')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `notification_category` (`slug`, `name`, `description`, `icon`, `is_system`, `allow_frequency_override`, `default_frequency`)
VALUES ('system_updates', 'System Updates', 'Platform updates, maintenance notices, and feature announcements', 'bi-gear', 0, 1, 'realtime')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `notification_category` (`slug`, `name`, `description`, `icon`, `is_system`, `allow_frequency_override`, `default_frequency`)
VALUES ('admin_broadcast', 'Admin Broadcasts', 'Messages sent by administrators to all users', 'bi-megaphone', 0, 1, 'realtime')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

COMMIT;

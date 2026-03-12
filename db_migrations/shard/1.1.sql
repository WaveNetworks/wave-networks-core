-- Migration 1.1 for Shard Database
-- Notification system tables: notifications, user preferences, push subscriptions
-- ⚠️ REMINDER: Update admin/include/common.php $shard_version = 1.1;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Per-user notifications (replaces the old main DB notification table)
CREATE TABLE IF NOT EXISTS `notification` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id`         int(11) NOT NULL,
  `category_slug`   varchar(50) NOT NULL DEFAULT 'system_updates',
  `title`           varchar(255) NOT NULL,
  `body`            text DEFAULT NULL,
  `action_url`      varchar(500) DEFAULT NULL,
  `action_label`    varchar(100) DEFAULT NULL,
  `is_read`         tinyint(1) NOT NULL DEFAULT 0,
  `push_sent`       tinyint(1) NOT NULL DEFAULT 0,
  `source_app`      varchar(50) DEFAULT NULL,
  `created`         datetime DEFAULT NOW(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_created` (`user_id`, `created` DESC),
  KEY `idx_user_unread` (`user_id`, `is_read`),
  KEY `idx_push_pending` (`push_sent`, `user_id`)
) ENGINE=InnoDB;

-- Per-user per-category notification preferences
CREATE TABLE IF NOT EXISTS `notification_preference` (
  `user_id`       int(11) NOT NULL,
  `category_slug` varchar(50) NOT NULL,
  `frequency`     enum('realtime','daily','weekly','off') NOT NULL DEFAULT 'realtime',
  `push_enabled`  tinyint(1) NOT NULL DEFAULT 1,
  `updated`       datetime DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (`user_id`, `category_slug`)
) ENGINE=InnoDB;

-- Web Push API subscriptions (per user, per browser/device)
CREATE TABLE IF NOT EXISTS `push_subscription` (
  `subscription_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id`         int(11) NOT NULL,
  `endpoint`        text NOT NULL,
  `p256dh_key`      varchar(255) NOT NULL,
  `auth_key`        varchar(255) NOT NULL,
  `user_agent`      varchar(255) DEFAULT NULL,
  `created`         datetime DEFAULT NOW(),
  `last_used`       datetime DEFAULT NULL,
  PRIMARY KEY (`subscription_id`),
  UNIQUE KEY `idx_endpoint` (`endpoint`(500)),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB;

COMMIT;

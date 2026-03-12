-- Migration 1.0 - wave-networks-core Main Database
-- ⚠️ REMINDER: Update /admin/include/common.php $db_version = 1.0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `db_version` (
  `version_id` int(11) NOT NULL AUTO_INCREMENT,
  `version`    decimal(10,1) NOT NULL DEFAULT 0.0,
  PRIMARY KEY (`version_id`)
) ENGINE=InnoDB;

INSERT INTO `db_version` (`version_id`, `version`) VALUES (1, 1.0)
  ON DUPLICATE KEY UPDATE version = 1.0;

-- Auth-only user table. Holds ONLY what is needed to authenticate and route.
-- Profile data (first_name, last_name, homedir, etc.) lives on the shard.
-- shard_id routes all subsequent queries after login - never changes once assigned.
CREATE TABLE IF NOT EXISTS `user` (
  `user_id`        int(11) NOT NULL AUTO_INCREMENT,
  `email`          varchar(255) NOT NULL,
  `password`       varchar(255) DEFAULT NULL,
  `shard_id`       varchar(20) NOT NULL DEFAULT 'shard1',
  `is_admin`       tinyint(1) DEFAULT 0,
  `is_owner`       tinyint(1) DEFAULT 0,
  `is_manager`     tinyint(1) DEFAULT 0,
  `is_employee`    tinyint(1) DEFAULT 0,
  `is_confirmed`   tinyint(1) DEFAULT 0,
  `confirm_hash`   varchar(255) DEFAULT NULL,
  `created_date`   datetime DEFAULT NOW(),
  `last_login`     datetime DEFAULT NULL,
  `totp_secret`    varchar(255) DEFAULT NULL,
  `totp_enabled`   tinyint(1) DEFAULT 0,
  `oauth_provider` varchar(50) DEFAULT NULL,
  `oauth_id`       varchar(255) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  KEY `shard_id` (`shard_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `device` (
  `device_id`  int(11) NOT NULL AUTO_INCREMENT,
  `cookie_id`  varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created`    datetime DEFAULT NOW(),
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `api_key` (
  `key_id`      int(11) NOT NULL AUTO_INCREMENT,
  `device_id`   int(11) DEFAULT NULL,
  `user_id`     int(11) DEFAULT NULL,
  `api_key`     varchar(255) DEFAULT NULL,
  `key_born`    date DEFAULT NULL,
  `remember_me` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`key_id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `forgot` (
  `forgot_id`    int(11) NOT NULL AUTO_INCREMENT,
  `user_id`      int(11) DEFAULT NULL,
  `forgot_token` varchar(255) DEFAULT NULL,
  `created`      datetime DEFAULT NOW(),
  `used`         tinyint(1) DEFAULT 0,
  PRIMARY KEY (`forgot_id`),
  KEY `forgot_token` (`forgot_token`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `invite` (
  `invite_id`    int(11) NOT NULL AUTO_INCREMENT,
  `email`        varchar(255) DEFAULT NULL,
  `invite_token` varchar(255) DEFAULT NULL,
  `created_by`   int(11) DEFAULT NULL,
  `created`      datetime DEFAULT NOW(),
  `used`         tinyint(1) DEFAULT 0,
  PRIMARY KEY (`invite_id`),
  KEY `invite_token` (`invite_token`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `auth_settings` (
  `setting_id`        int(11) NOT NULL AUTO_INCREMENT,
  `registration_mode` varchar(20) DEFAULT 'open',
  PRIMARY KEY (`setting_id`)
) ENGINE=InnoDB;

INSERT INTO `auth_settings` (`setting_id`, `registration_mode`) VALUES (1, 'open')
  ON DUPLICATE KEY UPDATE setting_id = setting_id;

CREATE TABLE IF NOT EXISTS `oauth_provider` (
  `provider_id`   int(11) NOT NULL AUTO_INCREMENT,
  `provider_name` varchar(50) DEFAULT NULL,
  `client_id`     varchar(255) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `is_enabled`    tinyint(1) DEFAULT 0,
  PRIMARY KEY (`provider_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `notification` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id`         int(11) DEFAULT NULL,
  `message`         text DEFAULT NULL,
  `is_read`         tinyint(1) DEFAULT 0,
  `created`         datetime DEFAULT NOW(),
  PRIMARY KEY (`notification_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `cron_log` (
  `log_id`   int(11) NOT NULL AUTO_INCREMENT,
  `job`      varchar(255) DEFAULT NULL,
  `ran_at`   datetime DEFAULT NOW(),
  `result`   text DEFAULT NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB;

COMMIT;

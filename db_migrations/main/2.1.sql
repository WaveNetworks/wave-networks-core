-- Migration 2.1 for Main Database
-- Add error_log table for PHP error tracking
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.1;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `error_log` (
  `error_id`       int(11) NOT NULL AUTO_INCREMENT,
  `level`          enum('DEBUG','INFO','WARNING','ERROR','FATAL') NOT NULL DEFAULT 'ERROR',
  `message`        text NOT NULL,
  `file`           varchar(500) DEFAULT NULL,
  `line`           int(11) DEFAULT NULL,
  `stack_trace`    mediumtext DEFAULT NULL,
  `context_json`   mediumtext DEFAULT NULL COMMENT 'JSON: request, session, server info',
  `source_app`     varchar(50) DEFAULT 'admin',
  `page`           varchar(100) DEFAULT NULL COMMENT 'The ?page= view active at time of error',
  `request_uri`    varchar(500) DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `user_id`        int(11) DEFAULT NULL,
  `ip_address`     varchar(45) DEFAULT NULL,
  `user_agent`     varchar(500) DEFAULT NULL,
  `php_version`    varchar(20) DEFAULT NULL,
  `memory_usage`   int(11) DEFAULT NULL COMMENT 'bytes at time of error',
  `created`        datetime DEFAULT NOW(),
  PRIMARY KEY (`error_id`),
  KEY `idx_level_created` (`level`, `created`),
  KEY `idx_created` (`created`),
  KEY `idx_source_app` (`source_app`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB;

COMMIT;

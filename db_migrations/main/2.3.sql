-- Migration 2.3 for Main Database
-- Add service_api_key table for programmatic API access with scoped permissions
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.3;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `service_api_key` (
  `service_key_id` int(11) NOT NULL AUTO_INCREMENT,
  `key_name`       varchar(100) NOT NULL,
  `key_prefix`     varchar(12) NOT NULL COMMENT 'First 12 chars for identification (wn_sk_ + 6 random)',
  `key_hash`       varchar(255) NOT NULL COMMENT 'bcrypt hash of full key',
  `scopes`         text NOT NULL COMMENT 'JSON array of scope strings',
  `created_by`     int(11) NOT NULL,
  `created_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`   datetime DEFAULT NULL,
  `revoked_at`     datetime DEFAULT NULL,
  `revoked_by`     int(11) DEFAULT NULL,
  PRIMARY KEY (`service_key_id`),
  KEY `idx_key_prefix` (`key_prefix`),
  KEY `idx_revoked` (`revoked_at`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

-- Migration 1.4 for Main Database
-- Add migration_source and user_migration_map tables for parallel auth migration
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 1.4;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `migration_source` (
  `source_id`          int(11) NOT NULL AUTO_INCREMENT,
  `source_name`        varchar(100) NOT NULL,
  `db_host`            varchar(255) NOT NULL,
  `db_port`            int(11) NOT NULL DEFAULT 3306,
  `db_name`            varchar(100) NOT NULL,
  `db_user`            varchar(100) NOT NULL,
  `db_password_enc`    varchar(500) NOT NULL,
  `user_table`         varchar(100) NOT NULL DEFAULT 'users',
  `col_id`             varchar(100) NOT NULL DEFAULT 'id',
  `col_email`          varchar(100) NOT NULL DEFAULT 'email',
  `col_password`       varchar(100) DEFAULT 'password',
  `col_first_name`     varchar(100) DEFAULT 'first_name',
  `col_last_name`      varchar(100) DEFAULT 'last_name',
  `password_algo`      varchar(50) NOT NULL DEFAULT 'bcrypt',
  `password_salt`      varchar(255) DEFAULT NULL,
  `saml_provider_slug` varchar(50) DEFAULT NULL,
  `sync_enabled`       tinyint(1) NOT NULL DEFAULT 0,
  `sync_filter_sql`    text DEFAULT NULL,
  `last_sync_at`       datetime DEFAULT NULL,
  `created`            datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated`            datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_migration_map` (
  `map_id`               int(11) NOT NULL AUTO_INCREMENT,
  `source_id`            int(11) NOT NULL,
  `external_user_id`     varchar(100) NOT NULL,
  `core_user_id`         int(11) DEFAULT NULL,
  `external_email`       varchar(255) NOT NULL,
  `legacy_password_hash` varchar(500) DEFAULT NULL,
  `legacy_hash_algo`     varchar(50) DEFAULT NULL,
  `password_migrated`    tinyint(1) NOT NULL DEFAULT 0,
  `sync_status`          enum('pending','synced','conflict','skipped') NOT NULL DEFAULT 'pending',
  `conflict_reason`      varchar(255) DEFAULT NULL,
  `synced_at`            datetime DEFAULT NULL,
  `created`              datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`map_id`),
  KEY `idx_external` (`source_id`, `external_user_id`),
  KEY `idx_core_user` (`core_user_id`),
  KEY `idx_email` (`external_email`),
  KEY `idx_status` (`sync_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

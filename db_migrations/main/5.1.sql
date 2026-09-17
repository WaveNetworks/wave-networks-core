-- Migration 5.1 for Main Database
-- User-deletion events: every path that erases an account (admin delete, the 30-day
-- self-delete cron, the API) records one row, and each child app marks its own purge
-- done per app from its cron (process_user_deletion_events). A child app that was not
-- loaded in the request that deleted the user still catches up, and a failed purge is
-- retried. Holds no personal data: the user id, their shard and where the delete came from.
-- REMINDER: $db_version = 5.1 in include/bootstrap.php.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `user_deletion_event` (
    `event_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `shard_id` VARCHAR(64) NULL DEFAULT NULL,
    `source` VARCHAR(32) NOT NULL DEFAULT 'delete_user_data',
    `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`),
    UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_deletion_event_app` (
    `event_id` INT UNSIGNED NOT NULL,
    `app_slug` VARCHAR(64) NOT NULL,
    `status` ENUM('done','failed') NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(1000) NULL DEFAULT NULL,
    `updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`, `app_slug`),
    INDEX `idx_app_status` (`app_slug`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

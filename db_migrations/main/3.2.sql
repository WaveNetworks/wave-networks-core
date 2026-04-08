-- Migration 3.2 for Main Database
-- Add refresh_tokens table for JWT refresh token rotation (mobile app auth)
-- REMINDER: Update admin/include/common.php $db_version = 3.2;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `device_id` VARCHAR(255) NOT NULL DEFAULT '',
    `platform` ENUM('ios','android','web') NOT NULL DEFAULT 'web',
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_device` (`user_id`, `device_id`),
    INDEX `idx_expires` (`expires_at`),
    INDEX `idx_revoked` (`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

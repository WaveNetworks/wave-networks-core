-- Migration 5.2 for Main Database
-- Device sign-up throttle (deviceRegister, include/common/registrationFunctions.php).
-- reCAPTCHA cannot run inside a bundled app, so app sign-ups are rate limited per IP,
-- per device and site-wide instead. Holds only SHA-256 hashes and a UTC time; rows older
-- than two days are pruned by the throttle itself.
-- REMINDER: $db_version = 5.2 in include/bootstrap.php.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `device_register_attempt` (
    `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_hash` CHAR(64) NOT NULL,
    `device_hash` CHAR(64) NULL DEFAULT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`attempt_id`),
    KEY `idx_ip` (`ip_hash`, `created`),
    KEY `idx_device` (`device_hash`, `created`),
    KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

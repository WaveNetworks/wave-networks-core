-- Migration 7.1 for Main Database
-- thanks_consent: may we thank this person by first name for something they asked for? (task #2725)
-- Each app has its own admin database, so one row per email here is one per person per app.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS thanks_consent (
    consent_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    first_name VARCHAR(60) DEFAULT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    decided_where VARCHAR(40) NOT NULL DEFAULT '',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email (email),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

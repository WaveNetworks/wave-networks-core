-- Migration 2.6 for Main Database
-- Device table upgrade for session management + legal content storage
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.6;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Add user_id, browser name, and last_used to device table for session management
ALTER TABLE device
    ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER device_id,
    ADD COLUMN browser VARCHAR(100) DEFAULT NULL AFTER user_agent,
    ADD COLUMN last_used DATETIME DEFAULT NULL AFTER created,
    ADD INDEX idx_user_id (user_id);

-- Add full legal content to consent_version for versioned legal pages
ALTER TABLE consent_version
    ADD COLUMN content LONGTEXT DEFAULT NULL AFTER summary,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER content;

-- Add login_history table for tracking every login event
CREATE TABLE IF NOT EXISTS login_history (
    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(512) DEFAULT NULL,
    browser VARCHAR(100) DEFAULT NULL,
    login_method ENUM('password','oauth','remember_me','saml','2fa') NOT NULL DEFAULT 'password',
    status ENUM('success','failed') NOT NULL DEFAULT 'success',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

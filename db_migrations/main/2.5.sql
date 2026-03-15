-- Migration 2.5 for Main Database
-- GDPR compliance: consent tracking, data export requests, account deletion requests
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.5;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Policy document versions
CREATE TABLE IF NOT EXISTS consent_version (
    version_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consent_type VARCHAR(50) NOT NULL,
    version_label VARCHAR(50) NOT NULL,
    effective_date DATE NOT NULL,
    document_url VARCHAR(500) DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_effective (consent_type, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Immutable consent event log
CREATE TABLE IF NOT EXISTS user_consent (
    consent_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    consent_type VARCHAR(50) NOT NULL,
    consent_version_id INT UNSIGNED DEFAULT NULL,
    action ENUM('granted','withdrawn') NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(512) DEFAULT NULL,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_type (user_id, consent_type),
    INDEX idx_created (created),
    CONSTRAINT fk_consent_version FOREIGN KEY (consent_version_id) REFERENCES consent_version(version_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Account deletion requests with cooling-off period
CREATE TABLE IF NOT EXISTS account_deletion_request (
    request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    reason TEXT DEFAULT NULL,
    status ENUM('pending','cancelled','completed') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancel_before DATETIME NOT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    completed_by VARCHAR(50) DEFAULT NULL,
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_cancel_before (cancel_before)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data export request audit trail
CREATE TABLE IF NOT EXISTS data_export_request (
    export_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    format ENUM('json','csv') NOT NULL DEFAULT 'json',
    status ENUM('pending','processing','ready','expired','failed') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed initial consent versions for terms and privacy policy
INSERT INTO consent_version (consent_type, version_label, effective_date, summary) VALUES
('terms_of_service', '1.0', '2026-03-15', 'Initial Terms of Service'),
('privacy_policy', '1.0', '2026-03-15', 'Initial Privacy Policy'),
('marketing_email', '1.0', '2026-03-15', 'Marketing email communications'),
('cookie_analytics', '1.0', '2026-03-15', 'Analytics cookies for site improvement'),
('cookie_marketing', '1.0', '2026-03-15', 'Marketing cookies for personalized ads');

COMMIT;

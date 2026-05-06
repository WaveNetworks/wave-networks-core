-- Migration 4.0 for Main Database
-- Resend confirmation email: add confirm_hash_created timestamp and resend throttle table.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 4.0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE user ADD COLUMN confirm_hash_created DATETIME NULL DEFAULT NULL AFTER confirm_hash;

CREATE TABLE IF NOT EXISTS confirmation_resend_throttle (
    email         VARCHAR(255) NOT NULL,
    last_sent_at  DATETIME NOT NULL,
    PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

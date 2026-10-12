-- Migration 5.8 for Main Database
-- Sign in on a computer by approving it from the signed-in phone app (ContactSwipe task #2391)
--
--   login_approval — one row per "sign in with your phone" request from a web sign-in
--   page. The QR carries a random token (stored as sha256 only); the browser that asked
--   holds a second random nonce in a cookie (sha256 only), so only that browser can
--   collect the sign-in. Two minutes, single use. status: pending -> approved -> used,
--   or pending -> denied. user_id is whoever approved, from their app session.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 5.8;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS login_approval (
    login_approval_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash    CHAR(64)        NOT NULL,
    nonce_hash    CHAR(64)        NOT NULL,
    status        ENUM('pending','approved','denied','used') NOT NULL DEFAULT 'pending',
    user_id       INT UNSIGNED    NOT NULL DEFAULT 0,
    ip_address    VARCHAR(45)     NOT NULL DEFAULT '',
    user_agent    VARCHAR(512)    NOT NULL DEFAULT '',
    country       CHAR(2)         NOT NULL DEFAULT '',
    created       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires       DATETIME        NOT NULL,
    decided       DATETIME        NULL,
    decided_ip    VARCHAR(45)     NOT NULL DEFAULT '',
    PRIMARY KEY (login_approval_id),
    UNIQUE KEY uq_token (token_hash),
    KEY idx_nonce (nonce_hash),
    KEY idx_ip_created (ip_address, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 5.7 for Main Database
-- Sign in with an emailed code instead of a password (ContactSwipe task #2389)
--
--   1. login_code — one row per "email me a sign-in code" request. The 6-digit code
--      is stored only as a password_hash(). Rows are written for unknown emails too
--      (user_id 0, nothing sent) so the per-email/per-IP rate limit and the reply
--      are identical whether or not an account exists.
--   2. login_history.login_method gains 'email_code' (and 'phone_approve' for the
--      sibling "sign in with your phone" flow, task #2391).
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 5.7;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS login_code (
    login_code_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255)    NOT NULL,
    user_id       INT UNSIGNED    NOT NULL DEFAULT 0,
    code_hash     VARCHAR(255)    NOT NULL,
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used          TINYINT(1)      NOT NULL DEFAULT 0,
    ip_address    VARCHAR(45)     NOT NULL DEFAULT '',
    user_agent    VARCHAR(512)    NOT NULL DEFAULT '',
    created       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires       DATETIME        NOT NULL,
    PRIMARY KEY (login_code_id),
    KEY idx_email_created (email, created),
    KEY idx_ip_created (ip_address, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE login_history MODIFY login_method ENUM('password','oauth','remember_me','saml','2fa','email_code','phone_approve') NOT NULL DEFAULT 'password';

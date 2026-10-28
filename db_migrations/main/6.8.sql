-- Migration 6.8 for Main Database
-- twofa_email_code: the LAST-RESORT second factor, a code to the account's verified email
-- ("Can't use any of these?" on auth/2fa.php; include/common/twofaEmailFunctions.php, task #2660).
-- Its own table, not login_code: the sign-in code replaces the PASSWORD, this replaces the SECOND
-- factor, and neither may ever stand in for the other. Only the password_hash() of a code is kept.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.8;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS twofa_email_code (
  twofa_email_code_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NULL DEFAULT NULL,
  user_agent VARCHAR(512) NULL DEFAULT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL,
  expires DATETIME NOT NULL,
  PRIMARY KEY (twofa_email_code_id),
  KEY idx_user_created (user_id, created),
  KEY idx_ip_created (ip_address, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 6.9 for Main Database
-- user_passkey: WebAuthn passkeys (include/common/passkeyFunctions.php, task #2661, part 3 of #2658).
-- One row per credential: the credential id (base64url), the PEM public key, the signature counter,
-- the person's name for it, and the random user handle the authenticator stores. Never a secret:
-- the private key never leaves the person's device.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.9;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS user_passkey (
  passkey_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  credential_id VARCHAR(255) NOT NULL,
  user_handle VARCHAR(64) NOT NULL,
  public_key TEXT NOT NULL,
  sign_count INT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(80) NOT NULL DEFAULT 'Passkey',
  aaguid VARCHAR(36) NULL DEFAULT NULL,
  backed_up TINYINT(1) NOT NULL DEFAULT 0,
  created DATETIME NOT NULL,
  last_used DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (passkey_id),
  UNIQUE KEY uq_credential (credential_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

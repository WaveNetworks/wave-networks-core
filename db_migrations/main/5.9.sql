-- Migration 5.9 for Main Database
-- Task #2397: one-time tokens are only COMPARED, so they are stored hashed.
-- forgot.forgot_token and invite.invite_token become 'sha256:' + SHA2(token, 256), matching
-- wn_token_hash() in include/common/secretFunctions.php. Idempotent: rows already carrying
-- the marker are skipped, so running this twice changes nothing.
-- (Encrypted columns — smtp_pass, client_secret, totp_secret — cannot be sealed in SQL, the
-- key lives in config; they are sealed by wn_secret_seal_sweep(), cron/days/1/seal_stored_secrets.php.)
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 5.9;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

UPDATE `forgot` SET `forgot_token` = CONCAT('sha256:', SHA2(`forgot_token`, 256))
 WHERE `forgot_token` IS NOT NULL AND `forgot_token` <> '' AND `forgot_token` NOT LIKE 'sha256:%';

UPDATE `invite` SET `invite_token` = CONCAT('sha256:', SHA2(`invite_token`, 256))
 WHERE `invite_token` IS NOT NULL AND `invite_token` <> '' AND `invite_token` NOT LIKE 'sha256:%';

-- A sealed value is longer than the plain one (enc:v1: + base64 of IV + ciphertext); give the
-- OAuth client secret room so a long secret is never truncated. (smtp_pass is already 500;
-- TOTP secrets are 16–32 chars and seal to ~70.)
ALTER TABLE `oauth_provider` MODIFY `client_secret` TEXT NULL;

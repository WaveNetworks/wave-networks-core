-- Migration 1.6 for Shard Database
-- Task #2662: tie a phone's sign-in approvals to a key kept in its Keychain/Keystore behind
-- biometrics (cordova-plugin-fingerprint-aio). Only sha256 of the key is stored. Once any phone
-- on the account holds a key, an approval counts only with the key of a phone still allowed,
-- so a removed phone that is still signed in can no longer approve.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $shard_version = 1.6;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE `push_subscription` ADD COLUMN IF NOT EXISTS `device_key_hash` char(64) DEFAULT NULL AFTER `approve_signin`;

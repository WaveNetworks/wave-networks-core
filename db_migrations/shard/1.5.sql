-- Migration 1.5 for Shard Database
-- Task #2662: revoke a phone from approving sign-ins without removing its notifications.
-- approve_signin = 0 → the 2FA push is not offered for it, not sent to it, and re-registering
-- the same token on the next app open keeps it off (save_push_subscription never touches it).
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $shard_version = 1.5;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE `push_subscription` ADD COLUMN IF NOT EXISTS `approve_signin` tinyint(1) NOT NULL DEFAULT 1 AFTER `user_agent`;

-- Migration 6.3 for Main Database
-- oauth_token: a provider refresh token per user, sealed (secretFunctions.php), kept ONLY so that
-- deleting the account can revoke it — Apple requires apps offering Sign in with Apple to revoke
-- the user's tokens on account deletion (nokemo task #2462). oauth_revoke_user_tokens() runs as a
-- delete_user_data() hook and removes the rows.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.3;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `oauth_token` (
  `user_id` int(10) unsigned NOT NULL,
  `provider` varchar(30) NOT NULL,
  `refresh_token` text NOT NULL,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`, `provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

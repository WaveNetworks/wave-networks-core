-- Migration 6.2 for Main Database
-- oauth_handoff: one-time codes that carry a "Continue with Google" sign-in made in the phone's
-- browser back into the app (nokemo task #2461). The browser finishes the provider sign-in, a code
-- is minted here (stored as sha256 only) and handed to the app's own URL scheme; the app trades it,
-- with the verifier only it holds (PKCE-style: challenge = sha256 hex of the verifier), for a
-- device token via the deviceOAuthExchange API action. Single use, 5-minute life.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.2;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `oauth_handoff` (
  `code_hash` char(64) NOT NULL,
  `challenge` char(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `provider` varchar(30) NOT NULL DEFAULT '',
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`code_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

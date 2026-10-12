-- Migration 6.1 for Main Database
-- email_queue.headers_json: extra headers a queued message carries (JSON object, name => value),
-- so a sender can thread a reply (In-Reply-To / References) or set its own Message-ID. Used by the
-- help-desk sub-admin (nokemo task #2395); any app may pass queue_email(..., ['headers' => [...]]).
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.1;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `headers_json` text DEFAULT NULL AFTER `body_text`;

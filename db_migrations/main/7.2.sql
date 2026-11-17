-- Migration 7.2 for Main Database
-- user.is_agent: an automation/agent account (task #2779). An agent account can never be the
-- owner and can never pass an owner or human-approval gate. Set only by scripts/set-owner.php
-- over SSH; nothing in the admin UI or the API writes it, or is_owner.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE `user` ADD COLUMN `is_agent` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;

-- Migration 1.0 - wave-networks-core Shard Database
-- ⚠️ REMINDER: Update /admin/include/common.php $shard_version = 1.0;
-- Applied to ALL shard databases. Each shard holds profile + child app data
-- for the subset of users assigned to it.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `db_version` (
  `version_id` int(11) NOT NULL AUTO_INCREMENT,
  `version`    decimal(10,1) NOT NULL DEFAULT 0.0,
  PRIMARY KEY (`version_id`)
) ENGINE=InnoDB;

INSERT INTO `db_version` (`version_id`, `version`) VALUES (1, 1.0)
  ON DUPLICATE KEY UPDATE version = 1.0;

-- Extended user profile - everything not needed for auth lives here.
-- Keyed by user_id from main DB. Never stores email or password.
-- homedir stores the absolute bucketed path created by create_home_dir_id().
CREATE TABLE IF NOT EXISTS `user_profile` (
  `user_id`       int(11) NOT NULL,
  `first_name`    varchar(100) DEFAULT NULL,
  `last_name`     varchar(100) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `homedir`       varchar(255) DEFAULT NULL,
  `referral_code` varchar(50) DEFAULT NULL,
  `regID`         varchar(255) DEFAULT NULL,
  `created`       datetime DEFAULT NOW(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB;

COMMIT;

-- Migration 2.0 for Main Database
-- Registered themes table — child apps auto-register custom themes here
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `registered_theme` (
  `theme_id`       int(11) NOT NULL AUTO_INCREMENT,
  `slug`           varchar(50) NOT NULL,
  `name`           varchar(100) NOT NULL,
  `css_path`       varchar(255) NOT NULL COMMENT 'Relative to webroot',
  `sidebar_mode`   enum('dark','glass') NOT NULL DEFAULT 'dark',
  `created_by_app` varchar(50) DEFAULT NULL,
  `is_active`      tinyint(1) NOT NULL DEFAULT 1,
  `created`        datetime DEFAULT NOW(),
  `updated`        datetime DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (`theme_id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB;

COMMIT;

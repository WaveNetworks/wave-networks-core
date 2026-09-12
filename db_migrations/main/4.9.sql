-- Migration 4.9 for Main Database
-- auth_settings.pwa_screenshot_tablet — the column the branding form already writes.
--
-- Migration 1.8 added two of the three PWA screenshot slots (wide, mobile). The
-- branding form has been writing a THIRD, pwa_screenshot_tablet, with no
-- migration ever creating it — so saving branding died with
--   Unknown column 'pwa_screenshot_tablet' in 'SET'
-- and took the whole save with it, not just the tablet image.
--
-- MariaDB supports ADD COLUMN IF NOT EXISTS, so this is safe to re-run and safe
-- on any host that already picked the column up by hand. The autocommit backstop
-- ensure_auth_settings_pwa_columns() in include/common/brandingFunctions.php is
-- the reliable path — the migration runner drops in-transaction DDL on MariaDB,
-- which is how a missing column survives a "successful" migration in the first
-- place.

ALTER TABLE `auth_settings`
    ADD COLUMN IF NOT EXISTS `pwa_screenshot_tablet` varchar(255) DEFAULT NULL AFTER `pwa_screenshot_wide`;

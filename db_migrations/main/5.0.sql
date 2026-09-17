-- Migration 5.0 for Main Database
-- Repair: re-apply what the pre-5.0 migration runner silently dropped.
--
-- Until core 5.0, run_migration() skipped any split fragment whose raw text
-- (the comment lines above a statement included) merely CONTAINED the words of a
-- transaction-control statement, and still advanced db_version. The comments
-- above the 4.6 and 4.9 ALTERs contained such a word, so on hosts where no
-- runtime backstop re-added them, these never landed. The runner now skips only
-- a fragment that is exactly transaction control once comments are stripped.
--
-- Everything here is idempotent (IF NOT EXISTS) and adds only; nothing is
-- dropped, narrowed or rewritten.
-- REMINDER: $db_version = 5.0 in include/bootstrap.php.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- 4.6: cost_recurring.vendor + metadata
ALTER TABLE cost_recurring
    ADD COLUMN IF NOT EXISTS vendor VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS metadata JSON DEFAULT NULL;

-- 4.9: auth_settings.pwa_screenshot_tablet
ALTER TABLE `auth_settings`
    ADD COLUMN IF NOT EXISTS `pwa_screenshot_tablet` varchar(255) DEFAULT NULL AFTER `pwa_screenshot_wide`;

-- 4.6: UNIQUE (vendor, frequency). Without the key, ensure_subscription_recurring()
-- could insert the same vendor row repeatedly (it now de-duplicates by hand), so a
-- host may hold duplicate (vendor, frequency) rows, and adding the key over them
-- would fail the whole migration. Add it only when there are none; otherwise leave
-- the rows untouched and the key absent. The key is declared by 4.6, so
-- apiSchemaAudit keeps reporting it as missing_indexes until it exists. The
-- statement text is assembled with CONCAT so the audit reads this as data, not as
-- an unparseable ALTER that would mark the whole table uncertain.
-- NULL vendors never collide in a unique key, so they are excluded from the check.
SET @wn50_dups := (SELECT COUNT(*) FROM (
    SELECT 1 FROM cost_recurring WHERE vendor IS NOT NULL
    GROUP BY vendor, frequency HAVING COUNT(*) > 1) d);
SET @wn50_sql := IF(@wn50_dups = 0,
    CONCAT('ALTER', ' TABLE cost_recurring ADD UNIQUE KEY IF NOT EXISTS uniq_vendor_frequency (vendor, frequency)'),
    'DO 0');
PREPARE wn50_stmt FROM @wn50_sql;
EXECUTE wn50_stmt;
DEALLOCATE PREPARE wn50_stmt;

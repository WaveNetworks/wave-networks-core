-- Migration 4.6 for Main Database
-- Subscription-model cost tracking: tag a recurring cost with a vendor + metadata
-- so a single cost_recurring row represents a flat-fee SaaS plan that
-- ensure_subscription_recurring() can idempotently upsert (keyed on vendor+frequency).
-- ⚠️ REMINDER: Update admin/include/common.php AND common_api.php $db_version = 4.6;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ONE ALTER statement = one implicit commit, which dodges the MariaDB multi-DDL
-- transaction bug (a separate later DDL can implicit-commit and drop an earlier one).
-- IF NOT EXISTS keeps it rerunnable on MariaDB. The new vendor column referenced by
-- the unique key is added in the same statement, which MariaDB resolves left-to-right.
ALTER TABLE cost_recurring
    ADD COLUMN IF NOT EXISTS vendor VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS metadata JSON DEFAULT NULL,
    ADD UNIQUE KEY IF NOT EXISTS uniq_vendor_frequency (vendor, frequency);

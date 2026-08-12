-- Migration 4.7 for Main Database
-- Shard / database size monitoring cache (populated hourly by cron and on-demand
-- from the /admin ?page=db_sizes view). Also created defensively at runtime by
-- ensure_db_size_cache_table() for hosts where the runner drops DDL.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 4.7;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS shard_db_size (
    schema_name  VARCHAR(128) NOT NULL,
    size_mb      DECIMAL(10,2) NOT NULL DEFAULT 0,
    table_count  INT UNSIGNED NOT NULL DEFAULT 0,
    status       VARCHAR(16) NOT NULL DEFAULT 'ok',
    last_checked DATETIME NULL,
    PRIMARY KEY (schema_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

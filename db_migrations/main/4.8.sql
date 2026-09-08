-- Migration 4.8 for Main Database
-- Shard registry — shards become DATA, not per-host config.
--
-- Until now $shardConfigs was a literal array in the gitignored, per-host
-- admin/config/config.php, hardcoded to exactly two shards. Adding a shard meant
-- hand-editing that file on every host, which does not scale to the shard counts
-- a 1GB-per-database ceiling forces (20i caps each schema at 1GB without Turbo,
-- so capacity can only grow by shard COUNT).
--
-- db_host is per-shard on purpose: shards may live on different packages or
-- servers, so one package's database-count cap is not a ceiling on the app.
--
-- An empty table means "behave exactly as before" — config.php stays the seed.
-- Also created defensively at runtime by ensure_shard_config_table() for hosts
-- where the decimal migration runner drops DDL (MariaDB).
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 4.8;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS shard_config (
    layer          VARCHAR(16)   NOT NULL DEFAULT 'admin',
    app_slug       VARCHAR(64)   NOT NULL DEFAULT '',
    shard_id       VARCHAR(32)   NOT NULL,
    db_host        VARCHAR(255)  NOT NULL,
    db_name        VARCHAR(128)  NOT NULL,
    db_user        VARCHAR(128)  NOT NULL,
    db_pass_enc    TEXT          NOT NULL,
    status         VARCHAR(16)   NOT NULL DEFAULT 'pending',
    schema_version DECIMAL(6,1)  NULL,
    size_mb        DECIMAL(10,2) NOT NULL DEFAULT 0,
    size_checked   DATETIME      NULL,
    user_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    last_migrated  DATETIME      NULL,
    last_error     TEXT          NULL,
    notes          VARCHAR(255)  NULL,
    created        DATETIME      NOT NULL,
    updated        DATETIME      NULL,
    PRIMARY KEY (layer, app_slug, shard_id),
    KEY idx_assign (layer, app_slug, status, size_mb)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

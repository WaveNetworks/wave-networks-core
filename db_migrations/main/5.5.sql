-- Migration 5.5 for Main Database
-- Credit/notify EVERY user a bug hit, not just the first.
--
-- error_log de-duplicates by error_hash and keeps ONE user_id per row, so an
-- error that hit 300 people knows only the first. This adds:
--   1. error_occurrence_user  — one row per (error_hash, user_id), written by
--      log_error_to_db for logged-in users, so every affected user is known.
--   2. error_log.resolution_ref — a link to the fix (commit or task id) recorded
--      when an error is resolved as 'fixed'.
--   3. error_fixed_event — a durable, privacy-safe event queue child apps drain
--      from their own cron (the resolving request is admin's and never loads a
--      child app, same model as user_deletion_event). Carries the affected user
--      ids + the DATE/PAGE only, NEVER the stack trace.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 5.5;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS error_occurrence_user (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    error_hash       CHAR(32)        NOT NULL,
    user_id          INT UNSIGNED    NOT NULL,
    first_seen_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    occurrence_count INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hash_user (error_hash, user_id),
    KEY idx_user (user_id),
    KEY idx_hash (error_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE error_log
    ADD COLUMN resolution_ref VARCHAR(255) NULL DEFAULT NULL AFTER resolution_notes;

CREATE TABLE IF NOT EXISTS error_fixed_event (
    event_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    error_id          INT UNSIGNED    NOT NULL,
    error_hash        CHAR(32)        NOT NULL,
    source_app        VARCHAR(50)     NULL,
    page              VARCHAR(100)    NULL,
    occurred_on       DATE            NULL,
    affected_user_ids MEDIUMTEXT      NULL,
    resolution_ref    VARCHAR(255)    NULL,
    resolved_at       DATETIME        NOT NULL,
    created           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    UNIQUE KEY uq_error (error_id),
    KEY idx_resolved_at (resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

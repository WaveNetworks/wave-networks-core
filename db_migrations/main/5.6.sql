-- Migration 5.6 for Main Database
-- Use-case health & ratings (API task #2252)
--
-- A "use case health" score per build from three signals already captured on
-- every app's admin: automated test status (use_case_test_run), error rate in
-- the flow (error_log + error_occurrence_user, #2249) and now per-user ratings.
--
--   1. use_case_rating        — one rating (1-5) per user per use case per build,
--                               with optional comment, build label and platform.
--   2. engagement_prompt_log  — the SHARED in-flow prompt budget (ratings AND any
--                               future survey). Enforces "at most one prompt per
--                               user per day" and "each use case at most once per
--                               build per user".
--   3. use_case health/flag   — columns on use_case recording the latest computed
--                               health and a 'needs attention' regression flag.
--   4. use_case_regression_event — a durable, idempotent queue nokemo's monitoring
--                               cron polls to file a fix task linking the use case,
--                               the build and the comments. Same poll model as
--                               error_fixed_event / user_deletion_event.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 5.6;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- 1. Per-user ratings. app_build is NOT NULL DEFAULT '' so the unique key holds
--    for web (no build label) too — MySQL treats NULLs as distinct in UNIQUE.
CREATE TABLE IF NOT EXISTS use_case_rating (
    rating_row_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    use_case_id    INT UNSIGNED    NOT NULL,
    source_app     VARCHAR(50)     NOT NULL DEFAULT '',
    user_id        INT UNSIGNED    NOT NULL,
    rating         TINYINT UNSIGNED NOT NULL,
    comment        TEXT            NULL,
    app_build      VARCHAR(50)     NOT NULL DEFAULT '',
    platform       ENUM('ios','android','web') NOT NULL DEFAULT 'web',
    created        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rating_row_id),
    UNIQUE KEY uq_user_case_build (use_case_id, user_id, app_build),
    KEY idx_case_build (use_case_id, app_build),
    KEY idx_app (source_app),
    KEY idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Shared in-flow prompt budget. prompt_kind lets a later survey system share
--    the same daily cap. ref_id is the use_case_id for rating prompts.
CREATE TABLE IF NOT EXISTS engagement_prompt_log (
    prompt_row_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED    NOT NULL,
    source_app     VARCHAR(50)     NOT NULL DEFAULT '',
    prompt_kind    VARCHAR(50)     NOT NULL DEFAULT 'use_case_rating',
    ref_id         INT UNSIGNED    NOT NULL DEFAULT 0,
    app_build      VARCHAR(50)     NOT NULL DEFAULT '',
    shown_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (prompt_row_id),
    UNIQUE KEY uq_once_per_build (user_id, prompt_kind, ref_id, app_build),
    KEY idx_user_day (user_id, shown_at),
    KEY idx_app (source_app)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Health + regression flag on the use case itself. health_json caches the
--    last computed score so the list view is cheap.
ALTER TABLE use_case
    ADD COLUMN health_status ENUM('ok','needs_attention') NOT NULL DEFAULT 'ok' AFTER test_status;
ALTER TABLE use_case
    ADD COLUMN health_json TEXT NULL DEFAULT NULL AFTER health_status;
ALTER TABLE use_case
    ADD COLUMN health_computed_at DATETIME NULL DEFAULT NULL AFTER health_json;
ALTER TABLE use_case
    ADD COLUMN flag_reason VARCHAR(255) NULL DEFAULT NULL AFTER health_computed_at;
ALTER TABLE use_case
    ADD COLUMN flag_build VARCHAR(50) NULL DEFAULT NULL AFTER flag_reason;
ALTER TABLE use_case
    ADD COLUMN flagged_at DATETIME NULL DEFAULT NULL AFTER flag_build;

-- 4. Durable regression queue nokemo polls. One row per (use_case_id, build) —
--    idempotent per build so re-computing never double-files a task.
CREATE TABLE IF NOT EXISTS use_case_regression_event (
    event_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    use_case_id    INT UNSIGNED    NOT NULL,
    source_app     VARCHAR(50)     NOT NULL DEFAULT '',
    slug           VARCHAR(100)    NOT NULL DEFAULT '',
    name           VARCHAR(255)    NULL,
    build          VARCHAR(50)     NOT NULL DEFAULT '',
    prev_build     VARCHAR(50)     NULL,
    reason         VARCHAR(255)    NOT NULL DEFAULT '',
    metric_json    TEXT            NULL,
    sample_comments MEDIUMTEXT     NULL,
    acknowledged   TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    created        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    UNIQUE KEY uq_case_build (use_case_id, build),
    KEY idx_app (source_app),
    KEY idx_ack (acknowledged, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

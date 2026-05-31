<?php
/**
 * acquisitionFunctions.php — acquisition-funnel analytics (Part A: schema + registry).
 *
 * ARCHITECTURE (decided 2026-05-31): the acquisition funnel is a NEW CONSUMER of
 * the existing user-action-log write path — NOT a parallel logging table. Funnel
 * stages ride log_user_action($stage_key, ...) as named actions, inheriting:
 *   - device_id continuity (anonymous device_action_log -> post-register
 *     user_action_log, stitched by device_id), and
 *   - is_test_account exclusion,
 * for free. See "One write path, many consumers" in admin/CLAUDE.md.
 *
 * The DURABLE layer is acquisition_funnel_daily — a kept-forever, aggregate-only,
 * GDPR-clean rollup that mirrors feature_metric_daily. Raw action-log rows expire
 * (user 24h / device 7d); the durable aggregate is populated by the nightly rollup
 * (task #804). This file only provides the schema, the per-app funnel-definition
 * registry, and the declare/get helpers. The record_acquisition_event() emit
 * wrapper and A/B (_experiments) layer arrive with task #795.
 *
 * Tables are created via ensure_acquisition_tables() in autocommit, NOT the
 * migration runner — makershost runs MariaDB and the runner's per-migration
 * transaction non-deterministically drops DDL. This mirrors ensure_media_table().
 */

/**
 * Guarantee the acquisition funnel tables exist (autocommit; runs outside the
 * migration runner so the feature works immediately regardless of migration /
 * opcache timing). Idempotent — safe to call on every request.
 *
 * NOTE on `segment`: the PRIMARY KEY includes `segment`, and MySQL/MariaDB
 * forbids NULL in a PK column. We therefore declare it NOT NULL DEFAULT ''
 * so the empty string is the canonical "no segment / all" bucket. Consumers
 * (rollup #804, dashboard #805) must use '' rather than NULL for that bucket.
 */
function ensure_acquisition_tables(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    // Durable, aggregate-only, kept-forever funnel rollup (mirrors feature_metric_daily).
    db_query(
        "CREATE TABLE IF NOT EXISTS `acquisition_funnel_daily` (
            `day`            DATE         NOT NULL,
            `source_app`     VARCHAR(50)  NOT NULL,
            `stage_key`      VARCHAR(100) NOT NULL,
            `segment`        VARCHAR(50)  NOT NULL DEFAULT '',
            `unique_devices` INT UNSIGNED NOT NULL DEFAULT 0,
            `unique_users`   INT UNSIGNED NOT NULL DEFAULT 0,
            `event_count`    INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `source_app`, `stage_key`, `segment`),
            KEY `idx_app_day` (`source_app`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Per-app funnel-definition registry: ordered stages + which stage marks an active user.
    db_query(
        "CREATE TABLE IF NOT EXISTS `acquisition_funnel_def` (
            `source_app`     VARCHAR(50)  NOT NULL,
            `stage_order`    INT UNSIGNED NOT NULL DEFAULT 0,
            `stage_key`      VARCHAR(100) NOT NULL,
            `stage_label`    VARCHAR(255) NOT NULL DEFAULT '',
            `is_active_user` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`source_app`, `stage_key`),
            KEY `idx_app_order` (`source_app`, `stage_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Upsert an app's ordered funnel stages into acquisition_funnel_def.
 *
 * @param string $source_app App slug (e.g. 'elevateher', 'nokemo').
 * @param array  $stages     Ordered list of stage definitions. Each entry:
 *                             [
 *                               'stage_key'      => string  (required; the log_user_action name),
 *                               'stage_label'    => string  (optional human label),
 *                               'is_active_user' => bool|int (optional; marks the active-user stage),
 *                             ]
 *                            Array order defines stage_order (0-based).
 * @return int Number of stages upserted.
 */
function declare_funnel(string $source_app, array $stages): int
{
    ensure_acquisition_tables();
    $source_app = trim($source_app);
    if ($source_app === '') { return 0; }

    $count = 0;
    foreach (array_values($stages) as $order => $stage) {
        $key = trim((string)($stage['stage_key'] ?? ''));
        if ($key === '') { continue; }
        $label  = (string)($stage['stage_label'] ?? '');
        $active = !empty($stage['is_active_user']) ? 1 : 0;

        $ok = db_query_prepared(
            "INSERT INTO `acquisition_funnel_def`
                (`source_app`, `stage_order`, `stage_key`, `stage_label`, `is_active_user`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `stage_order`    = VALUES(`stage_order`),
                `stage_label`    = VALUES(`stage_label`),
                `is_active_user` = VALUES(`is_active_user`)",
            [$source_app, $order, $key, $label, $active]
        );
        if ($ok !== false) { $count++; }
    }
    return $count;
}

/**
 * Return an app's funnel stages in order.
 *
 * @param string $source_app App slug.
 * @return array Ordered list of stage rows (stage_order, stage_key, stage_label,
 *               is_active_user, created), ascending by stage_order.
 */
function get_funnel_def(string $source_app): array
{
    ensure_acquisition_tables();
    $stmt = db_query_prepared(
        "SELECT `source_app`, `stage_order`, `stage_key`, `stage_label`, `is_active_user`, `created`
           FROM `acquisition_funnel_def`
          WHERE `source_app` = ?
          ORDER BY `stage_order` ASC, `stage_key` ASC",
        [trim($source_app)]
    );
    return db_fetch_all($stmt);
}

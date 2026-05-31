<?php
/**
 * costFunctions.php
 * Helper functions for cost tracking (COGS, CAC, support).
 * Available to child apps via common.php include chain.
 */

/**
 * Schema backstop: guarantee the `vendor` columns exist on cost_entry and
 * cost_recurring even when the migration runner dropped the DDL.
 *
 * makershost runs MariaDB, where the admin-core migration runner wraps each
 * migration in a transaction and DDL implicit-commits can non-deterministically
 * DROP an ALTER (migration 3.0 adds cost_entry.vendor, 4.6 adds
 * cost_recurring.vendor). When that drop happens on a deployed host, every
 * record_cost()/ensure_subscription_recurring() INSERT hits
 * "Unknown column 'vendor' in 'INSERT INTO'" and the whole TTS/cost write 500s
 * (nokemo tasks #806, #807 — observed on dswa.org/elevateher). This idempotent
 * autocommit ensure repairs the column on the fly. `ADD COLUMN IF NOT EXISTS`
 * is MariaDB-native and a cheap no-op once the column is present, so we run it
 * at most once per request behind a static guard.
 */
function ensure_cost_schema() {
    static $done = false;
    if ($done) return;
    $done = true;
    @db_query("ALTER TABLE cost_entry ADD COLUMN IF NOT EXISTS vendor VARCHAR(200) DEFAULT NULL AFTER source_app");
    @db_query("ALTER TABLE cost_recurring ADD COLUMN IF NOT EXISTS vendor VARCHAR(200) DEFAULT NULL");
}

/**
 * Record a single cost entry.
 *
 * @param string $cost_type  'cogs', 'cac', or 'support'
 * @param string $source_app App name (e.g. 'child-app', 'admin')
 * @param string $description Human-readable description
 * @param float  $amount      Cost amount
 * @param array  $opts        Optional: user_id, currency, metadata (JSON string), vendor
 * @return int|false          cost_id on success, false on failure
 */
function record_cost($cost_type, $source_app, $description, $amount, $opts = []) {
    $valid_types = ['cogs', 'cac', 'support'];
    if (!in_array($cost_type, $valid_types)) {
        return false;
    }

    ensure_cost_schema();

    $s_type        = sanitize($cost_type, SQL);
    $s_source      = sanitize($source_app, SQL);
    $s_desc        = sanitize($description, SQL);
    $s_amount      = floatval($amount);
    $s_currency    = sanitize($opts['currency'] ?? 'USD', SQL);
    $user_id       = isset($opts['user_id']) ? intval($opts['user_id']) : null;
    $metadata      = isset($opts['metadata']) ? sanitize($opts['metadata'], SQL) : null;
    $vendor        = isset($opts['vendor']) && $opts['vendor'] !== '' ? sanitize($opts['vendor'], SQL) : null;

    $user_col   = $user_id !== null ? "'$user_id'" : 'NULL';
    $meta_col   = $metadata !== null ? "'$metadata'" : 'NULL';
    $vendor_col = $vendor !== null ? "'$vendor'" : 'NULL';

    $r = db_query("INSERT INTO cost_entry (cost_type, source_app, vendor, user_id, description, amount, currency, metadata)
                    VALUES ('$s_type', '$s_source', $vendor_col, $user_col, '$s_desc', '$s_amount', '$s_currency', $meta_col)");

    if (!$r) {
        return false;
    }

    return (int) db_insert_id();
}

/**
 * Record multiple cost entries in a single batch.
 *
 * @param array $entries Array of associative arrays with keys:
 *                       cost_type, source_app, description, amount, [user_id, currency, metadata]
 * @return array ['inserted' => int, 'failed' => int]
 */
function record_cost_batch($entries) {
    $inserted = 0;
    $failed   = 0;

    foreach ($entries as $entry) {
        $result = record_cost(
            $entry['cost_type'] ?? '',
            $entry['source_app'] ?? '',
            $entry['description'] ?? '',
            $entry['amount'] ?? 0,
            [
                'user_id'  => $entry['user_id'] ?? null,
                'currency' => $entry['currency'] ?? 'USD',
                'metadata' => $entry['metadata'] ?? null,
                'vendor'   => $entry['vendor'] ?? null,
            ]
        );

        if ($result !== false) {
            $inserted++;
        } else {
            $failed++;
        }
    }

    return ['inserted' => $inserted, 'failed' => $failed];
}

/**
 * Get cost entries with filters and pagination.
 *
 * @param array $filters Optional: cost_type, source_app, vendor, user_id, from_date, to_date, page, per_page
 * @return array ['items' => array, 'total' => int, 'page' => int, 'per_page' => int]
 */
function get_cost_entries($filters = []) {
    $where = [];

    if (!empty($filters['cost_type'])) {
        $s = sanitize($filters['cost_type'], SQL);
        $where[] = "cost_type = '$s'";
    }
    if (!empty($filters['source_app'])) {
        $s = sanitize($filters['source_app'], SQL);
        $where[] = "source_app = '$s'";
    }
    if (!empty($filters['vendor'])) {
        $s = sanitize($filters['vendor'], SQL);
        $where[] = "vendor = '$s'";
    }
    if (isset($filters['user_id']) && $filters['user_id'] !== '') {
        $uid = intval($filters['user_id']);
        $where[] = "user_id = '$uid'";
    }
    if (!empty($filters['from_date'])) {
        $s = sanitize($filters['from_date'], SQL);
        $where[] = "created >= '$s'";
    }
    if (!empty($filters['to_date'])) {
        $s = sanitize($filters['to_date'], SQL);
        $where[] = "created <= '$s 23:59:59'";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $page     = max(1, intval($filters['page'] ?? 1));
    $per_page = max(1, min(100, intval($filters['per_page'] ?? 25)));
    $offset   = ($page - 1) * $per_page;

    $total = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM cost_entry $whereSQL"))['cnt'];

    $r = db_query("SELECT * FROM cost_entry $whereSQL ORDER BY created DESC LIMIT $offset, $per_page");
    $items = $r ? db_fetch_all($r) : [];

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/**
 * Get aggregated cost summary by type for a date range.
 *
 * @param array $filters Optional: cost_type, source_app, vendor, user_id, from_date, to_date
 * @return array Totals by cost type
 */
function get_cost_summary($filters = []) {
    $where = [];

    if (!empty($filters['cost_type'])) {
        $s = sanitize($filters['cost_type'], SQL);
        $where[] = "cost_type = '$s'";
    }
    if (!empty($filters['source_app'])) {
        $s = sanitize($filters['source_app'], SQL);
        $where[] = "source_app = '$s'";
    }
    if (!empty($filters['vendor'])) {
        $s = sanitize($filters['vendor'], SQL);
        $where[] = "vendor = '$s'";
    }
    if (isset($filters['user_id']) && $filters['user_id'] !== '') {
        $uid = intval($filters['user_id']);
        $where[] = "user_id = '$uid'";
    }
    if (!empty($filters['from_date'])) {
        $s = sanitize($filters['from_date'], SQL);
        $where[] = "created >= '$s'";
    }
    if (!empty($filters['to_date'])) {
        $s = sanitize($filters['to_date'], SQL);
        $where[] = "created <= '$s 23:59:59'";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $r = db_query("SELECT cost_type, SUM(amount) as total_amount, COUNT(*) as entry_count
                    FROM cost_entry $whereSQL
                    GROUP BY cost_type");

    $summary = [];
    if ($r) {
        foreach (db_fetch_all($r) as $row) {
            $summary[$row['cost_type']] = [
                'total'  => round((float) $row['total_amount'], 6),
                'count'  => (int) $row['entry_count'],
            ];
        }
    }

    return $summary;
}

/**
 * Get all recurring cost entries.
 *
 * @param bool $active_only Only return active entries
 * @return array
 */
function get_recurring_costs($active_only = false) {
    $where = $active_only ? 'WHERE is_active = 1' : '';
    $r = db_query("SELECT * FROM cost_recurring $where ORDER BY cost_type, description");
    return $r ? db_fetch_all($r) : [];
}

/**
 * Get total monthly amount for active recurring costs of a given type.
 * Normalizes all frequencies to monthly equivalent.
 *
 * @param string|null $cost_type Filter by type, or null for all
 * @return float Monthly total
 */
function get_recurring_monthly_total($cost_type = null) {
    $where = 'WHERE is_active = 1';
    if ($cost_type !== null) {
        $s = sanitize($cost_type, SQL);
        $where .= " AND cost_type = '$s'";
    }

    $r = db_query("SELECT amount, frequency FROM cost_recurring $where");
    if (!$r) return 0.0;

    $total = 0.0;
    foreach (db_fetch_all($r) as $row) {
        $amt = (float) $row['amount'];
        switch ($row['frequency']) {
            case 'daily':   $total += $amt * 30; break;
            case 'weekly':  $total += $amt * 4.33; break;
            case 'monthly': $total += $amt; break;
            case 'yearly':  $total += $amt / 12; break;
        }
    }

    return round($total, 2);
}

/**
 * Get per-user COGS total for a date range.
 *
 * @param int    $user_id
 * @param string $from Date string (Y-m-d)
 * @param string $to   Date string (Y-m-d)
 * @return float
 */
function get_user_cogs($user_id, $from = null, $to = null) {
    $uid = intval($user_id);
    $where = "WHERE cost_type = 'cogs' AND user_id = '$uid'";

    if ($from) {
        $s = sanitize($from, SQL);
        $where .= " AND created >= '$s'";
    }
    if ($to) {
        $s = sanitize($to, SQL);
        $where .= " AND created <= '$s 23:59:59'";
    }

    $row = db_fetch(db_query("SELECT COALESCE(SUM(amount), 0) as total FROM cost_entry $where"));
    return round((float) $row['total'], 6);
}

/**
 * Get total costs by type for a date range (entries only, excludes recurring).
 *
 * @param string $cost_type 'cogs', 'cac', or 'support'
 * @param string $from      Date string (Y-m-d)
 * @param string $to        Date string (Y-m-d)
 * @return float
 */
function get_total_costs_by_type($cost_type, $from = null, $to = null) {
    $s_type = sanitize($cost_type, SQL);
    $where = "WHERE cost_type = '$s_type'";

    if ($from) {
        $s = sanitize($from, SQL);
        $where .= " AND created >= '$s'";
    }
    if ($to) {
        $s = sanitize($to, SQL);
        $where .= " AND created <= '$s 23:59:59'";
    }

    $row = db_fetch(db_query("SELECT COALESCE(SUM(amount), 0) as total FROM cost_entry $where"));
    return round((float) $row['total'], 6);
}

/**
 * Get distinct source apps that have recorded costs.
 *
 * @return array List of source_app strings
 */
function get_cost_source_apps() {
    $r = db_query("SELECT DISTINCT source_app FROM cost_entry ORDER BY source_app");
    if (!$r) return [];
    $apps = [];
    foreach (db_fetch_all($r) as $row) {
        $apps[] = $row['source_app'];
    }
    return $apps;
}

/**
 * Get monthly cost trend data for charts.
 *
 * @param int    $months Number of months to look back
 * @param string $cost_type Optional filter
 * @return array
 */
function get_monthly_cost_trend($months = 12, $cost_type = null) {
    $months = intval($months);
    $where = "WHERE created >= DATE_SUB(NOW(), INTERVAL $months MONTH)";

    if ($cost_type !== null) {
        $s = sanitize($cost_type, SQL);
        $where .= " AND cost_type = '$s'";
    }

    $r = db_query("SELECT
                        DATE_FORMAT(created, '%Y-%m') as month_val,
                        cost_type,
                        SUM(amount) as total_amount,
                        COUNT(*) as entry_count
                    FROM cost_entry $where
                    GROUP BY DATE_FORMAT(created, '%Y-%m'), cost_type
                    ORDER BY month_val");

    return $r ? db_fetch_all($r) : [];
}

/**
 * Get cost breakdown by source app.
 *
 * @param string $from Date string (Y-m-d)
 * @param string $to   Date string (Y-m-d)
 * @return array
 */
function get_cost_by_source($from = null, $to = null) {
    $where = [];
    if ($from) {
        $s = sanitize($from, SQL);
        $where[] = "created >= '$s'";
    }
    if ($to) {
        $s = sanitize($to, SQL);
        $where[] = "created <= '$s 23:59:59'";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $r = db_query("SELECT source_app, cost_type, SUM(amount) as total_amount, COUNT(*) as entry_count
                    FROM cost_entry $whereSQL
                    GROUP BY source_app, cost_type
                    ORDER BY total_amount DESC");

    return $r ? db_fetch_all($r) : [];
}

/**
 * Get distinct vendors that have recorded costs.
 *
 * @return array List of vendor strings (excludes NULLs)
 */
function get_cost_vendors() {
    $r = db_query("SELECT DISTINCT vendor FROM cost_entry WHERE vendor IS NOT NULL ORDER BY vendor");
    if (!$r) return [];
    $vendors = [];
    foreach (db_fetch_all($r) as $row) {
        $vendors[] = $row['vendor'];
    }
    return $vendors;
}

/**
 * Get cost breakdown by vendor.
 *
 * @param string $from Date string (Y-m-d)
 * @param string $to   Date string (Y-m-d)
 * @return array
 */
function get_cost_by_vendor($from = null, $to = null) {
    $where = [];
    if ($from) {
        $s = sanitize($from, SQL);
        $where[] = "created >= '$s'";
    }
    if ($to) {
        $s = sanitize($to, SQL);
        $where[] = "created <= '$s 23:59:59'";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $r = db_query("SELECT COALESCE(vendor, '(no vendor)') as vendor, cost_type, SUM(amount) as total_amount, COUNT(*) as entry_count
                    FROM cost_entry $whereSQL
                    GROUP BY COALESCE(vendor, '(no vendor)'), cost_type
                    ORDER BY total_amount DESC");

    return $r ? db_fetch_all($r) : [];
}

/**
 * Record a per-event usage row for a subscription-model vendor.
 *
 * Subscription vendors (UnrealSpeech TTS, future image/video SaaS) charge a
 * flat monthly fee against a quota, NOT per event. So the cost_entry row carries
 * amount = $0 — it's an audit trail of consumed units, not a charge. The actual
 * bill lives in cost_recurring (see ensure_subscription_recurring). Pay-per-event
 * vendors (Anthropic, OpenAI, DeepSeek) keep using record_cost() with a real amount.
 *
 * @param string $vendor     e.g. 'unrealspeech'
 * @param int    $units_used chars / seconds / requests — whatever the vendor meters
 * @param string $unit_type  'chars' | 'seconds' | 'requests' | 'tokens'
 * @param array  $opts       user_id, source_app, description, plan, quota,
 *                           metadata (extra fields merged into metadata JSON)
 * @return int|false         cost_id on success, false on failure
 */
function record_subscription_usage($vendor, $units_used, $unit_type, $opts = []) {
    $meta = array_merge([
        'service'   => $vendor,
        'units'     => (int) $units_used,
        'unit_type' => $unit_type,
        'plan'      => $opts['plan']  ?? null,
        'quota'     => $opts['quota'] ?? null,
    ], $opts['metadata'] ?? []);

    return record_cost(
        'cogs',
        $opts['source_app'] ?? 'unknown',
        $opts['description'] ?? "Subscription usage: $vendor",
        0.0,                                  // amount = $0; subscription paid via cost_recurring
        [
            'user_id'  => $opts['user_id'] ?? null,
            'vendor'   => $vendor,
            'metadata' => json_encode($meta),
        ]
    );
}

/**
 * Ensure exactly one cost_recurring row exists for a subscription vendor.
 *
 * Idempotent upsert keyed on the UNIQUE (vendor, frequency) tuple (migration 4.6),
 * so callers can fire this once per request and config changes (plan/price) flow
 * straight through to the dashboard. Quota + plan details ride in metadata JSON so
 * the admin widget can render a generic per-vendor quota bar with no vendor-specific
 * code in admin core.
 *
 * @param string $vendor         e.g. 'unrealspeech'
 * @param float  $monthly_amount flat monthly fee (USD)
 * @param string $description     human-readable label (e.g. "UnrealSpeech basic plan")
 * @param array  $opts            frequency, cost_type, created_by, metadata (array)
 * @return bool
 */
function ensure_subscription_recurring($vendor, $monthly_amount, $description, $opts = []) {
    if ($vendor === null || $vendor === '') {
        return false;
    }

    ensure_cost_schema();

    $v          = sanitize($vendor, SQL);
    $amt        = number_format((float) $monthly_amount, 2, '.', '');
    $desc       = sanitize($description, SQL);
    $freq       = sanitize($opts['frequency'] ?? 'monthly', SQL);
    $ctype      = sanitize($opts['cost_type'] ?? 'cogs', SQL);
    $created_by = (int) ($opts['created_by'] ?? ($_SESSION['user_id'] ?? 0));
    $meta       = isset($opts['metadata']) ? sanitize(json_encode($opts['metadata']), SQL) : null;
    $meta_col   = $meta !== null ? "'$meta'" : 'NULL';

    return (bool) db_query(
        "INSERT INTO cost_recurring (cost_type, description, amount, frequency, vendor, metadata, is_active, created_by)
         VALUES ('$ctype', '$desc', '$amt', '$freq', '$v', $meta_col, 1, '$created_by')
         ON DUPLICATE KEY UPDATE
            amount      = VALUES(amount),
            description = VALUES(description),
            metadata    = VALUES(metadata),
            cost_type   = VALUES(cost_type),
            is_active   = 1"
    );
}

/**
 * Build the per-vendor "Subscriptions + Quota" widget data for ?page=costs.
 *
 * Generic: renders for ANY vendor that has a cost_recurring row with a non-NULL
 * vendor AND a record_subscription_usage() history this month. Units consumed are
 * summed from cost_entry.metadata (admin-local DB — the only source admin core can
 * reach; cross-app rollups live in each app's own DB). Degrades gracefully: a vendor
 * with a recurring row but no usage yet returns has_data = false so the view can show
 * "no data yet" instead of a misleading zero bar.
 *
 * @return array list of widget rows
 */
function get_subscription_usage_summary() {
    $r = db_query("SELECT vendor, amount, description, metadata
                   FROM cost_recurring
                   WHERE is_active = 1 AND vendor IS NOT NULL AND vendor <> ''
                   ORDER BY vendor");
    if (!$r) return [];

    // Month-to-date window + month math, computed once.
    $month_start = date('Y-m-01 00:00:00');
    $day_of_month = (int) date('j');
    $days_in_month = (int) date('t');

    $out = [];
    foreach (db_fetch_all($r) as $row) {
        $vendor = $row['vendor'];
        $v      = sanitize($vendor, SQL);
        $ms     = sanitize($month_start, SQL);
        $meta   = json_decode($row['metadata'] ?? '', true) ?: [];

        $unit_type   = $meta['unit_type']  ?? 'units';
        $quota       = (int) ($meta['quota']      ?? 0);   // 0 = unlimited
        $hour_quota  = (int) ($meta['hour_quota'] ?? 0);
        $warn_pct    = (int) ($meta['warn_pct']   ?? 80);
        $cps         = max(1, (int) ($meta['chars_per_second'] ?? 14));
        $plan_label  = $meta['plan'] ?? '';

        $usage = db_fetch(db_query(
            "SELECT COALESCE(SUM(CAST(JSON_EXTRACT(metadata, '$.units') AS UNSIGNED)), 0) AS units,
                    COUNT(DISTINCT DATE(created)) AS active_days
             FROM cost_entry
             WHERE vendor = '$v' AND created >= '$ms'"
        ));

        $units   = (int) ($usage['units'] ?? 0);
        $active  = (int) ($usage['active_days'] ?? 0);
        $has_data = $units > 0;

        $pct = ($quota > 0) ? round($units / $quota * 100, 1) : 0;
        // Forecast month-end by linear run-rate on elapsed days.
        $forecast = ($day_of_month > 0) ? (int) round($units / $day_of_month * $days_in_month) : $units;
        $forecast_pct = ($quota > 0) ? round($forecast / $quota * 100, 1) : 0;
        $avg_per_day  = ($active > 0) ? (int) round($units / $active) : 0;

        // Hours estimate (chars → seconds → hours), only meaningful for char metering.
        $hours_est  = ($unit_type === 'chars') ? round($units / $cps / 3600, 1) : null;

        $out[] = [
            'vendor'       => $vendor,
            'label'        => $row['description'] ?: $vendor,
            'plan_label'   => $plan_label,
            'monthly_cost' => (float) $row['amount'],
            'unit_type'    => $unit_type,
            'units_used'   => $units,
            'quota'        => $quota,
            'pct'          => $pct,
            'warn_pct'     => $warn_pct,
            'over_warn'    => $quota > 0 && $pct >= $warn_pct,
            'over_quota'   => $quota > 0 && $pct >= 100,
            'hours_est'    => $hours_est,
            'hour_quota'   => $hour_quota,
            'over_hours'   => $hour_quota > 0 && $hours_est !== null && $hours_est > $hour_quota,
            'active_days'  => $active,
            'avg_per_day'  => $avg_per_day,
            'forecast'     => $forecast,
            'forecast_pct' => $forecast_pct,
            'has_data'     => $has_data,
        ];
    }

    return $out;
}

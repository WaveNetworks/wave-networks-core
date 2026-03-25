<?php
/**
 * costFunctions.php
 * Helper functions for cost tracking (COGS, CAC, support).
 * Available to child apps via common.php include chain.
 */

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

<?php
/**
 * Report Actions
 * Actions: getReportData
 */

if (($_POST['action'] ?? '') == 'getReportData') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $report = $_POST['report'] ?? '';
    $range  = intval($_POST['range'] ?? 90);

    if (!in_array($report, ['overview', 'acquisition', 'retention', 'churn', 'forecast'])) {
        $errs['report'] = 'Invalid report type.';
    }
    if ($range < 7 || $range > 730) {
        $errs['range'] = 'Range must be between 7 and 730 days.';
    }

    if (count($errs) <= 0) {
        global $db;
        $rangeSQL = sanitize($range, 'SQL');

        if ($report === 'overview') {
            // Total users
            $total = db_fetch(db_query("SELECT COUNT(*) as cnt FROM user"))['cnt'];

            // Active users (last 30 days)
            $active30 = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active = 1"
            ))['cnt'];

            // New users (last 30 days)
            $new30 = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ))['cnt'];

            // Deactivated users (last 30 days)
            $deactivated30 = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE deactivated_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ))['cnt'];

            // Confirmation rate
            $confirmed = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE is_confirmed = 1"
            ))['cnt'];
            $confirmRate = $total > 0 ? round(($confirmed / $total) * 100, 1) : 0;

            // Status breakdown
            $activeTotal = db_fetch(db_query("SELECT COUNT(*) as cnt FROM user WHERE is_active = 1"))['cnt'];
            $inactiveTotal = db_fetch(db_query("SELECT COUNT(*) as cnt FROM user WHERE is_active = 0"))['cnt'];
            $unconfirmed = db_fetch(db_query("SELECT COUNT(*) as cnt FROM user WHERE is_confirmed = 0 AND is_active = 1"))['cnt'];

            // Daily trend: new signups vs deactivations over range
            $trend = db_fetch_all(db_query(
                "SELECT DATE(created_date) as date_val, COUNT(*) as new_users
                 FROM user
                 WHERE created_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                 GROUP BY DATE(created_date)
                 ORDER BY date_val"
            ));

            $churnTrend = db_fetch_all(db_query(
                "SELECT DATE(deactivated_date) as date_val, COUNT(*) as deactivated
                 FROM user
                 WHERE deactivated_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                   AND deactivated_date IS NOT NULL
                 GROUP BY DATE(deactivated_date)
                 ORDER BY date_val"
            ));

            $data['summary'] = [
                'total'          => (int) $total,
                'active_30d'     => (int) $active30,
                'new_30d'        => (int) $new30,
                'deactivated_30d'=> (int) $deactivated30,
                'confirm_rate'   => $confirmRate,
            ];
            $data['status_breakdown'] = [
                ['label' => 'Active',      'value' => (int) $activeTotal],
                ['label' => 'Inactive',    'value' => (int) $inactiveTotal],
                ['label' => 'Unconfirmed', 'value' => (int) $unconfirmed],
            ];
            $data['signups_trend']  = $trend;
            $data['churn_trend']    = $churnTrend;
        }

        if ($report === 'acquisition') {
            $rows = db_fetch_all(db_query(
                "SELECT
                    DATE(created_date) as date_val,
                    COUNT(*) as total,
                    SUM(CASE WHEN is_confirmed = 1 THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN is_confirmed = 0 THEN 1 ELSE 0 END) as unconfirmed
                 FROM user
                 WHERE created_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                 GROUP BY DATE(created_date)
                 ORDER BY date_val"
            ));

            // Monthly summary
            $monthly = db_fetch_all(db_query(
                "SELECT
                    DATE_FORMAT(created_date, '%Y-%m') as month_val,
                    COUNT(*) as total,
                    SUM(CASE WHEN is_confirmed = 1 THEN 1 ELSE 0 END) as confirmed
                 FROM user
                 WHERE created_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                 GROUP BY DATE_FORMAT(created_date, '%Y-%m')
                 ORDER BY month_val"
            ));

            $data['rows']    = $rows;
            $data['monthly'] = $monthly;
        }

        if ($report === 'retention') {
            // Monthly active users (MAU) over range
            $mau = db_fetch_all(db_query(
                "SELECT
                    DATE_FORMAT(last_login, '%Y-%m') as month_val,
                    COUNT(DISTINCT user_id) as active_users
                 FROM user
                 WHERE last_login >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                   AND last_login IS NOT NULL
                   AND is_active = 1
                 GROUP BY DATE_FORMAT(last_login, '%Y-%m')
                 ORDER BY month_val"
            ));

            // Recency buckets
            $today = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE DATE(last_login) = CURDATE() AND is_active = 1"
            ))['cnt'];
            $week = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND last_login < CURDATE() AND is_active = 1"
            ))['cnt'];
            $month = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND last_login < DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_active = 1"
            ))['cnt'];
            $quarter = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE last_login >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND last_login < DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active = 1"
            ))['cnt'];
            $older = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE last_login < DATE_SUB(NOW(), INTERVAL 90 DAY) AND last_login IS NOT NULL AND is_active = 1"
            ))['cnt'];
            $never = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE last_login IS NULL AND is_active = 1"
            ))['cnt'];

            $totalActive = db_fetch(db_query("SELECT COUNT(*) as cnt FROM user WHERE is_active = 1"))['cnt'];
            $active30 = db_fetch(db_query(
                "SELECT COUNT(*) as cnt FROM user WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active = 1"
            ))['cnt'];

            $data['mau'] = $mau;
            $data['recency_buckets'] = [
                ['label' => 'Today',   'value' => (int) $today],
                ['label' => '7 days',  'value' => (int) $week],
                ['label' => '30 days', 'value' => (int) $month],
                ['label' => '90 days', 'value' => (int) $quarter],
                ['label' => '90d+',    'value' => (int) $older],
                ['label' => 'Never',   'value' => (int) $never],
            ];
            $data['active_rate'] = $totalActive > 0 ? round(($active30 / $totalActive) * 100, 1) : 0;
        }

        if ($report === 'churn') {
            $rows = db_fetch_all(db_query(
                "SELECT DATE(deactivated_date) as date_val, COUNT(*) as deactivated
                 FROM user
                 WHERE deactivated_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                   AND deactivated_date IS NOT NULL
                 GROUP BY DATE(deactivated_date)
                 ORDER BY date_val"
            ));
            $data['rows'] = $rows;
        }

        if ($report === 'forecast') {
            // Current totals
            $totalUsers = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM user"))['cnt'];
            $totalActive = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM user WHERE is_active = 1"))['cnt'];
            $totalDeactivated = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM user WHERE is_active = 0"))['cnt'];

            // Monthly signups over range
            $monthlySignups = db_fetch_all(db_query(
                "SELECT
                    DATE_FORMAT(created_date, '%Y-%m') as month_val,
                    COUNT(*) as signups
                 FROM user
                 WHERE created_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                 GROUP BY DATE_FORMAT(created_date, '%Y-%m')
                 ORDER BY month_val"
            ));

            // Monthly cancellations over range
            $monthlyCancellations = db_fetch_all(db_query(
                "SELECT
                    DATE_FORMAT(deactivated_date, '%Y-%m') as month_val,
                    COUNT(*) as cancellations
                 FROM user
                 WHERE deactivated_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                   AND deactivated_date IS NOT NULL
                 GROUP BY DATE_FORMAT(deactivated_date, '%Y-%m')
                 ORDER BY month_val"
            ));

            // Cumulative user count at end of each month (running total)
            $cumulativeByMonth = db_fetch_all(db_query(
                "SELECT
                    m.month_val,
                    (SELECT COUNT(*) FROM user WHERE DATE_FORMAT(created_date, '%Y-%m') <= m.month_val) as cumulative_users
                 FROM (
                    SELECT DISTINCT DATE_FORMAT(created_date, '%Y-%m') as month_val
                    FROM user
                    WHERE created_date >= DATE_SUB(NOW(), INTERVAL {$rangeSQL} DAY)
                 ) m
                 ORDER BY m.month_val"
            ));

            $data['totals'] = [
                'total_users'       => $totalUsers,
                'total_active'      => $totalActive,
                'total_deactivated' => $totalDeactivated,
            ];
            $data['monthly_signups']       = $monthlySignups;
            $data['monthly_cancellations'] = $monthlyCancellations;
            $data['cumulative_by_month']   = $cumulativeByMonth;
        }

        $_SESSION['success'] = 'Report data loaded.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

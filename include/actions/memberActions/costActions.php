<?php
/**
 * Cost Actions (Admin UI)
 * Actions: getCostData, getCostReport, addRecurringCost, updateRecurringCost,
 *          deleteRecurringCost, toggleRecurringCost
 */

// ── Get cost entries (paginated, filtered) ──────────────────
if (($action ?? null) == 'getCostData') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $filters = [];
        if (!empty($_POST['cost_type']))  $filters['cost_type']  = $_POST['cost_type'];
        if (!empty($_POST['source_app'])) $filters['source_app'] = $_POST['source_app'];
        if (!empty($_POST['vendor']))     $filters['vendor']     = $_POST['vendor'];
        if (isset($_POST['user_id_filter']) && $_POST['user_id_filter'] !== '') {
            $filters['user_id'] = $_POST['user_id_filter'];
        }
        if (!empty($_POST['from_date'])) $filters['from_date'] = $_POST['from_date'];
        if (!empty($_POST['to_date']))   $filters['to_date']   = $_POST['to_date'];
        if (!empty($_POST['page']))      $filters['page']      = $_POST['page'];
        if (!empty($_POST['per_page']))  $filters['per_page']  = $_POST['per_page'];

        $result = get_cost_entries($filters);

        $data['items']    = $result['items'];
        $data['total']    = $result['total'];
        $data['page']     = $result['page'];
        $data['per_page'] = $result['per_page'];
        $data['source_apps'] = get_cost_source_apps();
        $data['vendors']     = get_cost_vendors();

        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get cost report data for charts ─────────────────────────
if (($action ?? null) == 'getCostReport') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $months = max(1, min(24, intval($_POST['months'] ?? 12)));

        // Summary for last 30 days
        $from30 = date('Y-m-d', strtotime('-30 days'));
        $to30   = date('Y-m-d');

        $data['summary_30d'] = get_cost_summary(['from_date' => $from30, 'to_date' => $to30]);
        $data['recurring_monthly'] = [
            'cogs'    => get_recurring_monthly_total('cogs'),
            'cac'     => get_recurring_monthly_total('cac'),
            'support' => get_recurring_monthly_total('support'),
            'total'   => get_recurring_monthly_total(),
        ];

        // Total active users for cost-per-user calc
        $totalUsers = (int) db_fetch(db_query(
            "SELECT COUNT(*) as cnt FROM user WHERE is_active = 1"
        ))['cnt'];
        $data['total_active_users'] = $totalUsers;

        // Monthly trend
        $data['monthly_trend'] = get_monthly_cost_trend($months);

        // Breakdown by source app (last 30 days)
        $data['by_source'] = get_cost_by_source($from30, $to30);

        // Breakdown by vendor (last 30 days)
        $data['by_vendor'] = get_cost_by_vendor($from30, $to30);

        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Add recurring cost ──────────────────────────────────────
if (($action ?? null) == 'addRecurringCost') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $cost_type   = trim($_POST['cost_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount      = $_POST['amount'] ?? '';
    $currency    = trim($_POST['currency'] ?? 'USD');
    $frequency   = trim($_POST['frequency'] ?? 'monthly');

    if (!in_array($cost_type, ['cogs', 'cac', 'support'])) {
        $errs['cost_type'] = 'Valid cost type required.';
    }
    if ($description === '') {
        $errs['description'] = 'Description required.';
    }
    if (!is_numeric($amount) || floatval($amount) <= 0) {
        $errs['amount'] = 'Amount must be a positive number.';
    }
    if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'])) {
        $errs['frequency'] = 'Valid frequency required.';
    }

    if (count($errs) <= 0) {
        $s_type   = sanitize($cost_type, SQL);
        $s_desc   = sanitize($description, SQL);
        $s_amount = floatval($amount);
        $s_curr   = sanitize($currency, SQL);
        $s_freq   = sanitize($frequency, SQL);
        $uid      = (int) $_SESSION['user_id'];

        $r = db_query("INSERT INTO cost_recurring (cost_type, description, amount, currency, frequency, created_by)
                        VALUES ('$s_type', '$s_desc', '$s_amount', '$s_curr', '$s_freq', '$uid')");

        if ($r) {
            $data['recurring_id'] = (int) db_insert_id();
            $_SESSION['success'] = 'Recurring cost added.';
        } else {
            $_SESSION['error'] = 'Failed to add recurring cost: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Update recurring cost ───────────────────────────────────
if (($action ?? null) == 'updateRecurringCost') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $recurring_id = intval($_POST['recurring_id'] ?? 0);
    $cost_type    = trim($_POST['cost_type'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $amount       = $_POST['amount'] ?? '';
    $currency     = trim($_POST['currency'] ?? 'USD');
    $frequency    = trim($_POST['frequency'] ?? 'monthly');

    if ($recurring_id <= 0) { $errs['id'] = 'Invalid recurring cost ID.'; }
    if (!in_array($cost_type, ['cogs', 'cac', 'support'])) {
        $errs['cost_type'] = 'Valid cost type required.';
    }
    if ($description === '') { $errs['description'] = 'Description required.'; }
    if (!is_numeric($amount) || floatval($amount) <= 0) {
        $errs['amount'] = 'Amount must be a positive number.';
    }
    if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'])) {
        $errs['frequency'] = 'Valid frequency required.';
    }

    if (count($errs) <= 0) {
        $s_type   = sanitize($cost_type, SQL);
        $s_desc   = sanitize($description, SQL);
        $s_amount = floatval($amount);
        $s_curr   = sanitize($currency, SQL);
        $s_freq   = sanitize($frequency, SQL);

        $r = db_query("UPDATE cost_recurring
                        SET cost_type = '$s_type', description = '$s_desc', amount = '$s_amount',
                            currency = '$s_curr', frequency = '$s_freq'
                        WHERE recurring_id = '$recurring_id'");

        if ($r) {
            $_SESSION['success'] = 'Recurring cost updated.';
        } else {
            $_SESSION['error'] = 'Failed to update recurring cost: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Delete recurring cost ───────────────────────────────────
if (($action ?? null) == 'deleteRecurringCost') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $recurring_id = intval($_POST['recurring_id'] ?? 0);
    if ($recurring_id <= 0) { $errs['id'] = 'Invalid recurring cost ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("DELETE FROM cost_recurring WHERE recurring_id = '$recurring_id'");
        if ($r) {
            $_SESSION['success'] = 'Recurring cost deleted.';
        } else {
            $_SESSION['error'] = 'Failed to delete recurring cost: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Toggle recurring cost active/inactive ───────────────────
if (($action ?? null) == 'toggleRecurringCost') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $recurring_id = intval($_POST['recurring_id'] ?? 0);
    if ($recurring_id <= 0) { $errs['id'] = 'Invalid recurring cost ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("UPDATE cost_recurring SET is_active = NOT is_active WHERE recurring_id = '$recurring_id'");
        if ($r) {
            $_SESSION['success'] = 'Recurring cost toggled.';
        } else {
            $_SESSION['error'] = 'Failed to toggle recurring cost: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

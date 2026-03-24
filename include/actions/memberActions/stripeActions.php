<?php
/**
 * Stripe Actions (Admin UI)
 * Actions: getStripeTransactions, getStripeStats, processRefund, stripeLookup,
 *          getStripeLtv, getStripeRefunds, getRevenueChart
 */

// ── Get transactions (paginated, filtered) ────────────────────
if (($action ?? null) == 'getStripeTransactions') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $filters = [];
        if (!empty($_POST['status']))              $filters['status']              = $_POST['status'];
        if (!empty($_POST['source_app']))           $filters['source_app']          = $_POST['source_app'];
        if (isset($_POST['user_id_filter']) && $_POST['user_id_filter'] !== '') {
            $filters['user_id'] = $_POST['user_id_filter'];
        }
        if (!empty($_POST['stripe_customer_id']))   $filters['stripe_customer_id']  = $_POST['stripe_customer_id'];
        if (!empty($_POST['from_date']))            $filters['from_date']           = $_POST['from_date'];
        if (!empty($_POST['to_date']))              $filters['to_date']             = $_POST['to_date'];
        if (!empty($_POST['search']))               $filters['search']              = $_POST['search'];
        if (!empty($_POST['page']))                 $filters['page']                = $_POST['page'];
        if (!empty($_POST['per_page']))             $filters['per_page']            = $_POST['per_page'];

        $result = get_stripe_transactions($filters);

        $data['items']       = $result['items'];
        $data['total']       = $result['total'];
        $data['page']        = $result['page'];
        $data['per_page']    = $result['per_page'];
        $data['source_apps'] = get_stripe_source_apps();

        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get revenue stats for dashboard cards ─────────────────────
if (($action ?? null) == 'getStripeStats') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $data['stats'] = get_revenue_stats();
        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get revenue chart data ────────────────────────────────────
if (($action ?? null) == 'getRevenueChart') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $months = max(1, min(24, intval($_POST['months'] ?? 12)));
        $data['monthly_trend'] = get_monthly_revenue_trend($months);
        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Process refund via Stripe API ─────────────────────────────
if (($action ?? null) == 'processRefund') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    $refund_amount  = $_POST['refund_amount'] ?? null;
    $reason         = trim($_POST['reason'] ?? 'requested_by_customer');

    if ($transaction_id <= 0) {
        $errs['transaction_id'] = 'Valid transaction ID required.';
    }
    if ($refund_amount !== null && $refund_amount !== '' && (!is_numeric($refund_amount) || floatval($refund_amount) <= 0)) {
        $errs['amount'] = 'Refund amount must be a positive number.';
    }
    if (!in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'])) {
        $errs['reason'] = 'Valid reason required.';
    }

    if (count($errs) <= 0) {
        $amt = ($refund_amount !== null && $refund_amount !== '') ? floatval($refund_amount) : null;
        $result = process_stripe_refund($transaction_id, $amt, $reason, $_SESSION['user_id']);

        if ($result['success']) {
            $data['refund_id'] = $result['refund_id'];
            $_SESSION['success'] = 'Refund processed successfully.';
        } else {
            $_SESSION['error'] = $result['error'];
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get refunds (paginated, filtered) ─────────────────────────
if (($action ?? null) == 'getStripeRefunds') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $filters = [];
        if (isset($_POST['transaction_id']) && $_POST['transaction_id'] !== '') {
            $filters['transaction_id'] = $_POST['transaction_id'];
        }
        if (isset($_POST['user_id_filter']) && $_POST['user_id_filter'] !== '') {
            $filters['user_id'] = $_POST['user_id_filter'];
        }
        if (!empty($_POST['status']))     $filters['status']    = $_POST['status'];
        if (!empty($_POST['from_date']))  $filters['from_date'] = $_POST['from_date'];
        if (!empty($_POST['to_date']))    $filters['to_date']   = $_POST['to_date'];
        if (!empty($_POST['page']))       $filters['page']      = $_POST['page'];
        if (!empty($_POST['per_page']))   $filters['per_page']  = $_POST['per_page'];

        $result = get_stripe_refunds($filters);

        $data['items']    = $result['items'];
        $data['total']    = $result['total'];
        $data['page']     = $result['page'];
        $data['per_page'] = $result['per_page'];

        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get LTV leaderboard ──────────────────────────────────────
if (($action ?? null) == 'getStripeLtv') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $limit = max(1, min(200, intval($_POST['limit'] ?? 50)));
        $data['leaderboard'] = get_ltv_leaderboard($limit);

        // Calculate LTV for each user
        foreach ($data['leaderboard'] as &$row) {
            $ltv = calculate_user_ltv($row['user_id']);
            $row['monthly_avg']   = $ltv['monthly_avg'];
            $row['months_active'] = $ltv['months_active'];
        }
        unset($row);

        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Stripe API Lookup (troubleshooting) ──────────────────────
if (($action ?? null) == 'stripeLookup') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $lookup_type = trim($_POST['lookup_type'] ?? '');
    $lookup_id   = trim($_POST['lookup_id'] ?? '');

    if (!in_array($lookup_type, ['payment_intent', 'customer', 'charge'])) {
        $errs['type'] = 'Valid lookup type required (payment_intent, customer, charge).';
    }
    if ($lookup_id === '') {
        $errs['id'] = 'Stripe ID required.';
    }

    if (count($errs) <= 0) {
        switch ($lookup_type) {
            case 'payment_intent':
                $result = stripe_lookup_payment($lookup_id);
                break;
            case 'customer':
                $result = stripe_lookup_customer($lookup_id);
                break;
            case 'charge':
                $result = stripe_lookup_charge($lookup_id);
                break;
        }

        if ($result['success']) {
            $data['stripe_data'] = $result['data'];
            $_SESSION['success'] = 'Stripe data retrieved.';
        } else {
            $_SESSION['error'] = $result['error'];
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get single transaction detail ─────────────────────────────
if (($action ?? null) == 'getStripeTransaction') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    if ($transaction_id <= 0) { $errs['id'] = 'Valid transaction ID required.'; }

    if (count($errs) <= 0) {
        $txn = get_stripe_transaction($transaction_id);
        if ($txn) {
            $data['transaction']    = $txn;
            $data['refund_total']   = get_transaction_refund_total($transaction_id);
            $data['refunds']        = get_stripe_refunds(['transaction_id' => $transaction_id])['items'];
            $data['user_ltv']       = $txn['user_id'] ? calculate_user_ltv($txn['user_id']) : null;
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = 'Transaction not found.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

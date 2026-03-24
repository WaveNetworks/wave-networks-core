<?php
/**
 * stripeFunctions.php
 * Stripe payment helpers: record transactions, calculate LTV, process refunds, troubleshoot.
 * Available to child apps via common.php include chain.
 */

// ═══════════════════════════════════════════════════════════════════════════
// STRIPE API INITIALIZATION
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Get a configured Stripe client instance.
 *
 * @return \Stripe\StripeClient|null
 */
function get_stripe_client() {
    global $stripe_secret_key;
    if (empty($stripe_secret_key)) return null;
    return new \Stripe\StripeClient($stripe_secret_key);
}

/**
 * Check if Stripe is configured.
 *
 * @return bool
 */
function is_stripe_configured() {
    global $stripe_secret_key;
    return !empty($stripe_secret_key);
}

// ═══════════════════════════════════════════════════════════════════════════
// TRANSACTION RECORDING
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Record a Stripe transaction (payment) in the local database.
 * Call this from child apps after a successful Stripe charge/payment intent.
 *
 * @param array $data {
 *     @type string $stripe_payment_id     Stripe payment intent or charge ID
 *     @type string $stripe_customer_id    Stripe customer ID
 *     @type string $stripe_invoice_id     Stripe invoice ID
 *     @type string $stripe_subscription_id Stripe subscription ID
 *     @type int    $user_id               Local user ID
 *     @type string $source_app            Which app recorded the transaction
 *     @type float  $amount                Amount in major currency units (e.g. dollars)
 *     @type string $currency              Three-letter currency code
 *     @type string $status                Payment status
 *     @type string $description           Human-readable description
 *     @type string $payment_method        e.g. 'card', 'bank_transfer'
 *     @type string $metadata              JSON string of extra data
 *     @type string $stripe_created        Timestamp from Stripe
 * }
 * @return int|false transaction_id on success
 */
function record_stripe_transaction($data) {
    $s_payment_id  = sanitize($data['stripe_payment_id'] ?? '', SQL);
    $s_customer_id = sanitize($data['stripe_customer_id'] ?? '', SQL);
    $s_invoice_id  = sanitize($data['stripe_invoice_id'] ?? '', SQL);
    $s_sub_id      = sanitize($data['stripe_subscription_id'] ?? '', SQL);
    $user_id       = isset($data['user_id']) ? intval($data['user_id']) : null;
    $s_source      = sanitize($data['source_app'] ?? 'admin', SQL);
    $s_amount      = floatval($data['amount'] ?? 0);
    $s_currency    = sanitize($data['currency'] ?? 'usd', SQL);
    $s_status      = sanitize($data['status'] ?? 'succeeded', SQL);
    $s_desc        = sanitize($data['description'] ?? '', SQL);
    $s_method      = sanitize($data['payment_method'] ?? '', SQL);
    $s_metadata    = isset($data['metadata']) ? sanitize($data['metadata'], SQL) : null;
    $s_stripe_date = !empty($data['stripe_created']) ? "'" . sanitize($data['stripe_created'], SQL) . "'" : 'NULL';

    $user_col = $user_id !== null ? "'$user_id'" : 'NULL';
    $meta_col = $s_metadata !== null ? "'$s_metadata'" : 'NULL';

    $valid_statuses = ['succeeded', 'pending', 'failed', 'canceled', 'refunded', 'partially_refunded'];
    if (!in_array($s_status, $valid_statuses)) {
        $s_status = 'pending';
    }

    $r = db_query("INSERT INTO stripe_transaction
        (stripe_payment_id, stripe_customer_id, stripe_invoice_id, stripe_subscription_id,
         user_id, source_app, amount, currency, status, description, payment_method, metadata, stripe_created)
        VALUES ('$s_payment_id', '$s_customer_id', '$s_invoice_id', '$s_sub_id',
                $user_col, '$s_source', '$s_amount', '$s_currency', '$s_status',
                '$s_desc', '$s_method', $meta_col, $s_stripe_date)");

    if (!$r) return false;

    $transaction_id = (int) db_insert_id();

    // Update revenue summary if payment succeeded
    if ($s_status === 'succeeded' && $user_id !== null) {
        refresh_user_revenue($user_id);
    }

    return $transaction_id;
}

/**
 * Update transaction status.
 *
 * @param int    $transaction_id
 * @param string $status
 * @return bool
 */
function update_transaction_status($transaction_id, $status) {
    $tid = intval($transaction_id);
    $s_status = sanitize($status, SQL);

    $r = db_query("UPDATE stripe_transaction SET status = '$s_status' WHERE transaction_id = '$tid'");
    if (!$r) return false;

    // Refresh revenue for the associated user
    $row = db_fetch(db_query("SELECT user_id FROM stripe_transaction WHERE transaction_id = '$tid'"));
    if ($row && $row['user_id']) {
        refresh_user_revenue($row['user_id']);
    }

    return true;
}

// ═══════════════════════════════════════════════════════════════════════════
// TRANSACTION QUERIES
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Get transactions with filters and pagination.
 *
 * @param array $filters Optional: user_id, source_app, status, stripe_customer_id,
 *                       from_date, to_date, search, page, per_page
 * @return array ['items' => array, 'total' => int, 'page' => int, 'per_page' => int]
 */
function get_stripe_transactions($filters = []) {
    $where = [];

    if (isset($filters['user_id']) && $filters['user_id'] !== '') {
        $uid = intval($filters['user_id']);
        $where[] = "t.user_id = '$uid'";
    }
    if (!empty($filters['source_app'])) {
        $s = sanitize($filters['source_app'], SQL);
        $where[] = "t.source_app = '$s'";
    }
    if (!empty($filters['status'])) {
        $s = sanitize($filters['status'], SQL);
        $where[] = "t.status = '$s'";
    }
    if (!empty($filters['stripe_customer_id'])) {
        $s = sanitize($filters['stripe_customer_id'], SQL);
        $where[] = "t.stripe_customer_id = '$s'";
    }
    if (!empty($filters['from_date'])) {
        $s = sanitize($filters['from_date'], SQL);
        $where[] = "t.created >= '$s'";
    }
    if (!empty($filters['to_date'])) {
        $s = sanitize($filters['to_date'], SQL);
        $where[] = "t.created <= '$s 23:59:59'";
    }
    if (!empty($filters['search'])) {
        $s = sanitize($filters['search'], SQL);
        $where[] = "(t.stripe_payment_id LIKE '%$s%' OR t.stripe_customer_id LIKE '%$s%'
                     OR t.description LIKE '%$s%' OR t.stripe_invoice_id LIKE '%$s%')";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $page     = max(1, intval($filters['page'] ?? 1));
    $per_page = max(1, min(100, intval($filters['per_page'] ?? 25)));
    $offset   = ($page - 1) * $per_page;

    $total = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM stripe_transaction t $whereSQL"))['cnt'];

    $r = db_query("SELECT t.*, u.email as user_email
                    FROM stripe_transaction t
                    LEFT JOIN user u ON u.user_id = t.user_id
                    $whereSQL
                    ORDER BY t.created DESC
                    LIMIT $offset, $per_page");
    $items = $r ? db_fetch_all($r) : [];

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

/**
 * Get a single transaction by ID.
 *
 * @param int $transaction_id
 * @return array|false
 */
function get_stripe_transaction($transaction_id) {
    $tid = intval($transaction_id);
    $r = db_query("SELECT t.*, u.email as user_email
                    FROM stripe_transaction t
                    LEFT JOIN user u ON u.user_id = t.user_id
                    WHERE t.transaction_id = '$tid'");
    return $r ? db_fetch($r) : false;
}

/**
 * Look up a transaction by Stripe payment ID.
 *
 * @param string $stripe_payment_id
 * @return array|false
 */
function get_transaction_by_stripe_id($stripe_payment_id) {
    $s = sanitize($stripe_payment_id, SQL);
    $r = db_query("SELECT t.*, u.email as user_email
                    FROM stripe_transaction t
                    LEFT JOIN user u ON u.user_id = t.user_id
                    WHERE t.stripe_payment_id = '$s'");
    return $r ? db_fetch($r) : false;
}

// ═══════════════════════════════════════════════════════════════════════════
// REFUNDS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Record a refund in the local database.
 *
 * @param array $data {
 *     @type int    $transaction_id   Local transaction ID
 *     @type string $stripe_refund_id Stripe refund ID
 *     @type string $stripe_payment_id Stripe payment ID
 *     @type int    $user_id
 *     @type float  $amount           Refund amount
 *     @type string $currency
 *     @type string $reason           Refund reason
 *     @type string $status
 *     @type int    $refunded_by      Admin user_id who processed refund
 *     @type string $metadata         JSON string
 * }
 * @return int|false refund_id on success
 */
function record_stripe_refund($data) {
    $transaction_id  = intval($data['transaction_id'] ?? 0);
    $s_refund_id     = sanitize($data['stripe_refund_id'] ?? '', SQL);
    $s_payment_id    = sanitize($data['stripe_payment_id'] ?? '', SQL);
    $user_id         = isset($data['user_id']) ? intval($data['user_id']) : null;
    $s_amount        = floatval($data['amount'] ?? 0);
    $s_currency      = sanitize($data['currency'] ?? 'usd', SQL);
    $s_reason        = sanitize($data['reason'] ?? '', SQL);
    $s_status        = sanitize($data['status'] ?? 'succeeded', SQL);
    $refunded_by     = isset($data['refunded_by']) ? intval($data['refunded_by']) : null;
    $s_metadata      = isset($data['metadata']) ? sanitize($data['metadata'], SQL) : null;

    $user_col     = $user_id !== null ? "'$user_id'" : 'NULL';
    $refby_col    = $refunded_by !== null ? "'$refunded_by'" : 'NULL';
    $meta_col     = $s_metadata !== null ? "'$s_metadata'" : 'NULL';

    $r = db_query("INSERT INTO stripe_refund
        (stripe_refund_id, transaction_id, stripe_payment_id, user_id, amount, currency,
         reason, status, refunded_by, metadata)
        VALUES ('$s_refund_id', '$transaction_id', '$s_payment_id', $user_col, '$s_amount',
                '$s_currency', '$s_reason', '$s_status', $refby_col, $meta_col)");

    if (!$r) return false;

    $refund_id = (int) db_insert_id();

    // Update parent transaction status
    if ($s_status === 'succeeded') {
        $txn = get_stripe_transaction($transaction_id);
        if ($txn) {
            $total_refunded = get_transaction_refund_total($transaction_id);
            if ($total_refunded >= (float) $txn['amount']) {
                update_transaction_status($transaction_id, 'refunded');
            } else {
                update_transaction_status($transaction_id, 'partially_refunded');
            }
        }
    }

    // Refresh revenue
    if ($user_id !== null) {
        refresh_user_revenue($user_id);
    }

    return $refund_id;
}

/**
 * Process a refund through Stripe API and record it locally.
 *
 * @param int    $transaction_id  Local transaction ID
 * @param float  $amount          Refund amount (null = full refund)
 * @param string $reason          Reason: 'duplicate', 'fraudulent', 'requested_by_customer'
 * @param int    $admin_user_id   Admin who initiated the refund
 * @return array ['success' => bool, 'refund_id' => int|null, 'error' => string|null]
 */
function process_stripe_refund($transaction_id, $amount = null, $reason = 'requested_by_customer', $admin_user_id = null) {
    $txn = get_stripe_transaction($transaction_id);
    if (!$txn) {
        return ['success' => false, 'refund_id' => null, 'error' => 'Transaction not found.'];
    }

    if (!in_array($txn['status'], ['succeeded', 'partially_refunded'])) {
        return ['success' => false, 'refund_id' => null, 'error' => 'Transaction cannot be refunded (status: ' . $txn['status'] . ').'];
    }

    $stripe = get_stripe_client();
    if (!$stripe) {
        return ['success' => false, 'refund_id' => null, 'error' => 'Stripe is not configured.'];
    }

    if (empty($txn['stripe_payment_id'])) {
        return ['success' => false, 'refund_id' => null, 'error' => 'No Stripe payment ID on this transaction.'];
    }

    // Calculate max refundable
    $already_refunded = get_transaction_refund_total($transaction_id);
    $max_refundable = (float) $txn['amount'] - $already_refunded;
    $refund_amount = $amount !== null ? floatval($amount) : $max_refundable;

    if ($refund_amount <= 0) {
        return ['success' => false, 'refund_id' => null, 'error' => 'Nothing left to refund.'];
    }
    if ($refund_amount > $max_refundable) {
        return ['success' => false, 'refund_id' => null, 'error' => 'Refund amount ($' . number_format($refund_amount, 2) . ') exceeds refundable balance ($' . number_format($max_refundable, 2) . ').'];
    }

    // Call Stripe API
    try {
        $params = [
            'payment_intent' => $txn['stripe_payment_id'],
            'amount' => (int) round($refund_amount * 100), // Stripe uses cents
        ];
        if (in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'])) {
            $params['reason'] = $reason;
        }

        $stripe_refund = $stripe->refunds->create($params);

        // Record locally
        $refund_id = record_stripe_refund([
            'transaction_id'   => $transaction_id,
            'stripe_refund_id' => $stripe_refund->id,
            'stripe_payment_id' => $txn['stripe_payment_id'],
            'user_id'          => $txn['user_id'],
            'amount'           => $refund_amount,
            'currency'         => $txn['currency'],
            'reason'           => $reason,
            'status'           => $stripe_refund->status === 'succeeded' ? 'succeeded' : 'pending',
            'refunded_by'      => $admin_user_id,
            'metadata'         => json_encode(['stripe_refund_status' => $stripe_refund->status]),
        ]);

        return ['success' => true, 'refund_id' => $refund_id, 'error' => null];

    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['success' => false, 'refund_id' => null, 'error' => 'Stripe error: ' . $e->getMessage()];
    }
}

/**
 * Get total amount refunded for a transaction.
 *
 * @param int $transaction_id
 * @return float
 */
function get_transaction_refund_total($transaction_id) {
    $tid = intval($transaction_id);
    $row = db_fetch(db_query(
        "SELECT COALESCE(SUM(amount), 0) as total FROM stripe_refund
         WHERE transaction_id = '$tid' AND status = 'succeeded'"
    ));
    return round((float) ($row['total'] ?? 0), 2);
}

/**
 * Get refunds with filters and pagination.
 *
 * @param array $filters Optional: transaction_id, user_id, status, from_date, to_date, page, per_page
 * @return array
 */
function get_stripe_refunds($filters = []) {
    $where = [];

    if (isset($filters['transaction_id']) && $filters['transaction_id'] !== '') {
        $tid = intval($filters['transaction_id']);
        $where[] = "r.transaction_id = '$tid'";
    }
    if (isset($filters['user_id']) && $filters['user_id'] !== '') {
        $uid = intval($filters['user_id']);
        $where[] = "r.user_id = '$uid'";
    }
    if (!empty($filters['status'])) {
        $s = sanitize($filters['status'], SQL);
        $where[] = "r.status = '$s'";
    }
    if (!empty($filters['from_date'])) {
        $s = sanitize($filters['from_date'], SQL);
        $where[] = "r.created >= '$s'";
    }
    if (!empty($filters['to_date'])) {
        $s = sanitize($filters['to_date'], SQL);
        $where[] = "r.created <= '$s 23:59:59'";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $page     = max(1, intval($filters['page'] ?? 1));
    $per_page = max(1, min(100, intval($filters['per_page'] ?? 25)));
    $offset   = ($page - 1) * $per_page;

    $total = (int) db_fetch(db_query("SELECT COUNT(*) as cnt FROM stripe_refund r $whereSQL"))['cnt'];

    $r = db_query("SELECT r.*, u.email as user_email, admin.email as refunded_by_email
                    FROM stripe_refund r
                    LEFT JOIN user u ON u.user_id = r.user_id
                    LEFT JOIN user admin ON admin.user_id = r.refunded_by
                    $whereSQL
                    ORDER BY r.created DESC
                    LIMIT $offset, $per_page");
    $items = $r ? db_fetch_all($r) : [];

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// REVENUE & LTV
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Refresh the materialized revenue summary for a user.
 * Called automatically after transactions and refunds.
 *
 * @param int $user_id
 * @return bool
 */
function refresh_user_revenue($user_id) {
    $uid = intval($user_id);

    // Calculate totals from transactions
    $txn_row = db_fetch(db_query(
        "SELECT COALESCE(SUM(amount), 0) as total_paid,
                COUNT(*) as transaction_count,
                MIN(created) as first_payment,
                MAX(created) as last_payment
         FROM stripe_transaction
         WHERE user_id = '$uid' AND status = 'succeeded'"
    ));

    $total_paid = (float) ($txn_row['total_paid'] ?? 0);
    $txn_count  = (int) ($txn_row['transaction_count'] ?? 0);
    $first_pay  = $txn_row['first_payment'] ?? null;
    $last_pay   = $txn_row['last_payment'] ?? null;

    // Calculate total refunded
    $ref_row = db_fetch(db_query(
        "SELECT COALESCE(SUM(amount), 0) as total_refunded, COUNT(*) as refund_count
         FROM stripe_refund
         WHERE user_id = '$uid' AND status = 'succeeded'"
    ));

    $total_refunded = (float) ($ref_row['total_refunded'] ?? 0);
    $refund_count   = (int) ($ref_row['refund_count'] ?? 0);
    $net_revenue    = round($total_paid - $total_refunded, 2);

    // Get stripe customer ID
    $cust_row = db_fetch(db_query(
        "SELECT stripe_customer_id FROM stripe_transaction
         WHERE user_id = '$uid' AND stripe_customer_id != '' AND stripe_customer_id IS NOT NULL
         ORDER BY created DESC LIMIT 1"
    ));
    $s_cust_id = sanitize($cust_row['stripe_customer_id'] ?? '', SQL);

    $first_col = $first_pay ? "'" . sanitize($first_pay, SQL) . "'" : 'NULL';
    $last_col  = $last_pay ? "'" . sanitize($last_pay, SQL) . "'" : 'NULL';

    $r = db_query("INSERT INTO stripe_user_revenue
        (user_id, total_paid, total_refunded, net_revenue, transaction_count, refund_count,
         first_payment_date, last_payment_date, stripe_customer_id)
        VALUES ('$uid', '$total_paid', '$total_refunded', '$net_revenue', '$txn_count', '$refund_count',
                $first_col, $last_col, '$s_cust_id')
        ON DUPLICATE KEY UPDATE
            total_paid = '$total_paid',
            total_refunded = '$total_refunded',
            net_revenue = '$net_revenue',
            transaction_count = '$txn_count',
            refund_count = '$refund_count',
            first_payment_date = $first_col,
            last_payment_date = $last_col,
            stripe_customer_id = '$s_cust_id'");

    return $r !== false;
}

/**
 * Get user revenue summary (LTV data).
 *
 * @param int $user_id
 * @return array|false
 */
function get_user_revenue($user_id) {
    $uid = intval($user_id);
    $r = db_query("SELECT r.*, u.email, u.created_date as signup_date
                    FROM stripe_user_revenue r
                    JOIN user u ON u.user_id = r.user_id
                    WHERE r.user_id = '$uid'");
    return $r ? db_fetch($r) : false;
}

/**
 * Calculate LTV (lifetime value) for a user.
 * LTV = net_revenue. Also computes average revenue per month.
 *
 * @param int $user_id
 * @return array ['ltv' => float, 'monthly_avg' => float, 'months_active' => int, 'net_revenue' => float]
 */
function calculate_user_ltv($user_id) {
    $uid = intval($user_id);

    $rev = get_user_revenue($uid);
    if (!$rev || (float) $rev['net_revenue'] <= 0) {
        return ['ltv' => 0.0, 'monthly_avg' => 0.0, 'months_active' => 0, 'net_revenue' => 0.0];
    }

    $net = (float) $rev['net_revenue'];
    $first = $rev['first_payment_date'] ? strtotime($rev['first_payment_date']) : time();
    $last  = $rev['last_payment_date'] ? strtotime($rev['last_payment_date']) : time();
    $months = max(1, ceil(($last - $first) / (30 * 86400)));

    return [
        'ltv'           => $net,
        'monthly_avg'   => round($net / $months, 2),
        'months_active' => (int) $months,
        'net_revenue'   => $net,
    ];
}

/**
 * Get LTV leaderboard (top users by net revenue).
 *
 * @param int $limit
 * @return array
 */
function get_ltv_leaderboard($limit = 50) {
    $limit = max(1, min(200, intval($limit)));
    $r = db_query("SELECT r.*, u.email, u.created_date as signup_date
                    FROM stripe_user_revenue r
                    JOIN user u ON u.user_id = r.user_id
                    WHERE r.net_revenue > 0
                    ORDER BY r.net_revenue DESC
                    LIMIT $limit");
    return $r ? db_fetch_all($r) : [];
}

/**
 * Get aggregate revenue stats.
 *
 * @return array
 */
function get_revenue_stats() {
    // Total revenue
    $total = db_fetch(db_query(
        "SELECT COALESCE(SUM(amount), 0) as total_revenue,
                COUNT(*) as total_transactions
         FROM stripe_transaction WHERE status = 'succeeded'"
    ));

    // Total refunded
    $refunded = db_fetch(db_query(
        "SELECT COALESCE(SUM(amount), 0) as total_refunded,
                COUNT(*) as total_refunds
         FROM stripe_refund WHERE status = 'succeeded'"
    ));

    // Paying users
    $paying = db_fetch(db_query(
        "SELECT COUNT(*) as cnt FROM stripe_user_revenue WHERE net_revenue > 0"
    ));

    // Last 30 days
    $recent = db_fetch(db_query(
        "SELECT COALESCE(SUM(amount), 0) as revenue_30d,
                COUNT(*) as transactions_30d
         FROM stripe_transaction
         WHERE status = 'succeeded' AND created >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    ));

    $total_rev = (float) ($total['total_revenue'] ?? 0);
    $total_ref = (float) ($refunded['total_refunded'] ?? 0);
    $paying_count = (int) ($paying['cnt'] ?? 0);

    return [
        'total_revenue'      => $total_rev,
        'total_refunded'     => $total_ref,
        'net_revenue'        => round($total_rev - $total_ref, 2),
        'total_transactions' => (int) ($total['total_transactions'] ?? 0),
        'total_refunds'      => (int) ($refunded['total_refunds'] ?? 0),
        'paying_users'       => $paying_count,
        'avg_ltv'            => $paying_count > 0 ? round(($total_rev - $total_ref) / $paying_count, 2) : 0,
        'revenue_30d'        => (float) ($recent['revenue_30d'] ?? 0),
        'transactions_30d'   => (int) ($recent['transactions_30d'] ?? 0),
    ];
}

/**
 * Get monthly revenue trend.
 *
 * @param int $months
 * @return array
 */
function get_monthly_revenue_trend($months = 12) {
    $months = intval($months);

    $r = db_query("SELECT
            DATE_FORMAT(t.created, '%Y-%m') as month_val,
            COALESCE(SUM(t.amount), 0) as revenue,
            COUNT(*) as transaction_count
        FROM stripe_transaction t
        WHERE t.status = 'succeeded' AND t.created >= DATE_SUB(NOW(), INTERVAL $months MONTH)
        GROUP BY DATE_FORMAT(t.created, '%Y-%m')
        ORDER BY month_val");

    $revenue = $r ? db_fetch_all($r) : [];

    // Refund trend
    $r2 = db_query("SELECT
            DATE_FORMAT(r.created, '%Y-%m') as month_val,
            COALESCE(SUM(r.amount), 0) as refunded
        FROM stripe_refund r
        WHERE r.status = 'succeeded' AND r.created >= DATE_SUB(NOW(), INTERVAL $months MONTH)
        GROUP BY DATE_FORMAT(r.created, '%Y-%m')
        ORDER BY month_val");

    $refunds = [];
    if ($r2) {
        foreach (db_fetch_all($r2) as $row) {
            $refunds[$row['month_val']] = (float) $row['refunded'];
        }
    }

    // Merge
    foreach ($revenue as &$row) {
        $row['refunded'] = $refunds[$row['month_val']] ?? 0;
        $row['net'] = round((float) $row['revenue'] - $row['refunded'], 2);
    }

    return $revenue;
}

// ═══════════════════════════════════════════════════════════════════════════
// TROUBLESHOOTING — STRIPE API LOOKUPS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Fetch a payment intent directly from Stripe for troubleshooting.
 *
 * @param string $payment_intent_id
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function stripe_lookup_payment($payment_intent_id) {
    $stripe = get_stripe_client();
    if (!$stripe) {
        return ['success' => false, 'data' => null, 'error' => 'Stripe not configured.'];
    }

    try {
        $pi = $stripe->paymentIntents->retrieve($payment_intent_id, ['expand' => ['charges', 'customer']]);
        return ['success' => true, 'data' => $pi->toArray(), 'error' => null];
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Fetch a customer from Stripe.
 *
 * @param string $customer_id
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function stripe_lookup_customer($customer_id) {
    $stripe = get_stripe_client();
    if (!$stripe) {
        return ['success' => false, 'data' => null, 'error' => 'Stripe not configured.'];
    }

    try {
        $customer = $stripe->customers->retrieve($customer_id, ['expand' => ['subscriptions']]);
        return ['success' => true, 'data' => $customer->toArray(), 'error' => null];
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Fetch a charge from Stripe.
 *
 * @param string $charge_id
 * @return array
 */
function stripe_lookup_charge($charge_id) {
    $stripe = get_stripe_client();
    if (!$stripe) {
        return ['success' => false, 'data' => null, 'error' => 'Stripe not configured.'];
    }

    try {
        $charge = $stripe->charges->retrieve($charge_id);
        return ['success' => true, 'data' => $charge->toArray(), 'error' => null];
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Fetch balance transactions from Stripe for a payment.
 *
 * @param string $payment_intent_id
 * @return array
 */
function stripe_lookup_balance_transactions($payment_intent_id) {
    $stripe = get_stripe_client();
    if (!$stripe) {
        return ['success' => false, 'data' => null, 'error' => 'Stripe not configured.'];
    }

    try {
        $txns = $stripe->balanceTransactions->all([
            'limit' => 20,
            'source' => $payment_intent_id,
        ]);
        return ['success' => true, 'data' => $txns->toArray(), 'error' => null];
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Get revenue for a specific user across a date range.
 * Used by child apps to check a user's spending.
 *
 * @param int    $user_id
 * @param string $from Y-m-d (optional)
 * @param string $to   Y-m-d (optional)
 * @return float
 */
function get_user_revenue_amount($user_id, $from = null, $to = null) {
    $uid = intval($user_id);
    $where = "WHERE user_id = '$uid' AND status = 'succeeded'";

    if ($from) {
        $s = sanitize($from, SQL);
        $where .= " AND created >= '$s'";
    }
    if ($to) {
        $s = sanitize($to, SQL);
        $where .= " AND created <= '$s 23:59:59'";
    }

    $row = db_fetch(db_query("SELECT COALESCE(SUM(amount), 0) as total FROM stripe_transaction $where"));
    return round((float) ($row['total'] ?? 0), 2);
}

/**
 * Get distinct source apps that have recorded transactions.
 *
 * @return array
 */
function get_stripe_source_apps() {
    $r = db_query("SELECT DISTINCT source_app FROM stripe_transaction ORDER BY source_app");
    if (!$r) return [];
    $apps = [];
    foreach (db_fetch_all($r) as $row) {
        $apps[] = $row['source_app'];
    }
    return $apps;
}

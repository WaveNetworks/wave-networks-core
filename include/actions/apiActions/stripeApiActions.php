<?php
/**
 * Stripe API Actions
 * Actions: apiRecordTransaction, apiGetUserRevenue, apiGetUserLtv
 * Authenticated via service API key (Bearer token) with scope gating.
 * These endpoints let child apps record payments and query revenue.
 */

// ── Record a transaction (called by child apps after Stripe payment) ────
if (($action ?? null) == 'apiRecordTransaction') {
    if (require_api_scope('stripe:write')) {
        $errs = [];

        $amount = $_POST['amount'] ?? '';
        if (!is_numeric($amount) || floatval($amount) < 0) {
            $errs['amount'] = 'amount must be a non-negative number.';
        }
        if (empty($_POST['source_app'])) {
            $errs['source_app'] = 'source_app is required.';
        }

        if (count($errs) <= 0) {
            $txn_data = [
                'stripe_payment_id'     => $_POST['stripe_payment_id'] ?? '',
                'stripe_customer_id'    => $_POST['stripe_customer_id'] ?? '',
                'stripe_invoice_id'     => $_POST['stripe_invoice_id'] ?? '',
                'stripe_subscription_id' => $_POST['stripe_subscription_id'] ?? '',
                'user_id'               => isset($_POST['user_id']) && $_POST['user_id'] !== '' ? intval($_POST['user_id']) : null,
                'source_app'            => $_POST['source_app'],
                'amount'                => floatval($amount),
                'currency'              => $_POST['currency'] ?? 'usd',
                'status'                => $_POST['status'] ?? 'succeeded',
                'description'           => $_POST['description'] ?? '',
                'payment_method'        => $_POST['payment_method'] ?? '',
                'metadata'              => $_POST['metadata'] ?? null,
                'stripe_created'        => $_POST['stripe_created'] ?? null,
            ];

            $transaction_id = record_stripe_transaction($txn_data);
            if ($transaction_id !== false) {
                $data['transaction_id'] = $transaction_id;
                $_SESSION['success'] = 'Transaction recorded.';
            } else {
                $_SESSION['error'] = 'Failed to record transaction.';
            }
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

// ── Get user revenue data ───────────────────────────────────────────────
if (($action ?? null) == 'apiGetUserRevenue') {
    if (require_api_scope('stripe:read')) {
        $errs = [];

        $user_id = $_POST['user_id'] ?? '';
        if ($user_id === '' || !is_numeric($user_id)) {
            $errs['user_id'] = 'user_id is required.';
        }

        if (count($errs) <= 0) {
            $rev = get_user_revenue(intval($user_id));
            $data['revenue'] = $rev ?: [
                'total_paid' => 0, 'total_refunded' => 0, 'net_revenue' => 0,
                'transaction_count' => 0, 'refund_count' => 0,
            ];

            // Optional date-range filter
            if (!empty($_POST['from_date']) || !empty($_POST['to_date'])) {
                $data['range_revenue'] = get_user_revenue_amount(
                    intval($user_id),
                    $_POST['from_date'] ?? null,
                    $_POST['to_date'] ?? null
                );
            }

            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

// ── Get user LTV ────────────────────────────────────────────────────────
if (($action ?? null) == 'apiGetUserLtv') {
    if (require_api_scope('stripe:read')) {
        $errs = [];

        $user_id = $_POST['user_id'] ?? '';
        if ($user_id === '' || !is_numeric($user_id)) {
            $errs['user_id'] = 'user_id is required.';
        }

        if (count($errs) <= 0) {
            $data['ltv'] = calculate_user_ltv(intval($user_id));
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

// ── Get revenue stats (aggregate) ───────────────────────────────────────
if (($action ?? null) == 'apiGetRevenueStats') {
    if (require_api_scope('stripe:read')) {
        $data['stats'] = get_revenue_stats();
        $_SESSION['success'] = 'OK';
    }
}

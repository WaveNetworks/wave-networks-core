-- Migration 2.9 for Main Database
-- Stripe payment tracking: transactions, refunds, revenue per user, LTV
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.9;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ── Stripe transactions (payments, invoices, charges) ───────────────────
CREATE TABLE IF NOT EXISTS stripe_transaction (
    transaction_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_payment_id   VARCHAR(255) NULL,
    stripe_customer_id  VARCHAR(255) NULL,
    stripe_invoice_id   VARCHAR(255) NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    user_id             INT UNSIGNED NULL,
    source_app          VARCHAR(100) NOT NULL DEFAULT 'admin',
    amount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency            VARCHAR(10) NOT NULL DEFAULT 'usd',
    status              ENUM('succeeded','pending','failed','canceled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    description         VARCHAR(500) NULL,
    payment_method      VARCHAR(100) NULL,
    metadata            JSON NULL,
    stripe_created      DATETIME NULL,
    created             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id),
    KEY idx_stripe_payment_id (stripe_payment_id),
    KEY idx_stripe_customer_id (stripe_customer_id),
    KEY idx_status (status),
    KEY idx_source_app (source_app),
    KEY idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Stripe refunds ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stripe_refund (
    refund_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_refund_id    VARCHAR(255) NULL,
    transaction_id      INT UNSIGNED NOT NULL,
    stripe_payment_id   VARCHAR(255) NULL,
    user_id             INT UNSIGNED NULL,
    amount              DECIMAL(12,2) NOT NULL,
    currency            VARCHAR(10) NOT NULL DEFAULT 'usd',
    reason              VARCHAR(255) NULL,
    status              ENUM('succeeded','pending','failed','canceled') NOT NULL DEFAULT 'pending',
    refunded_by         INT UNSIGNED NULL,
    metadata            JSON NULL,
    created             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_transaction_id (transaction_id),
    KEY idx_user_id (user_id),
    KEY idx_stripe_refund_id (stripe_refund_id),
    KEY idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── User revenue summary (materialized for fast LTV lookups) ────────────
CREATE TABLE IF NOT EXISTS stripe_user_revenue (
    user_id             INT UNSIGNED PRIMARY KEY,
    total_paid          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_refunded      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    net_revenue         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    transaction_count   INT UNSIGNED NOT NULL DEFAULT 0,
    refund_count        INT UNSIGNED NOT NULL DEFAULT 0,
    first_payment_date  DATETIME NULL,
    last_payment_date   DATETIME NULL,
    stripe_customer_id  VARCHAR(255) NULL,
    updated             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_net_revenue (net_revenue),
    KEY idx_stripe_customer_id (stripe_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

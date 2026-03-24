-- Migration 2.7 for Main Database
-- Cost tracking: cost_entry (per-event costs) + cost_recurring (recurring expenses)
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.7;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Per-event cost entries (COGS, CAC, support) recorded by child apps or admin
CREATE TABLE IF NOT EXISTS cost_entry (
    cost_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cost_type ENUM('cogs','cac','support') NOT NULL,
    source_app VARCHAR(100) NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(12,6) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    metadata JSON DEFAULT NULL,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cost_type (cost_type),
    INDEX idx_source_app (source_app),
    INDEX idx_user_id (user_id),
    INDEX idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recurring expenses (rent, subscriptions, salaries, etc.) managed by admin
CREATE TABLE IF NOT EXISTS cost_recurring (
    recurring_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cost_type ENUM('cogs','cac','support') NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    frequency ENUM('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 2.8 for Main Database
-- Feedback system: user feedback, upvotes, and change requests
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 2.8;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- User feedback entries submitted via floating tab or API
CREATE TABLE IF NOT EXISTS feedback (
    feedback_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feedback_type ENUM('bug','suggestion','general') NOT NULL DEFAULT 'general',
    source_app VARCHAR(100) NOT NULL DEFAULT 'admin',
    page_url VARCHAR(500) DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    user_role VARCHAR(50) DEFAULT NULL,
    message TEXT NOT NULL,
    context_json JSON DEFAULT NULL,
    upvotes INT UNSIGNED NOT NULL DEFAULT 0,
    change_request_id INT UNSIGNED DEFAULT NULL,
    status ENUM('new','reviewed','grouped','dismissed') NOT NULL DEFAULT 'new',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (feedback_type),
    INDEX idx_source (source_app),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_change_request (change_request_id),
    INDEX idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One upvote per user per feedback entry
CREATE TABLE IF NOT EXISTS feedback_upvote (
    upvote_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feedback_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_feedback_user (feedback_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Change requests / additions created from feedback
CREATE TABLE IF NOT EXISTS change_request (
    change_request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    request_type ENUM('change','addition') NOT NULL,
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('proposed','approved','in_progress','completed','paused','rejected') NOT NULL DEFAULT 'proposed',
    source_app VARCHAR(100) DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    assigned_to INT UNSIGNED DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_type (request_type),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

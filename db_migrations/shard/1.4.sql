-- Migration 1.4 for Shard Database
-- Task #2502: feedback receipts no longer raise the bell / app icon badge.
-- One-off: mark the receipts and status updates already sitting unread as read, so the
-- badge shows only what needs the person. "Shipped"/"completed" stay unread on purpose.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $shard_version = 1.4;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

UPDATE notification SET is_read = 1, push_sent = 1
WHERE is_read = 0
  AND category_slug IN ('feedback_received', 'feedback_update')
  AND title NOT IN ('Change request completed', 'Change request rejected', 'Something you asked for just shipped');

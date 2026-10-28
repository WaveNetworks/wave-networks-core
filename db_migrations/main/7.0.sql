-- Migration 7.0 for Main Database
-- Approve a sign-in on the phone as the SECOND step after a password (task #2662)
--
--   login_approval gains kind: 'qr' is the existing "Sign in with your phone" QR
--   (main/5.8); 'push' is a 2FA step started on auth/2fa.php, already tied to the
--   account (user_id set at creation) and pushed to its phones. match_number is the
--   2-digit number the browser shows; the phone must pick it out of three to approve.
--   deny_reason says why a push was refused (not_me | wrong_number) for the notice.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 7.0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE login_approval
    ADD COLUMN IF NOT EXISTS kind         ENUM('qr','push') NOT NULL DEFAULT 'qr' AFTER login_approval_id,
    ADD COLUMN IF NOT EXISTS match_number TINYINT UNSIGNED  NOT NULL DEFAULT 0    AFTER kind,
    ADD COLUMN IF NOT EXISTS deny_reason  VARCHAR(20)       NOT NULL DEFAULT ''   AFTER status,
    ADD KEY IF NOT EXISTS idx_user_kind (user_id, kind, status);

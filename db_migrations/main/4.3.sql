-- Migration 4.3 for Main Database
-- mobile_parity: add 'element' category for the per-DOM-element rows
-- emitted by admin/scripts/diff_view_contract.py.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 4.3;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE mobile_parity
  MODIFY COLUMN category
  ENUM('page','action','script','snippet','widget','element') NOT NULL;

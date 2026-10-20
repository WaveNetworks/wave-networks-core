-- Migration 6.5 for Main Database
-- feedback.status gains 'resolved'.
-- The CR-completed cascade (update_change_request), apiMarkFeedbackShipped and
-- migration 3.7 all write status = 'resolved', but the column (main 2.8) was
-- ENUM('new','reviewed','grouped','dismissed'). Outside strict mode MySQL stored
-- the empty string instead, so shipped feedback showed no status at all and fell
-- out of every status count (nokemo feedback #11 and #13; task #2539).
-- 'resolved' is appended LAST so the MODIFY is a metadata-only change and the
-- blanked rows survive it as they are. Only those rows are then repaired: the
-- status their change request implies, or 'reviewed' when they have none.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.5;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE feedback
  MODIFY COLUMN status ENUM('new','reviewed','grouped','dismissed','resolved') NOT NULL DEFAULT 'new';

UPDATE feedback f
  JOIN change_request cr ON cr.change_request_id = f.change_request_id
   SET f.status = CASE cr.status WHEN 'completed' THEN 'resolved'
                                 WHEN 'rejected'  THEN 'dismissed'
                                 ELSE 'grouped' END
 WHERE f.status = '';

UPDATE feedback SET status = 'reviewed' WHERE status = '';

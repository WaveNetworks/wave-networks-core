-- Migration 3.7 for Main Database
-- Backfill: close feedback rows that were addressed via nokemo mcp_task
-- work instead of admin change requests. Feedback #11 was shipped as
-- nokemo task 86 (pagination/sorting on tasks list, completed 2026-04-11).
-- Feedback #13 was shipped as nokemo task 84 (notification dropdown
-- navigates to notifications page, completed 2026-04-11). Neither row is
-- linked to a change_request, so the existing CR→feedback cascade
-- (apiBulkCascadeFeedbackFromCR) can't reach them. Mark them resolved so
-- the submitters see the items closed on their feedback list and the
-- admin "new" queue reflects real state. Scoped to the two specific IDs
-- with status IN ('new','reviewed','grouped') so this is idempotent and
-- won't clobber manual reclassification.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.7;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

UPDATE feedback
   SET status = 'resolved',
       context_json = JSON_SET(
           COALESCE(context_json, JSON_OBJECT()),
           '$.resolved_by_task_id', 86,
           '$.resolved_note', 'Shipped as nokemo task 86 (pagination and sorting on tasks list), completed 2026-04-11.'
       )
 WHERE feedback_id = 11
   AND status IN ('new','reviewed','grouped');

UPDATE feedback
   SET status = 'resolved',
       context_json = JSON_SET(
           COALESCE(context_json, JSON_OBJECT()),
           '$.resolved_by_task_id', 84,
           '$.resolved_note', 'Shipped as nokemo task 84 (notification dropdown navigates to notifications page), completed 2026-04-11.'
       )
 WHERE feedback_id = 13
   AND status IN ('new','reviewed','grouped');

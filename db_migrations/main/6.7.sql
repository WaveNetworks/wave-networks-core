-- Migration 6.7 for Main Database
-- feedback type corrections (owner 2026-10-21: "a reclassify run on feedback to get the right type — I often put
-- bugs as general because it's easier"). feedback_type stays THE type every reader uses; these record who set it.
-- submitted_type: what the person picked, kept on the first correction (NULL = never corrected), so any change
-- can be seen and undone. type_source: user (as sent) | ai (the ops box's classifier read it; it may have kept
-- the type) | admin (a person set it; the classifier never overrides that). type_note: the reason given.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.7;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE feedback
  ADD COLUMN submitted_type ENUM('bug','suggestion','general') NULL DEFAULT NULL AFTER feedback_type,
  ADD COLUMN type_source ENUM('user','ai','admin') NOT NULL DEFAULT 'user' AFTER submitted_type,
  ADD COLUMN type_note VARCHAR(200) NULL DEFAULT NULL AFTER type_source,
  ADD INDEX idx_type_source (type_source);

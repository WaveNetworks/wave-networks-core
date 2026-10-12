-- Migration 6.0 for Main Database
-- Task #2398: api_key.api_key (remember-me cookie + device bearer keys) is only COMPARED, so it
-- is stored as 'sha256:' + SHA2(key, 256), matching wn_token_hash() in
-- include/common/secretFunctions.php. Clients keep the raw key; validate_api_key() hashes what
-- they send, so every signed-in browser and device stays signed in. Idempotent: rows already
-- carrying the marker are skipped, so running this twice changes nothing.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

UPDATE `api_key` SET `api_key` = CONCAT('sha256:', SHA2(`api_key`, 256))
 WHERE `api_key` IS NOT NULL AND `api_key` <> '' AND `api_key` NOT LIKE 'sha256:%';

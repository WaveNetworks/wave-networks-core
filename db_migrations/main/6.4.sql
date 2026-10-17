-- Migration 6.4 for Main Database
-- login_history.login_method: ENUM -> VARCHAR(30). The OAuth sign-ins record 'oauth_<provider>'
-- (oauth_google, oauth_apple, ...; wn_login_finish), which the ENUM never listed, so every
-- Continue with Google / Apple sign-in since #2461 was stored as '' (or refused under strict mode)
-- and nothing could tell which method a person used (nokemo task #2461). Existing values are kept.
-- ⚠️ REMINDER: Update admin/include/bootstrap.php $db_version = 6.4;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE login_history MODIFY login_method VARCHAR(30) NOT NULL DEFAULT 'password';

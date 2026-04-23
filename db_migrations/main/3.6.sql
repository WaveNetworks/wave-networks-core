-- Migration 3.6 for Main Database
-- Fix character set on auth_settings so branding text columns (site_name,
-- site_short_name, site_description) accept Unicode characters like
-- superscript letters (ᵘᵖ), ™, ², etc. The table was created in 1.0 without
-- an explicit charset and inherited latin1 on older installs, which replaced
-- multi-byte UTF-8 bytes with "??" on save. Converting the whole table is
-- safe because all existing content is ASCII or already valid UTF-8.
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = 3.6;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

ALTER TABLE auth_settings
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

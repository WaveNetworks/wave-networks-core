# Action Files & Database Migrations

## ACTION FILES — TWO INVOCATION METHODS
Action files are auto-included via glob in common.php / common_api.php / common_auth.php.
They run on EVERY request before views render. No routing config needed.

CORRECT: Add action files to admin/include/actions/
  Authenticated: admin/include/actions/memberActions/yourFeatureActions.php
  Public API:    admin/include/actions/apiActions/yourFeatureActions.php
  Auth flow:     admin/include/actions/loginActions/yourFeatureActions.php

Two ways to invoke an action:
  1. Plain form POST: <form method="post"> on any view page (no action attr).
     common.php runs the action, sets session flash, view re-renders. No JS needed.
     Preferred for settings forms, file uploads, any full-page action.
  2. AJAX: POST to admin/api/index.php via apiPost() from bs-init.js.
     Returns JSON. Use for inline UI updates (mark read, delete row, live search).

WRONG:
  - Creating new endpoint files in admin/api/
  - Creating custom routing systems
  - Pointing <form action=""> at admin/api/index.php (navigates to raw JSON)

See: admin/include/actions/CLAUDE.md for full action file rules.

## DATABASE MIGRATIONS — TWO-STEP PROCESS
EVERY migration requires BOTH steps or it WILL NOT RUN:

Step 1: Create the SQL file
  Main DB:  db_migrations/main/{version}.sql  (e.g. 1.1.sql)
  Shard DB: db_migrations/shard/{version}.sql

Step 2: UPDATE VERSION IN admin/include/common.php
  Main DB migration:  change $db_version = X.X;
  Shard DB migration: change $shard_version = X.X;

THIS STEP IS FREQUENTLY FORGOTTEN — DO NOT SKIP IT.
See: db_migrations/CLAUDE.md for full migration rules.

## Migration file template
-- Migration X.X for [Main/Shard] Database
-- Brief description of changes
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = X.X;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
-- SQL here

Note: Do NOT wrap DDL (CREATE TABLE, ALTER TABLE, DROP TABLE) in
START TRANSACTION / COMMIT — MySQL DDL causes implicit commits.
The migration runner handles transactions automatically and gracefully
skips commit if DDL already auto-committed. Migration failures are
logged to the admin error_log DB table as WARNING level.

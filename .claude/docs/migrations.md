# Database migrations — two-step process

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
```sql
-- Migration X.X for [Main/Shard] Database
-- Brief description of changes
-- REMINDER: Update admin/include/common.php $db_version = X.X;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
-- SQL here
```

Note: Do NOT wrap DDL (CREATE TABLE, ALTER TABLE, DROP TABLE) in
START TRANSACTION / COMMIT — MySQL DDL causes implicit commits.
The migration runner handles transactions automatically and gracefully
skips commit if DDL already auto-committed. Migration failures are
logged to the admin error_log DB table as WARNING level.

## Migration rules
- Migration files: decimal versioning (1.0, 1.1, 2.0), no START TRANSACTION/COMMIT (runner handles it)
- Update $db_version or $shard_version in common.php WITH EVERY MIGRATION
- Use IF NOT EXISTS in CREATE TABLE statements (makes migrations rerunnable)
- NEVER edit existing migration files — always create a new version
- NEVER manually update the db_version table — the migration system handles it

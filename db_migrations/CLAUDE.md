# db_migrations — CLAUDE.md

## CRITICAL: Every migration is a TWO-STEP process

STEP 1 — Create the SQL file:
  Main DB:  db_migrations/main/{version}.sql   (e.g. 1.1.sql)
  Shard DB: db_migrations/shard/{version}.sql

STEP 2 — UPDATE VERSION IN admin/include/common.php
  For main DB:  change $db_version = X.X;    (near top of file)
  For shard DB: change $shard_version = X.X;

IF YOU SKIP STEP 2, THE MIGRATION WILL NEVER RUN.
The system compares these variables against the db_version table in each
database. If common.php still shows the old version number, it assumes
the DB is already up to date and skips the file entirely.

## How the migration system works (migrationFunctions.php)

On every page load, common.php calls:
  check_and_migrate_main_db()       — compares $db_version vs db_version table
  check_and_migrate_all_shards()    — iterates $shardConfigs, migrates each

get_available_migrations($db_type)  — scans db_migrations/main/ or shard/
                                      for files matching /^\d+\.\d+\.sql$/
                                      returns sorted float array

run_migration($conn, $file, $type, $version)
  — reads SQL file
  — splits on /;[\r\n]+/
  — skips START TRANSACTION, COMMIT, ROLLBACK (system manages transaction)
  — executes each statement in a PDO transaction
  — on success: UPDATE db_version SET version = ? WHERE version_id = 1
  — on failure: rollback + $_SESSION['error'] (does not block login)

Migrations run on EVERY page load (not cached per session).
Each migration runs only when current_db_version < target_version.
Concurrent logins are safe — transaction isolation prevents double-apply.

## Version numbering rules
- Decimal format: 1.0, 1.1, 1.2, 2.0
- Increment by 0.1 for minor changes, bump integer for breaking changes
- Main DB and shard DB versions are INDEPENDENT (can differ)
- Migrations execute in numeric order: 1.0 → 1.1 → 1.2 → 2.0
- Missing intermediate versions are skipped safely
- NEVER edit an existing migration file — always create a new version

## SQL file template
-- Migration X.X for [Main/Shard] Database
-- [Brief description of changes]
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = X.X;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Your SQL here
ALTER TABLE tablename ADD COLUMN col datatype;

COMMIT;

## Common migration patterns
Add column:    ALTER TABLE t ADD COLUMN col datatype;
Add index:     ALTER TABLE t ADD KEY idx_name (col);
Create table:  CREATE TABLE IF NOT EXISTS t (...) ENGINE=InnoDB;
Insert data:   INSERT INTO t (cols) VALUES (...) ON DUPLICATE KEY UPDATE ...;
Modify column: ALTER TABLE t MODIFY COLUMN col new_datatype;

## Rules
- Always create a NEW file (never edit existing migrations)
- Always wrap in START TRANSACTION / COMMIT
- Always update $db_version or $shard_version in common.php
- Use IF NOT EXISTS for CREATE TABLE (idempotent on retry)
- Add reminder comment at top of every migration file
- Test on development database before deploying
- Never manually UPDATE the db_version table
- Never skip version numbers (1.0 → 1.2 without a 1.1 is fine, but confusing)
- Never update the wrong variable (main vs shard)

## Verification checklist before committing
[ ] SQL file exists in correct directory (main/ or shard/)
[ ] $db_version or $shard_version updated in common.php
[ ] Version in common.php MATCHES the new filename (e.g. 1.1.sql → $db_version = 1.1)
[ ] Migration wraps statements in START TRANSACTION / COMMIT
[ ] Reminder comment included at top of migration file
[ ] Tested on development database

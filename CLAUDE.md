# wave-networks-core — CLAUDE.md

## What this repo is
Standalone auth and user admin for Wave Networks. Knows nothing about
plans, billing, or business domain. Child apps are separate repos deployed
as siblings in the same webroot, reaching this repo via ../admin/include/common.php

## ACTION FILES — TWO INVOCATION METHODS (critical)
Action files run on EVERY request before views render. No routing config needed.
Add action files to include/actions/ (memberActions/, apiActions/, loginActions/).
Two ways to invoke: plain form POST (preferred for full-page) or AJAX via apiPost().
WRONG: Creating new endpoint files, custom routing, pointing form action at api/.
See [CLAUDE-development.md](CLAUDE-development.md) for patterns. See [include/actions/CLAUDE.md](include/actions/CLAUDE.md) for full rules.

## DATABASE MIGRATIONS — TWO-STEP PROCESS (critical)
Step 1: Create SQL file in db_migrations/main/{version}.sql or shard/{version}.sql
Step 2: UPDATE $db_version or $shard_version in include/common.php
THIS STEP IS FREQUENTLY FORGOTTEN — DO NOT SKIP IT.
See [CLAUDE-development.md](CLAUDE-development.md) for template. See [db_migrations/CLAUDE.md](db_migrations/CLAUDE.md) for full rules.

## Folder layout
The repo root is admin/ — deployed into public_html/admin/.
Child apps are siblings: public_html/your-app/. ../files/ is one level above public_html.

## Path reference
```
auth/*.php          -> include: ../include/common_auth.php
app/index.php       -> include: ../include/common.php
api/index.php       -> include: ../include/common_api.php
views/*.php         -> included BY app/index.php (never directly)
snippets/*.php      -> included by views and auth pages
(child)/app/        -> include: ../include/common.php -> ../../admin/include/common.php
vendor/autoload.php -> from admin: ../vendor/autoload.php
```

## Active coding rules — follow when writing any code in this repo

DO:
- Escape out of PHP for HTML — never echo HTML from within PHP strings
- Use <?= $var ?> shorthand for output, always h() for user-supplied data
- Use sanitize($val, SQL) for any DB value not already sanitized
- Flat if($_POST['action'] == 'x') blocks in action files — no dispatcher
- Collect validation errors in $errs array before setting $_SESSION['error']
- Always set $_SESSION['success'] when an action completes successfully
- New helpers go in include/common/ — glob picks them up automatically
- New actions go in include/actions/[memberActions|apiActions|loginActions]/
- Migration files: decimal versioning (1.0, 1.1, 2.0), no START TRANSACTION/COMMIT
- Update $db_version or $shard_version in common.php WITH EVERY MIGRATION
- Use IF NOT EXISTS in CREATE TABLE statements (makes migrations rerunnable)
- Asset paths: relative not absolute (../admin/assets/ from child, assets/ from admin)
- __DIR__-based paths for all includes (never relative paths like ../../)

DO NOT:
- Edit vendor/ ever
- Write to core DB tables (user, device, api_key, etc.) from child app code
- Echo HTML strings from PHP — escape out instead
- Use absolute asset paths
- Skip h() or sanitize() for any output
- Add business domain logic (plans, billing, coaching) to this repo
- Create a new $db PDO connection — use the global $db set in common.php
- Store credentials anywhere except config/config.php (gitignored)
- Create new API endpoint files — use action files instead
- Edit existing migration files — always create a new version
- Manually update the db_version table — the migration system handles it
- Set $_SESSION['error'] immediately on first validation failure (collect all errors first)
- Use JavaScript, computed styles, or DOM inspection to confirm visual fixes — trust screenshots

## Topic files

| File | Contents |
|------|----------|
| [CLAUDE-architecture.md](CLAUDE-architecture.md) | Shard routing, config loading, homedir system, Docker, child app integration, sensitive folders, view/snippet paths |
| [CLAUDE-services.md](CLAUDE-services.md) | Email queue, notifications, cron jobs, error logging, service API keys, MCP server |
| [CLAUDE-schema.md](CLAUDE-schema.md) | GDPR compliance (tables, helpers, login flow, re-consent, child app usage), theme management, versioning/update checks, branding settings |
| [CLAUDE-development.md](CLAUDE-development.md) | Template pattern, action file pattern, API response format, migration template, shared assets |
| [db_migrations/CLAUDE.md](db_migrations/CLAUDE.md) | Migration version rules (read before any DB work) |
| [include/actions/CLAUDE.md](include/actions/CLAUDE.md) | Action file patterns (read before any API work) |

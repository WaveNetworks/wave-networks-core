# wave-networks-core — CLAUDE.md

## What this repo is
Standalone auth and user admin for Wave Networks. Knows nothing about
plans, billing, or business domain. Child apps are separate repos deployed
as siblings in the same webroot, reaching this repo via ../admin/include/common.php

## Folder layout (critical)
The repo root is admin/ — deployed into public_html/admin/.
public_html/ is the webroot but is not a repo.
Child apps are separate repos deployed as siblings of admin/ inside public_html/.
One exception: ../files/ is created by the installer one level
above public_html/ and is never in this repo.

## Path reference (critical — all paths relative to repo root)
```
admin/auth/*.php          -> include: ../include/common_auth.php
admin/app/index.php       -> include: ../include/common.php
admin/api/index.php       -> include: ../include/common_api.php
admin/views/*.php         -> included BY admin/app/index.php (never directly)
admin/snippets/*.php      -> included by views and auth pages
(child-app)/app/index.php -> include: ../include/common.php (child's own bootstrap)
(child-app)/include/common.php -> include: ../../admin/include/common.php (core)
vendor/autoload.php       -> from admin/*: ../vendor/autoload.php
```

## ALWAYS-LOAD RULES — three things Claude must never forget

1. **Action files, not endpoints.** New server-side work goes in
   `admin/include/actions/{memberActions|apiActions|loginActions}/*.php`.
   Do NOT create new files in `admin/api/`. Do NOT build routers.
   See `.claude/docs/actions.md` and `admin/include/actions/CLAUDE.md`.

2. **Migrations are TWO steps.** Create the SQL in `db_migrations/main/{ver}.sql`
   or `db_migrations/shard/{ver}.sql` AND update `$db_version` / `$shard_version`
   in `admin/include/common.php`. Skipping step 2 means the migration never runs.
   See `.claude/docs/migrations.md` and `db_migrations/CLAUDE.md`.

3. **No business domain.** This repo is auth + user admin only. Plans, billing,
   coaching, and app-specific features belong in child-app repos. Never write
   to core DB tables (user, device, api_key, etc.) from child code.

## Topic index — load what you need
Load only the files relevant to your current task. Full technical detail lives in these:

| File | When to read |
|------|--------------|
| `.claude/docs/architecture.md` | Shard routing, config loading, homedirs, sensitive folders, Docker vs shared hosting |
| `.claude/docs/actions.md` | How action files work, form vs AJAX invocation, action/template/API patterns |
| `.claude/docs/migrations.md` | Two-step migration rules, file template, DDL/transaction gotchas |
| `.claude/docs/email.md` | queue_email() usage, email_queue/email_settings tables, throttling, allowed senders |
| `.claude/docs/notifications.md` | send_notification(), push subscriptions, VAPID, service worker |
| `.claude/docs/cron.md` | cron scripts, common_readonly.php, daily/monthly task directories |
| `.claude/docs/child-apps.md` | Child app bootstrap, shared CSS/JS assets, integration rules |
| `.claude/docs/error-logging.md` | errorHandler.php, error_log table, admin view, scope-gated API |
| `.claude/docs/api-keys.md` | service_api_key table, scopes, Bearer-token auth, admin UI |
| `.claude/docs/mcp.md` | MCP server.php, tool list, scopes, Claude Desktop/Code config |
| `.claude/docs/theming.md` | Bootswatch + custom themes, branding fields, auth_settings |
| `.claude/docs/gdpr.md` | consent_version, user_consent, deletion/export requests, login history, re-consent flow |
| `.claude/docs/versioning.md` | WN_ADMIN_VERSION, update check system, /api/versions public endpoint, changelog generator |
| `.claude/docs/coding-rules.md` | Full DO/DO NOT checklist — read before writing any PHP/JS |

## Specialized sub-CLAUDE.md files (tree-local rules)
- `db_migrations/CLAUDE.md` — migration version rules (read before any DB work)
- `admin/include/actions/CLAUDE.md` — action file patterns (read before any API work)

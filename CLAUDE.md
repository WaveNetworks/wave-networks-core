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
admin/auth/*.php          -> include: ../include/common_auth.php
admin/app/index.php       -> include: ../include/common.php
admin/api/index.php       -> include: ../include/common_api.php
admin/views/*.php         -> included BY admin/app/index.php (never directly)
admin/snippets/*.php      -> included by views and auth pages
(child-app)/index.php     -> include: ../admin/include/common.php
vendor/autoload.php       -> from admin/*: ../vendor/autoload.php

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

## Shard routing architecture
Main DB (wncore_main): auth only. user table holds user_id, email,
  password, shard_id, role flags. Never holds profile or app data.

Shard DBs (wncore_shard_1, wncore_shard_2): profile + child app data.
  user_profile table keyed by user_id from main DB.

Login flow:
  1. SELECT user_id, password, shard_id FROM user WHERE email = ?
  2. Verify → store user_id + shard_id in $_SESSION
  3. prime_shard($_SESSION['shard_id']) → open shard connection
  4. Load user_profile from shard

All subsequent requests: shard_id from session, prime_shard() → zero lookups.

New user: SELECT shard_id, COUNT(*) as cnt FROM user GROUP BY shard_id
          ORDER BY cnt ASC LIMIT 1 → assign least-loaded shard.

Email change: UPDATE user SET email=? on main DB only. Shard untouched.
shard_id NEVER changes after registration.

## Config file loading
common.php loads: __DIR__ . '/../config/config.php'
Falls back to getenv() for Docker/CI.
config.php is gitignored. config.sample.php is committed as reference.
$files_location must be absolute path with trailing slash.
$shardConfigs array maps shard names to connection details.

## User homedir system
create_home_dir_id($user_id) called ONCE on registration.
Stores absolute bucketed path in user_profile.homedir on the SHARD.
All subsequent access uses user_profile.homedir directly — no recomputation.
Files live at ../files/home/ (one level above webroot, absolute path in config).
Child apps use create_namespaced_dir($id, $namespace) for their own trees.

## Sensitive folders — protected by .htaccess deny (NOT above-webroot)
vendor/
db_migrations/
cron/
tests/
include/
config/
views/
snippets/

## View and snippet paths
Views: admin/views/ — loaded by admin/app/index.php via include()
Snippets: admin/snippets/ — included by views and auth pages
admin/uploads/ is WEB-ACCESSIBLE — logos, favicons, public binary assets only

## Docker vs shared hosting
Docker:  All config via environment variables. FILES_LOCATION=/var/files/
         Two shard containers: db_shard (shard1) + db_shard2 (shard2)
Hosting: admin/config/config.php with absolute $files_location path.
         ../files/ must be created manually by operator.

## Email queue system
Main DB stores: email_settings (SMTP config, throttle limits, allowed senders)
                email_queue (pending/sent/failed messages)

Sending: queue_email($to, $subject, $body, $opts) inserts into email_queue.
         cron/cron.php processes the queue respecting throttle limits.
         Child apps call queue_email() after including common.php — no SMTP config needed.

Admin UI: views/email.php — SMTP settings, throttle config, allowed senders, queue log.
Helpers: include/common/emailFunctions.php, emailQueueFunctions.php
Actions: include/actions/memberActions/emailSettingsActions.php

Throttle limits (configurable in admin):
  per_minute, per_hour, per_day — checked before each send batch.

Allowed senders: email_allowed_senders table. Only listed addresses can appear
  in the From header. Default from/reply-to configured in email_settings.

## Notification system
Main DB: notification_category (slug, name, icon, is_system, default_frequency)
Shard DB: notification (per-user), notification_preference, push_subscription

Key functions (available to child apps via common.php):
  send_notification($user_id, $shard_id, $category_slug, $title, $body, $opts)
  broadcast_notification($category_slug, $title, $body, $opts)
  register_notification_category($slug, $name, $desc, $opts)

Web Push: VAPID keys in config. Service worker at admin/sw.js.
  Realtime notifications send push immediately.
  Daily/weekly batched by cron/push_digest.php.

Helpers: include/common/notificationFunctions.php, pushFunctions.php
Actions: include/actions/memberActions/notificationActions.php,
         notificationAdminActions.php

## Cron jobs
All cron scripts: CLI-only, bootstrap via include/common_readonly.php.
common_readonly.php loads config + DB + helpers but NO actions, NO session guard.

Scripts:
  cron/cron.php                  — every minute: email queue + scheduled tasks
  cron/sync_users.php            — migration sync from external DB
  cron/push_digest.php           — daily push digest (weekly on Mondays)
  cron/cleanup_notifications.php — monthly: prune old notifications + stale subscriptions

Subdirectories (cron/minutes/, days/, weeks/, months/) hold task scripts
executed by cron.php on their respective intervals.

## Child app integration
Child apps reach core via:   ../admin/include/common.php
Child apps use assets via:   ../admin/assets/
Child apps define APP_MIGRATION_DIR before including common.php
Child apps start their own db_version and shard_version at 1.0
Child apps create their own DB and tables — never write to core tables
Child apps route to shard via: prime_shard($_SESSION['shard_id'])
  (shard_id is already in session from core's login — no extra lookup)

Child apps can use core services after including common.php:
  queue_email($to, $subject, $body)           — send email via core's queue
  send_notification($uid, $shard, ...)        — create user notification
  register_notification_category($slug, ...)  — register app-specific categories

## Active coding rules — follow when writing any code in this repo

DO:
- Escape out of PHP for HTML — never echo HTML from within PHP strings
- Use <?= $var ?> shorthand for output, always h() for user-supplied data
- Use sanitize($val, SQL) for any DB value not already sanitized
- Flat if($_POST['action'] == 'x') blocks in action files — no dispatcher
- Collect validation errors in $errs array before setting $_SESSION['error']
- Always set $_SESSION['success'] when an action completes successfully
- New helpers go in admin/include/common/ — glob picks them up automatically
- New actions go in admin/include/actions/[memberActions|apiActions|loginActions]/
- Migration files: decimal versioning (1.0, 1.1, 2.0), START TRANSACTION + COMMIT
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
- Store credentials anywhere except admin/config/config.php (gitignored)
- Create new API endpoint files — use action files instead
- Edit existing migration files — always create a new version
- Manually update the db_version table — the migration system handles it
- Set $_SESSION['error'] immediately on first validation failure (collect all errors first)

## Template pattern
<?php if ($someCondition) { ?>
<div class="something"><?= h($userData['field']) ?></div>
<?php } ?>

## Action file pattern
if ($_POST['action'] == 'addUser') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!$_POST['email'])      { $errs['email'] = 'Email required.'; }
    if (count($errs) <= 0) {
        // do the thing
        $_SESSION['success'] = 'User added.';
        $data['user_id'] = $newId; // optional: return data to JS
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

## API response format (from admin/api/index.php)
{
  "error":   "",
  "success": "Action completed.",
  "info":    "",
  "warning": "",
  "results": { /* $data array */ }
}

## Migration file template
-- Migration X.X for [Main/Shard] Database
-- Brief description of changes
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = X.X;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
-- SQL here
COMMIT;

## Specialized sub-CLAUDE.md files
db_migrations/CLAUDE.md            — migration version rules (read before any DB work)
admin/include/actions/CLAUDE.md    — action file patterns (read before any API work)

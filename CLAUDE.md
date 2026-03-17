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
(child-app)/app/index.php -> include: ../include/common.php (child's own bootstrap)
(child-app)/include/common.php -> include: ../../admin/include/common.php (core)
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
$files_location uses __DIR__-relative path: __DIR__ . '/../../../files/' (resolves to one level above public_html).
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
Branding uploads (logos, favicons, PWA icons) are stored in $files_location/branding/
  (outside webroot) and served via admin/branding.php proxy with .htaccess rewrite.
  URL pattern: /admin/branding/filename — e.g. /admin/branding/branding_logo.svg

## Docker vs shared hosting
Docker:  All config via environment variables. FILES_LOCATION=/var/files/
         Two shard containers: db_shard (shard1) + db_shard2 (shard2)
Hosting: admin/config/config.php with __DIR__-relative $files_location.
         ../files/ is auto-created on first request by bootstrap.

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

Daily (cron/days/1/):
  cleanup_error_log.php        — deletes error_log entries older than 30 days
  cleanup_expired_tokens.php   — deletes forgot tokens > 7 days, api_key > 90 days

Monthly (cron/months/1/):
  purge_deleted_users.php      — deletes read notifications > 90 days, orphaned devices

## Shared assets provided to child apps
Child apps reference core's CSS and JS via ../../admin/assets/ (never copy).

CSS: assets/css/style.css         — sidebar, navbar, layout, collapse behavior
     assets/css/bs-theme-overrides.css — dark/light mode Bootstrap overrides

JS:  assets/js/bs-init.js         — apiPost() AJAX helper, Bootstrap init
     assets/js/sidebar.js         — sidebar collapse/expand, mobile toggle, localStorage
     assets/js/color-mode.js      — dark/light mode toggle with localStorage
     assets/js/notifications.js   — notification bell polling, dropdown, mark-read, Web Push

Child apps add their own JS on top (modal.js, theme.js, toast.js, search.js, etc.)

## Child app integration
Child apps are separate repos deployed as siblings: public_html/your-app/
Each child app has its own include path (your-app/include/) with its own
common.php, helpers, actions, views, config, and db_migrations.

Bootstrap: your-app/include/common.php includes ../../admin/include/common.php
  which provides auth, session, helpers, shard routing, $db.
Child app then opens its own $child_db connection to its own database.

IMPORTANT: Child apps do NOT define APP_MIGRATION_DIR or set $db_version
  before including core's common.php. Admin runs its own migrations first.
  Child app runs its own migration functions afterward against $child_db.
Child apps start their own $child_db_version and $child_shard_version at 1.0.
Child apps create their own DB ($child_db) — never write to core tables.
Child apps have their own shard DBs ($childShardConfigs), separate from admin's.
Child apps route to shard via: child_prime_shard($_SESSION['shard_id'])
  (shard_id is already in session from core's login — no extra lookup)

Child apps can use core services after including common.php:
  queue_email($to, $subject, $body)           — send email via core's queue
  send_notification($uid, $shard, ...)        — create user notification
  register_notification_category($slug, ...)  — register app-specific categories

Reference implementation: github.com/WaveNetworks/child-app
  Demonstrates: glassmorphism SCSS theme, D3.js dashboard charts, DataTables,
  live search, AJAX modals, offcanvas panel, toasts, notifications, Docker env.

See: CHILD_APP_SPEC.md for full child app structure and patterns.

## Error logging system
Custom error/exception/shutdown handlers capture all PHP errors to the DB.
errorHandler.php registers three handlers via set_error_handler, set_exception_handler,
register_shutdown_function. Errors are classified: FATAL, ERROR, WARNING, INFO.
Falls back to error_log() if DB is unavailable or log_error_to_db() not yet loaded.
In production, errors are suppressed from display. In development (ENVIRONMENT=development),
errors render to screen.

DB table: error_log (main DB). Columns: error_id, level, message, file, line,
  stack_trace, context_json, source_app, page, request_uri, request_method,
  user_id, ip_address, user_agent, php_version, memory_usage, resolved_at,
  resolved_by, created.

log_error_to_db() deduplicates per-request via md5(file:line:message).
Detects source_app from file path (regex matches directory name before include/views/etc).
context_json captures: GET params, POST action name, session user info, memory stats.

Admin view: views/error_log.php (admin-only, page=error_log).
  Filterable by level, source_app, status (open/resolved), free-text search.
  Paginated, expandable detail rows with stack trace and context JSON.

Member actions (include/actions/memberActions/errorLogActions.php):
  getErrorLogs    — paginated list with filters, stats, sources
  deleteErrorLog  — delete single entry by error_id
  clearErrorLogs  — bulk delete older than N days (default 30)
  resolveErrorLog — mark resolved (sets resolved_at + resolved_by)
  unresolveErrorLog — reopen

API actions (include/actions/apiActions/errorLogApiActions.php):
  Authenticated via service API key (Bearer token). Scope-gated.
  apiGetErrorLogs     — list (scope: error_log:read)
  apiGetErrorLog      — single entry (scope: error_log:read)
  apiResolveErrorLog  — resolve (scope: error_log:write)
  apiUnresolveErrorLog — reopen (scope: error_log:write)
  apiGetErrorStats    — dashboard stats + source list (scope: error_log:read)

Cron: cron/days/1/cleanup_error_log.php — deletes entries older than 30 days.

Child app benefit: errors from child apps are captured automatically because
  errorHandler.php is loaded via common.php. source_app is auto-detected from
  the file path, so child app errors appear in the admin error log tagged by app name.

Helpers: include/common/errorHandler.php, include/common/errorLogFunctions.php

## Service API keys
Programmatic API access for external tools and agents. Separate from the
remember-me api_key table used for device cookies.

DB table: service_api_key (main DB). Columns: service_key_id, key_name,
  key_prefix, key_hash, scopes (JSON array), created_by, created_at,
  last_used_at, revoked_at, revoked_by.

Key format: wn_sk_ + 58-char random hash. Only the bcrypt hash is stored.
The full key is returned once at creation and never shown again.
Validation: prefix lookup (first 12 chars) narrows candidates, then bcrypt verify.
last_used_at updated on each successful validation.

Scopes: JSON array of scope strings. Available scopes defined in get_available_scopes():
  error_log:read, error_log:write, users:read.
  Child apps can extend by adding scopes to this function.
  require_api_scope($scope) checks the current key's scopes and sets $_SESSION['error']
  if missing. API action files call this before processing.

Authentication: Bearer token in Authorization header. common_api.php extracts
  the token, calls validate_service_api_key(), populates global $_SERVICE_API_KEY.

Admin view: views/api_keys.php (admin-only, page=api_keys).
  Lists keys with name, prefix, scopes, created/last-used timestamps, status.
  Create modal with name + scope checkboxes. Key revealed once after creation.
  Revoke button (soft delete via revoked_at).

Member actions (include/actions/memberActions/serviceApiKeyActions.php):
  createServiceApiKey — name + scopes[] required, returns full_key once
  revokeServiceApiKey — sets revoked_at, cannot be undone
  getServiceApiKeys   — list all keys (never returns key_hash)

Helpers: include/common/serviceApiKeyFunctions.php

## MCP server
JSON-RPC 2.0 over stdio server for AI agent integration.
Located at: admin/mcp/server.php

Does NOT include common.php — wraps the admin HTTP API via cURL.
Authenticates using a service API key (Bearer token).

Environment variables:
  WN_API_URL — Admin API endpoint (default: http://localhost/admin/api/index.php)
  WN_API_KEY — Service API key (needs error_log:read + error_log:write scopes)

Tools exposed:
  list_errors    — paginated error list. Params: page, per_page, level, source_app, search, status
  get_error      — single error entry. Params: error_id (required)
  resolve_error  — mark resolved. Params: error_id (required)
  unresolve_error — reopen. Params: error_id (required)
  get_error_stats — dashboard stats (no params)

Each tool maps to an apiAction: list_errors -> apiGetErrorLogs,
  get_error -> apiGetErrorLog, resolve_error -> apiResolveErrorLog,
  unresolve_error -> apiUnresolveErrorLog, get_error_stats -> apiGetErrorStats.

Claude Desktop config:
  "mcpServers": { "wave-networks-admin": {
    "command": "php",
    "args": ["/path/to/admin/mcp/server.php"],
    "env": { "WN_API_URL": "...", "WN_API_KEY": "wn_sk_..." }
  }}

Logs to stderr via mcp_log(). Warns if WN_API_KEY is unset.

## Theme management
Bootswatch themes + registered custom themes. Theme stored in cookie (wn_theme)
so PHP can render correct stylesheet on first paint — no FOUC.

Built-in themes: 25 Bootswatch themes (cerulean, cosmo, cyborg, darkly, flatly,
  journal, litera, lumen, lux, materia, minty, morph, pulse, quartz, sandstone,
  simplex, sketchy, slate, solar, spacelab, superhero, united, vapor, yeti, zephyr).
  Default: sandstone. Loaded from jsDelivr CDN.

Custom theme registration (for child apps):
  register_theme($slug, $name, $css_path, $opts)
  $opts: sidebar_mode (dark|glass), created_by_app, is_active
  DB table: registered_theme (main DB). Columns: slug (unique), name, css_path,
    sidebar_mode, created_by_app, is_active. Uses ON DUPLICATE KEY UPDATE.
  Child apps call register_theme() at bootstrap to add app-specific themes.

Theme resolution: get_active_theme() reads wn_theme cookie, validates against
  Bootswatch allowed list + registered_theme table. Falls back to sandstone.
  get_theme_css_url($prefix, $webroot_prefix) returns CDN URL for Bootswatch
  or relative path for registered themes.

JS: assets/js/theme.js
  Populates <select id="themeSelector"> from Bootswatch API + registered themes
  (registered themes passed via data-registered-themes attribute as JSON).
  On change: saves to localStorage + cookie, swaps stylesheet link href.
  Custom themes appear under a "Custom" separator in the dropdown.
  Falls back gracefully if Bootswatch API is down or theme CSS fails to load.

Helpers: include/common/themeFunctions.php, include/common/themeRegistrationFunctions.php

## GDPR compliance system
Admin core provides all GDPR infrastructure. Child apps consume it via shared
helper functions loaded through common.php. All GDPR data lives in the admin
main DB so it's shared across all child apps.

### Database tables (main DB, migrations 2.5 + 2.6)

**consent_version** — versioned legal documents
| Column | Type | Notes |
|--------|------|-------|
| version_id | INT PK AUTO_INCREMENT | |
| consent_type | VARCHAR(50) | e.g. terms_of_service, privacy_policy, marketing_email |
| version_label | VARCHAR(50) | e.g. "1.0", "2.0" |
| effective_date | DATE | When this version takes effect |
| document_url | VARCHAR(500) NULL | Link to full document |
| summary | TEXT NULL | Short description shown to users |
| content | LONGTEXT NULL | Full legal text (for inline display) |
| is_active | TINYINT(1) DEFAULT 1 | Whether this version is current |
| created | DATETIME | |

Seeded with 5 initial types: terms_of_service, privacy_policy, marketing_email,
cookie_analytics, cookie_marketing (all version 1.0).

**user_consent** — immutable audit log of consent events
| Column | Type | Notes |
|--------|------|-------|
| consent_id | BIGINT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | |
| consent_type | VARCHAR(50) | |
| consent_version_id | INT NULL | FK to consent_version |
| action | ENUM('granted','withdrawn') | |
| ip_address | VARCHAR(45) NULL | |
| user_agent | VARCHAR(512) NULL | |
| created | DATETIME | |

NEVER update or delete rows — this is an append-only audit trail.

**account_deletion_request** — GDPR Article 17 right to erasure
| Column | Type | Notes |
|--------|------|-------|
| request_id | INT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | Uniqueness enforced in application code |
| reason | TEXT NULL | Optional user-provided reason |
| status | ENUM('pending','completed','cancelled') | |
| requested_at | DATETIME | |
| cancel_before | DATETIME | 30 days after requested_at |
| cancelled_at | DATETIME NULL | Set when request is cancelled |
| completed_by | VARCHAR(50) NULL | Who completed the deletion |
| completed_at | DATETIME NULL | |

**data_export_request** — GDPR Article 20 data portability
| Column | Type | Notes |
|--------|------|-------|
| export_id | INT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | |
| format | ENUM('json','csv') DEFAULT 'json' | |
| status | ENUM('pending','processing','ready','expired') | |
| file_path | VARCHAR(500) NULL | Server path to export file |
| file_size | INT UNSIGNED NULL | |
| requested_at | DATETIME | |
| completed_at | DATETIME NULL | |
| expires_at | DATETIME NULL | 7 days after completion |

**login_history** — tracks every login attempt (migration 2.6)
| Column | Type | Notes |
|--------|------|-------|
| history_id | BIGINT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | |
| ip_address | VARCHAR(45) NULL | |
| user_agent | VARCHAR(512) NULL | |
| browser | VARCHAR(100) NULL | Parsed from user agent |
| login_method | ENUM('password','oauth','remember_me','saml','2fa') | |
| status | ENUM('success','failed') | |
| created | DATETIME | |

**device table upgrades** (migration 2.6) — added columns:
  user_id INT UNSIGNED, browser VARCHAR(100), last_used DATETIME, idx_user_id

### Helper functions

Helpers: include/common/gdprFunctions.php
  record_consent($user_id, $consent_type, $action, $version_id) — append to audit log
  get_consent_status($user_id, $consent_type) — latest status: 'granted', 'withdrawn', or null
  get_all_consent_statuses($user_id) — all types with current status
  get_consent_history($user_id) — full audit trail with version labels
  get_latest_consent_version($consent_type) — latest version row for a type
  get_all_consent_versions() — all types with their latest versions
  request_account_deletion($user_id, $reason) — creates pending request, 30-day cancel_before
  cancel_account_deletion($user_id) — cancels pending request
  get_pending_deletion($user_id) — returns active pending deletion or null
  request_data_export($user_id, $format) — creates export request (blocks duplicates)
  get_latest_export($user_id) — latest export request
  build_export_data($user_id, $shard_id) — collects user data from admin main+shard
  complete_data_export($export_id, $file_path, $file_size) — marks ready, 7-day expiry

Helpers: include/common/loginHistoryFunctions.php
  record_login($user_id, $method, $status) — insert login_history row
  get_login_history($user_id, $limit, $offset) — paginated history
  count_login_history($user_id) — total count
  check_reconsent_needed($user_id) — checks if user must re-accept terms_of_service
    or privacy_policy; returns array of types needing re-consent with version info

Helpers: include/common/deviceFunctions.php (rewritten for session management)
  parse_browser_name($ua) — extracts browser name from user agent
  register_device($cookie_id, $user_id) — stores browser, last_used
  touch_device($device_id) — updates last_used timestamp
  get_user_devices($user_id) — lists all devices with api_key join
  revoke_device($device_id, $user_id) — deletes device + api_key (user-scoped)
  revoke_all_other_devices($user_id, $current_cookie_id) — revokes all except current

### Login flow integration
loginActions.php modifications:
  LOGIN: records login history (success/failed), registers device with user_id,
    calls check_reconsent_needed() → redirects to auth/consent.php if needed.
  REGISTER: validates agree_terms checkbox, records consent for ToS + Privacy Policy.
  VERIFY 2FA: records login history, checks re-consent.

### Re-consent flow
When consent_version gets a new row for terms_of_service or privacy_policy,
check_reconsent_needed() detects users who haven't accepted the latest version.
On next login, they're redirected to auth/consent.php which displays updated
policies and requires acceptance before entering the app.

Auth pages: auth/consent.php (admin), auth/consent.php (child-app — same UI,
  child-app template). Action: consentActions.php → acceptReconsent.

### Child app usage
Child apps get all GDPR functions for free via common.php include chain.
Child apps build their own Privacy & Data UI (views/privacy.php) calling
the shared helper functions. Child apps add app-specific data to exports
(items, preferences, history) on top of admin's build_export_data() base.

### Admin compliance actions
Action file: include/actions/memberActions/userComplianceActions.php
  adminResetPassword     — reset user password with optional email notification
  adminRevokeSession     — revoke a single device session for a user
  adminRevokeAllSessions — revoke all device sessions for a user
  adminCancelDeletion    — cancel a pending account deletion request

Admin UI: views/user_edit.php — 5-tab compliance dashboard (Profile, Consent,
  Login History, Sessions, Data & Deletion) accessed from Users list.

## Branding settings
Admin UI: views/settings.php — branding tab.
Form field names (important for programmatic updates):
  logo          — Logo file upload (navbar, login page)
  logo_dark     — Dark mode logo variant
  favicon       — Favicon file upload
  site_name     — Display name shown in navbar and page titles
  site_short_name — Short name for PWA manifest and browser tabs
  site_description — Site description for meta tags
  theme_color   — Hex color for PWA manifest and browser chrome

Storage: auth_settings table in main DB (key-value pairs).
Access: get_branding() returns all values. Available to child apps and
  websites via common_auth.php (no session required).

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

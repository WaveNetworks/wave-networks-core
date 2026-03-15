# Child App Specification — wave-networks-core

## What a child app is
A separate git repo deployed as a sibling of /admin/ in the same webroot.
Uses core for auth, session, shared helpers, shard routing, email, and notifications.
Has its own databases, own shards, own migrations, own views, own config, and own include path.
Also has access to core's $db (main DB, auth only) via the inherited connection.

## Reference implementation
github.com/WaveNetworks/child-app
Demonstrates every pattern listed here in working code.
When in doubt, match its structure exactly.

## Deployment layout
```
public_html/                       <- webroot (NOT a repo)
  admin/                           <- wave-networks-core repo
    app/index.php
    include/common.php
    ...
  your-app/                        <- child app repo (separate git repo)
    auth/                          <- branded auth pages (login, register, etc.)
      login.php
      register.php
      template.php                 <- auth page shell (branded)
    app/index.php                  <- controller (routes ?page= to views/)
    api/index.php                  <- AJAX-only JSON endpoint
    include/
      common.php                   <- child app bootstrap (see below)
      common_auth.php              <- auth page bootstrap (no session guard)
      common_api.php               <- API bootstrap (mirrors core pattern)
      definition.php               <- child app variable pre-declarations
      common/                      <- child app helper functions (glob-included)
        childDBFunctions.php       <- $child_db + child shard wrappers
        appMigrationFunctions.php  <- migration runner for child DBs
        appFunctions.php           <- app-specific helpers
      actions/
        memberActions/             <- authenticated actions
        apiActions/                <- public/AJAX actions
    views/                         <- DENIED by .htaccess
    snippets/                      <- DENIED by .htaccess
    config/
      config.php                   <- GITIGNORED — child app DB credentials ONLY
      config.sample.php            <- committed template
    uploads/                       <- web-accessible binary assets
    db_migrations/
      main/
        1.0.sql                    <- child app's OWN database tables
      shard/
        1.0.sql                    <- tables for child app's OWN shard DBs
      seeds/
        dev_seed.sql
    assets/                        <- child app CSS/JS (optional, app-specific only)
      scss/                        <- SCSS source for custom Bootstrap themes
      css/                         <- compiled CSS output
    CLAUDE.md
    .htaccess
    .gitignore
```

## Bootstrap pattern (your-app/include/common.php)

IMPORTANT: Do NOT define APP_MIGRATION_DIR or set $db_version/$shard_version
before including admin's common.php. Admin must run its own migrations first
against its own database. Child app runs its own migrations separately afterward.

```php
<?php
// 1. Include admin core — provides: autoload, admin config, $db, helpers,
//    admin migrations, session guard, definition.php, admin action files.
//    Admin loads its own config/config.php (or env vars). We don't touch it.
include(__DIR__ . '/../../admin/include/common.php');

// 2. Load child app's own config (only child DB credentials)
$childConfigFile = __DIR__ . '/../config/config.php';
if (file_exists($childConfigFile)) {
    include($childConfigFile);
} else {
    // Docker / CI: read child app DB from environment
    $child_db_host = getenv('CHILD_DB_HOST') ?: 'db_child';
    $child_db_name = getenv('CHILD_DB_NAME') ?: 'childapp_main';
    $child_db_user = getenv('CHILD_DB_USER') ?: getenv('DB_USER') ?: 'root';
    $child_db_pass = getenv('CHILD_DB_PASS') ?: getenv('DB_PASSWORD') ?: '';

    $childShardConfigs = [];
    // ... build from CHILD_DB_HOST_SHARD, CHILD_DB_HOST_SHARD2 env vars
}

// 3. Open child app's own main database
$child_db = new PDO(
    "mysql:host=$child_db_host;dbname=$child_db_name;charset=utf8mb4",
    $child_db_user, $child_db_pass
);
$child_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 4. Include child DB helpers and migration functions
include_once(__DIR__ . '/common/childDBFunctions.php');
include_once(__DIR__ . '/common/appMigrationFunctions.php');

// 5. Run child app migrations (independent of admin's versions)
$child_db_version    = 1.0;
$child_shard_version = 1.0;
child_check_and_migrate($child_db, 'main', $child_db_version, __DIR__ . '/../db_migrations/');
child_check_and_migrate_shards($child_shard_version, __DIR__ . '/../db_migrations/');

// 6. Child app definition.php
include(__DIR__ . '/definition.php');

// 7. Glob-include child app helpers and actions
foreach (glob(__DIR__ . '/common/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/actions/memberActions/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/actions/apiActions/*.php') as $f) { include_once($f); }
```

### Auth page bootstrap (your-app/include/common_auth.php)
Same pattern but includes admin's `common_auth.php` instead (no session guard):
```php
include(__DIR__ . '/../../admin/include/common_auth.php');
// ... same child config + DB + migrations + helpers
```
Auth pages in your-app/auth/ use this bootstrap. Admin's loginActions are
auto-included by admin's common_auth.php — no duplication needed.

### API bootstrap (your-app/include/common_api.php)
```php
header('Content-Type: application/json; charset=utf-8');
include(__DIR__ . '/../../admin/include/common_api.php');
// ... same child config + DB + migrations + helpers
```

## What you have after bootstrap
```
$db                              core main DB (user, device, api_key — READ ONLY)
$child_db                        child app's own main DB — all app tables live here
$childShardConfigs               child app's own shard connection configs
child_db_query($sql)             query child app's main DB
child_db_query_prepared($sql, $p) prepared query on child main DB
child_prime_shard($shard_id)     opens child shard connection on demand
child_db_query_shard($s, $sql)   query child app's own shard tables
$_SESSION['user_id']             authenticated user (set by core login)
$_SESSION['shard_id']            which shard this user lives on
prime_shard($shard_id)           opens CORE shard connection (user_profile etc.)
db_query_shard($shard, $sql)     query core shard tables (READ ONLY recommended)
All core helpers                 h(), sanitize(), db_query(), db_fetch(), etc.
Session guard                    auto-redirects to ../auth/login.php
Email service                    queue_email($to, $subject, $body, $opts)
Notification service             send_notification(), register_notification_category()
```

## Database architecture for child apps
Child apps have THREE database layers:

1. **Core main DB** ($db) — READ ONLY from child apps.
   Contains: user, device, api_key, email_queue, notification_category, etc.
   Child apps NEVER write to these tables.

2. **Child app's own main DB** ($child_db) — child app's private tables.
   Created by child app's db_migrations/main/ SQL files.
   Child app has full read/write access.
   Name your DB something like `yourapp_main`. NEVER reuse core's DB name.

3. **Child app's own shard DBs** — per-user data stored in child's own shards.
   Child apps have their OWN shard databases (e.g. `yourapp_shard_1`, `yourapp_shard_2`),
   completely separate from admin's shards. Created by db_migrations/shard/ SQL files.
   Users are assigned to shards by admin during registration (shard_id in session).
   Child app maps the same shard_id to its own shard databases via $childShardConfigs.
   Access via: `child_prime_shard($shard_id); child_db_query_shard($shard, $sql);`

   To read from admin's shard tables (e.g. user_profile), use admin's functions:
   `prime_shard($shard_id); db_query_shard($shard, $sql);`

### Child app migration rules
- Same two-step rule as core: SQL file + version bump.
- CRITICAL: update $child_db_version / $child_shard_version in YOUR-APP/include/common.php.
- Child app versions start at 1.0 and are independent of core's versions.
- Child app shard migrations run against CHILD'S OWN shard DBs, not admin's.
- Use CREATE TABLE IF NOT EXISTS (idempotent).
- NEVER alter or drop core tables from child app code.

## Child app DB wrapper functions
Child apps define their own DB wrapper functions in include/common/childDBFunctions.php:
```
child_db_query($sql)                          — query child's main DB
child_db_query_prepared($sql, $params)        — prepared query on child main DB
child_db_insert_id()                          — last insert ID on child main DB
child_prime_shard($shard_id)                  — open/cache child shard connection
child_db_query_shard($shard_id, $sql)         — query child's shard DB
child_db_query_shard_prepared($shard_id, $sql, $params) — prepared shard query
child_shard_insert_id($shard_id)              — last insert ID on child shard
```

## Shard queries
```php
$shard = $_SESSION['shard_id'];

// Read from ADMIN's shard table (user_profile — read only)
prime_shard($shard);
$r = db_query_shard($shard,
    "SELECT * FROM user_profile WHERE user_id = '" . $_SESSION['user_id'] . "'");

// Read/write CHILD APP's own shard table
child_prime_shard($shard);
$r = child_db_query_shard_prepared($shard,
    "SELECT * FROM user_history WHERE user_id = ?", [$_SESSION['user_id']]);
```

## Branded auth pages
Child apps include their own auth/ directory with branded copies of login,
register, forgot, reset, 2fa, confirm, and OAuth/SAML callback pages.
Each page includes `../include/common_auth.php` (child's bootstrap) and
`./template.php` (child's auth shell). All auth ACTIONS (login, register,
forgot password, etc.) are handled by admin's loginActions — auto-included
by admin's common_auth.php. No action duplication needed in child apps.

The auth template uses `get_branding()` from admin for shared branding
(site name, logo, favicon, theme color) by default. Child apps can override
with their own branding if needed.

## Action files — two invocation methods
Same pattern as core. Action files in your-app/include/actions/ are glob-included
by your bootstrap and run on every request before views render.

### 1. Plain form POST (preferred for most forms)
```html
<form method="post">
    <input type="hidden" name="action" value="saveItem">
    <!-- fields -->
    <button type="submit">Save</button>
</form>
```
Form posts to current URL. Action runs, sets session flash, view re-renders.
No JS needed. No action attribute on the form tag.

### 2. AJAX via apiPost() (for inline UI updates)
```javascript
apiPost('getItems', { page: 1, filter: 'active' }, function(data) {
    // update UI inline
});
```
Posts to your-app/api/index.php. Returns JSON.
Use for: mark read, delete row, live search, load more.

### WRONG patterns
- `<form action="../api/index.php">` — navigates to raw JSON
- `<form action="your-app/api/index.php">` — same problem
- Creating new endpoint PHP files — use action files instead

## Shared assets — reference from core, never copy

### CSS (loaded in `<head>`)
```html
<link rel="stylesheet" href="../../admin/assets/css/style.css">
<link rel="stylesheet" href="../../admin/assets/css/bs-theme-overrides.css">
<link rel="stylesheet" href="<?= h(get_app_theme_css_url()) ?>" id="themeStylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
```

| File | Purpose |
|------|---------|
| `style.css` | Sidebar, navbar, layout structure, sidebar collapse/expand, badge hide on collapse |
| `bs-theme-overrides.css` | Dark/light mode overrides for Bootstrap components |

### JS (loaded in footer, after Bootstrap bundle)
```html
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../admin/assets/js/bs-init.js"></script>
<script src="../../admin/assets/js/sidebar.js"></script>
<script src="../../admin/assets/js/color-mode.js"></script>
<script src="../../admin/assets/js/notifications.js"></script>
<!-- Child app-specific JS after this -->
```

| File | Purpose |
|------|---------|
| `bs-init.js` | `apiPost(action, data, callback)` AJAX helper, Bootstrap init, `modalView()` support |
| `sidebar.js` | Sidebar collapse/expand, mobile toggle, localStorage persistence |
| `color-mode.js` | Dark/light mode toggle with localStorage and system preference detection |
| `notifications.js` | Notification bell polling, dropdown rendering, mark-read, Web Push registration |

Child app-specific CSS/JS goes in your-app/assets/.

## SCSS theme customization
Child apps can compile custom Bootstrap themes from SCSS:
```
assets/scss/custom.scss          — main entry (imports _variables + bootstrap)
assets/scss/_variables.scss      — Bootstrap variable overrides
assets/scss/themes/my-theme/     — full Bootswatch-style theme
```
Build: `npm run build:css` compiles custom.scss to assets/css/custom.css.
The compiled CSS is loaded by template.php if the file exists.

## Sending email from a child app
Core provides a shared email queue. Child apps never configure SMTP themselves.

```php
queue_email($to, $subject, $html_body, $options);
```
$options (all optional):
  - `'from_email'` => must be in core's allowed senders list
  - `'from_name'`  => display name
  - `'reply_to'`   => reply-to address
  - `'source_app'` => 'your-app' (for queue log filtering)

Emails are queued in core's main DB and sent by core's cron runner
with throttle limits. No SMTP config needed in the child app.

## Sending notifications from a child app
Step 1: Register your app's notification categories at bootstrap.
  Call this once (idempotent — safe to call on every request):
```php
register_notification_category('your-app-alerts', 'Your App Alerts',
    'Important alerts from Your App', [
        'icon' => 'bi-bell',
        'default_frequency' => 'realtime',
        'created_by_app' => 'your-app',
    ]);
```

Step 2: Send notifications when events occur:
```php
$uid   = $_SESSION['user_id'];
$shard = $_SESSION['shard_id'];
send_notification($uid, $shard, 'your-app-alerts', 'Title', 'Body', [
    'action_url'   => '../your-app/app/index.php?page=detail&id=123',
    'action_label' => 'View Details',
    'source_app'   => 'your-app',
]);
```

Step 3: Broadcast to all users (admin action):
```php
broadcast_notification('your-app-alerts', 'Title', 'Body', $opts);
```

Users control frequency and push preferences per category from core's UI.
System categories (is_system=1) cannot be turned off by users.

## Docker development
Child app's docker-compose.yml runs 8 services:
- `app` — PHP 8.2 Apache with both /admin/ and /child-app/ volume-mounted
- `db`, `db_shard`, `db_shard2` — admin core databases
- `db_child`, `db_child_shard`, `db_child_shard2` — child app's own databases
- `mailhog` — SMTP testing

Source code is volume-mounted for live reload — edit PHP, refresh browser.
Admin repo must be checked out as a sibling directory.

Child app config is read from environment variables (CHILD_DB_HOST, etc.)
when config.php doesn't exist. Admin's env vars (DB_HOST_MAIN, etc.) are
also set because admin's bootstrap reads them when its config.php is absent.

## .htaccess (child app needs its own — does NOT inherit core's)
Deny: include/, config/, views/, snippets/, db_migrations/

## What NOT to do
- Name your DB connection $db — use $child_db
- Define APP_MIGRATION_DIR before including admin's common.php
- Set $db_version or $shard_version before including admin's common.php
- Write to core tables (user, device, api_key, email_queue, etc.) from child app code
- Copy admin/assets/ — always reference via ../../admin/assets/
- Define your own session guard — core handles it
- Put child app credentials in core's admin/config/config.php
- Put core credentials in child app's config.php — admin loads its own
- Create new API endpoint files — use action files in your-app/include/actions/
- Use `<form action="...api/index.php">` — post to self instead
- Alter or drop core tables in child app migrations
- Reuse core's database name for your app DB
- Write to admin's shard tables from child app code

## SCSS theme customization
Child apps compile custom Bootstrap themes from SCSS:
```
assets/scss/
├── custom.scss          Entry point (imports _variables → bootstrap → _bootswatch)
├── _variables.scss      Bootstrap variable overrides (colors, fonts, spacing)
├── _bootswatch.scss     Theme overrides (glassmorphism, dark/light mode, all components)
└── themes/              Additional Bootswatch-style theme variants
```

Build: `npm run build:css` compiles to assets/css/custom.css.
Watch: `npm run watch` for live recompilation during development.

The reference child-app includes a glassmorphism theme with:
- `glass($bg, $blur)` mixin for backdrop-filter effects on cards, modals, navbar, etc.
- Full dark mode (base) + light mode (`[data-bs-theme="light"]`) overrides
- Styled DataTables controls, D3 chart containers, search bar, offcanvas panel

## Child app JS patterns

Child apps typically add their own JS files for app-specific behavior:

| Script | Purpose |
|--------|---------|
| `modal.js` | `modalView(params)` and `reloadModalThisForm(formId, params)` for AJAX modals |
| `theme.js` | Bootswatch theme switcher (reads theme list from CDN) |
| `toast.js` | Reads `#toast-data` div and shows Bootstrap toasts from session flash messages |
| `search.js` | Debounced live search in topnav (300ms, 2 char min, desktop + mobile) |
| `page-nav.js` | Page navigation helpers |
| `bg-canvas.js` | Background canvas rendering for gamified/animated apps |

## Template shell features

The reference child-app template (`views/template.php`) provides a complete application shell:

- **Sidebar** — collapsible nav with icon+text links, badges (`.sidebar-badge` auto-hides on collapse), brand logo from `get_branding()`
- **Top navigation** — live search (debounced AJAX), notification bell, color mode toggle, theme switcher, user dropdown, right panel trigger (vertical dots icon, rightmost)
- **Right panel** — offcanvas-end with tabbed content (Bootstrap `data-bs-toggle="offcanvas"`, no custom JS needed)
- **Footer** — sticky to bottom via flexbox (`min-vh-100` + `flex-grow-1` + `margin-top: auto`), Terms/Privacy links, copyright
- **Canvas** — `#bgCanvas` fixed background for animations
- **Toast** — session-based flash messages (`$_SESSION['success']`, `error`, `warning`, `info`) rendered as Bootstrap toasts
- **Large modal** — `#largeModal` container for AJAX content loaded by `modalView()`

## UI patterns — three tiers of reference
Tier 1: admin/views/template.php      — annotated shell, copy for new apps
Tier 2: admin/views/ui_components.php — full component catalogue, every pattern
Tier 3: admin/views/*.php             — normal pages showing realistic combos

Reference child-app pages:
- **Dashboard** (`?page=dashboard`) — hero box, breadcrumb, D3.js line chart + donut chart
- **Items** (`?page=items`) — CRUD with status badges, inline editing via AJAX modals
- **Examples** (`?page=examples`) — DataTables (Bootstrap 5 + Responsive), modal demos, tabbed cards
- **UI Guide** (`?page=ui-guide`) — every Bootstrap 5 component with the active theme

See: github.com/WaveNetworks/child-app

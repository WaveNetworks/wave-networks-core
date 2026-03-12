# Child App Specification — wave-networks-core

## What a child app is
A separate git repo deployed as a sibling of /admin/ in the same webroot.
Uses core for auth, session, shared helpers, shard routing, email, and notifications.
Has its own database, own migrations, own views, own config, and own include path.
Also has access to core's $db (main DB, auth only) via the inherited connection.

## Reference implementation
github.com/WaveNetworks/wave-networks-sample
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
    app/index.php                  <- controller (routes ?page= to views/)
    include/
      common.php                   <- child app bootstrap (see below)
      common_api.php               <- API bootstrap (mirrors core pattern)
      definition.php               <- child app constants
      common/                      <- child app helper functions (glob-included)
      actions/
        memberActions/             <- authenticated actions
        apiActions/                <- public/AJAX actions
    views/                         <- DENIED by .htaccess
    snippets/                      <- DENIED by .htaccess
    config/
      config.php                   <- GITIGNORED — child app DB credentials
      config.sample.php            <- committed template
    uploads/                       <- web-accessible binary assets
    db_migrations/
      main/
        1.0.sql                    <- child app's OWN database tables
      shard/
        1.0.sql                    <- tables added to shared shard DBs
      seeds/
        dev_seed.sql
    api/
      index.php                    <- AJAX-only JSON endpoint (mirrors admin/api/)
    assets/                        <- child app CSS/JS (optional, app-specific only)
    CLAUDE.md
    .htaccess
    .gitignore
```

## Bootstrap pattern (your-app/include/common.php)
```php
<?php
// 1. Load child app's own config
include(__DIR__ . '/../config/config.php');

// 2. Tell core where this app's migrations live and what version we're at
define('APP_MIGRATION_DIR', __DIR__ . '/../db_migrations/');
$db_version    = 1.0;   // child app's main DB version (independent of core)
$shard_version = 1.0;   // child app's shard version (independent of core)

// 3. Include core — provides auth, session, helpers, shard routing, $db
include(__DIR__ . '/../../admin/include/common.php');

// 4. Open child app's OWN database connection
$app_db = new PDO(
    "mysql:host=$app_db_host;dbname=$app_db_name;charset=utf8mb4",
    $app_db_user, $app_db_pass
);
$app_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 5. Glob-include child app's own helpers and actions
foreach (glob(__DIR__ . '/common/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/actions/memberActions/*.php') as $f) { include_once($f); }
foreach (glob(__DIR__ . '/actions/apiActions/*.php') as $f) { include_once($f); }
```

## What you have after bootstrap
```
$db                         core main DB (user, device, api_key — READ ONLY)
$app_db                     child app's own DB — all app tables live here
$_SESSION['user_id']        authenticated user (set by core login)
$_SESSION['shard_id']       which shard this user lives on
prime_shard($shard_id)      opens shard connection on demand
db_query_shard($shard, $sql) query shared shard tables
All core helpers            h(), sanitize(), db_query(), db_fetch(), etc.
Session guard               auto-redirects to ../admin/auth/login.php
Email service               queue_email($to, $subject, $body, $opts)
Notification service        send_notification(), register_notification_category()
```

## Database architecture for child apps
Child apps have THREE database layers:

1. **Core main DB** ($db) — READ ONLY from child apps.
   Contains: user, device, api_key, email_queue, notification_category, etc.
   Child apps NEVER write to these tables.

2. **Child app's own DB** ($app_db) — child app's private tables.
   Created by child app's db_migrations/main/ SQL files.
   Child app has full read/write access.
   Name your DB something like `yourapp_main`. NEVER reuse core's DB name.

3. **Shared shard DBs** (via db_query_shard()) — per-user data.
   Core shards already contain: user_profile, notification, push_subscription, etc.
   Child apps ADD their own tables to these shared shards via db_migrations/shard/.
   Example: a coaching app adds `coaching_session` table to each shard.
   Access via: `prime_shard($_SESSION['shard_id']); db_query_shard($shard, $sql);`

### Child app migration rules
- Same two-step rule as core: SQL file + version bump.
- CRITICAL: update $db_version / $shard_version in YOUR-APP/include/common.php, NOT core's.
- Child app versions start at 1.0 and are independent of core's versions.
- Child app shard migrations add tables to existing shared shard DBs.
- Use CREATE TABLE IF NOT EXISTS (idempotent).
- NEVER alter or drop core tables from child app migrations.

## Shard queries
```php
$shard = $_SESSION['shard_id'];
prime_shard($shard);

// Read from core's shard table (user_profile)
$r = db_query_shard($shard,
    "SELECT * FROM user_profile WHERE user_id = '" . $_SESSION['user_id'] . "'");

// Read/write child app's own shard table
$r = db_query_shard($shard,
    "SELECT * FROM coaching_session WHERE user_id = '" . $_SESSION['user_id'] . "'");
```

## Action files — two invocation methods
Same pattern as core. Action files in your-app/include/actions/ are glob-included
by your bootstrap and run on every request before views render.

### 1. Plain form POST (preferred for most forms)
```html
<form method="post">
    <input type="hidden" name="action" value="saveCoachingSession">
    <!-- fields -->
    <button type="submit">Save</button>
</form>
```
Form posts to current URL. Action runs, sets session flash, view re-renders.
No JS needed. No action attribute on the form tag.

### 2. AJAX via apiPost() (for inline UI updates)
```javascript
apiPost('markSessionComplete', { session_id: 123 }, function(data) {
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
```html
<link rel="stylesheet" href="../admin/assets/css/style.css">
<link rel="stylesheet" href="../admin/assets/css/bs-theme-overrides.css">
<script src="../admin/assets/js/bs-init.js"></script>
<script src="../admin/assets/js/theme.js"></script>
```
Use the theme CSS URL helper: `<?= h(get_theme_css_url()) ?>`
Child app-specific CSS/JS goes in your-app/assets/.

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

## .htaccess (child app needs its own — does NOT inherit core's)
Deny: include/, config/, views/, snippets/, db_migrations/

## What NOT to do
- Name your DB connection $db — use $app_db
- Write to core tables (user, device, api_key, email_queue, etc.) from child app code
- Copy admin/assets/ — always reference via ../admin/assets/
- Define your own session guard — core handles it
- Put child app credentials in core's admin/config/config.php
- Create new API endpoint files — use action files in your-app/include/actions/
- Use `<form action="...api/index.php">` — post to self instead
- Alter or drop core tables in child app migrations
- Reuse core's database name for your app DB

## UI patterns — three tiers of reference
Tier 1: admin/views/template.php      — annotated shell, copy for new apps
Tier 2: admin/views/ui_components.php — full component catalogue, every pattern
Tier 3: admin/views/*.php             — normal pages showing realistic combos
See: github.com/WaveNetworks/wave-networks-sample

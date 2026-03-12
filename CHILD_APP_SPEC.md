# Child App Specification — wave-networks-core

## What a child app is
A separate git repo deployed as a sibling of /admin/ in the same webroot.
Uses core for auth, session, shared helpers, and shard routing.
Has its own database, own migrations, own views, and own config.
Also has access to core's $db (user data) via the inherited connection.

## Reference implementation
github.com/WaveNetworks/wave-networks-sample
Demonstrates every pattern listed here in working code.
When in doubt, match its structure exactly.

## Folder structure
(webroot)/
  admin/                   <- wave-networks-core
  your-app/                <- this child app (separate repo)
    config/
      config.php           <- GITIGNORED — child app DB credentials only
      config.sample.php    <- committed template
    include/
      common.php           <- child app bootstrap (see pattern below)
      common_api.php
      common_auth.php      <- only if child app has extra auth pages
      definition.php
      common/
      actions/
        memberActions/
        apiActions/
    views/                 <- DENIED by .htaccess
    snippets/              <- DENIED by .htaccess
    app/
      index.php            <- controller only
    api/
      index.php
    uploads/               <- web-accessible
    db_migrations/
      main/
        1.0.sql
      shard/
        1.0.sql            <- if child app adds shard tables
      seeds/
        dev_seed.sql
    CLAUDE.md
    .htaccess
    .gitignore

## Bootstrap pattern (your-app/include/common.php)
include(__DIR__ . '/../config/config.php');          // child app DB creds
define('APP_MIGRATION_DIR', __DIR__ . '/../db_migrations/');
$db_version    = 1.0;
$shard_version = 1.0;
include(__DIR__ . '/../../admin/include/common.php'); // core does the rest
$app_db = new PDO("mysql:host=$app_db_host;dbname=$app_db_name;charset=utf8mb4",
    $app_db_user, $app_db_pass);
$app_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

## What you have after bootstrap
$db                         core main DB (user, device, api_key, etc.)
$app_db                     child app's own DB — NEVER name your DB $db
$_SESSION['user_id']        authenticated user
$_SESSION['shard_id']       which shard this user's data lives on
prime_shard($shard_id)      opens shard connection on demand
All core helper functions   h(), sanitize(), db_query(), db_query_shard(), etc.
Session guard               auto-redirects to ../admin/auth/login.php
Email service               queue_email(), get_email_settings()
Notification service        send_notification(), register_notification_category()

## Shard queries
$shard = $_SESSION['shard_id'];
prime_shard($shard);
$r = db_query_shard($shard, "SELECT * FROM user_profile WHERE user_id = '"
    . $_SESSION['user_id'] . "'");

## Shared assets — reference, never copy
<link rel="stylesheet" href="../admin/assets/bootstrap/css/bootstrap.min.css">
<script src="../admin/assets/js/bs-init.js"></script>
<script src="../admin/assets/js/theme.js"></script>

## API endpoint
your-app/api/index.php — copy structure from admin/api/index.php.
Actions: your-app/include/actions/memberActions/ or apiActions/
Called at: POST your-app/api/index.php with action=yourAction

## Migration rules
Same two-step rule as core. See wave-networks-core/db_migrations/CLAUDE.md.
CRITICAL DIFFERENCE: update $db_version in YOUR-APP/include/common.php, not core's.
Child app shard migrations add tables to the existing shared shard DBs.

## Sending email from a child app
Core provides a shared email queue. Child apps never configure SMTP themselves.

queue_email($to, $subject, $html_body, $options);
  $options (all optional):
    'from_email' => 'noreply@yourdomain.com',  // must be in allowed senders
    'from_name'  => 'Your App',
    'reply_to'   => 'support@yourdomain.com',
    'source_app' => 'your-app',                 // for queue log filtering

Emails are queued in core's main DB and sent by core's cron runner with
throttle limits. No SMTP config needed in the child app.

## Sending notifications from a child app
Step 1: Register your app's notification categories at bootstrap.
  Call this once (idempotent — safe to call on every request):

  register_notification_category('your-app-alerts', 'Your App Alerts',
      'Important alerts from Your App', [
          'icon' => 'bi-bell',
          'default_frequency' => 'realtime',
          'created_by_app' => 'your-app',
      ]);

Step 2: Send notifications when events occur:
  $uid   = $_SESSION['user_id'];
  $shard = $_SESSION['shard_id'];
  send_notification($uid, $shard, 'your-app-alerts', 'Title', 'Body', [
      'action_url'   => '../your-app/app/index.php?page=detail&id=123',
      'action_label' => 'View Details',
      'source_app'   => 'your-app',
  ]);

Step 3: Broadcast to all users (admin action):
  broadcast_notification('your-app-alerts', 'Title', 'Body', $opts);

Users control frequency and push preferences per category from core's UI.
System categories (is_system=1) cannot be turned off by users.

## .htaccess (child app needs its own — does NOT inherit core's)
Deny: include/, config/, views/, snippets/, db_migrations/

## What NOT to do
- Name your DB connection $db — use $app_db
- Write to core tables (user, device, api_key, forgot) from child app code
- Copy admin/assets/ — always reference via ../admin/assets/
- Define your own session guard — core handles it
- Put child app credentials in core's admin/config/config.php
- Create new API endpoint files — use action files in actions/

## UI patterns — three tiers of reference
Tier 1: subtheme/views/template.php      — annotated shell, copy for new apps
Tier 2: subtheme/views/ui_components.php — full component catalogue, every pattern
Tier 3: subtheme/views/*.php             — normal pages showing realistic combos
See: github.com/WaveNetworks/wave-networks-sample

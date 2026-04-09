# wave-networks-core — Architecture

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

## Docker vs shared hosting
Docker:  All config via environment variables. FILES_LOCATION=/var/files/
         Source COPIED into image at build time (no bind mounts).
         Single MySQL server hosts all databases. Config auto-generated
         from env vars by entrypoint on first start.
Hosting: admin/config/config.php with __DIR__-relative $files_location.
         ../files/ is auto-created on first request by bootstrap.

### Docker naming convention
Admin is always started through a child app's docker-compose.yml (not standalone).
All 6 databases live on a single MySQL server, namespaced by child app name:
  {child_app}_admin_main, {child_app}_admin_shard_1, {child_app}_admin_shard_2
  {child_app}_main, {child_app}_shard_1, {child_app}_shard_2
This ensures multiple child apps on the same machine never collide.
See child-app CLAUDE.md Docker section for full naming table.

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

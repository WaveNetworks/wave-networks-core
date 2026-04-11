# Architecture & Folder Layout

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

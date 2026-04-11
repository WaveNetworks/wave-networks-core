# Child App Integration & Shared Assets

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

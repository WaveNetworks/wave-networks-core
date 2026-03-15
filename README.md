# Wave Networks Core

Standalone authentication and user administration layer. Handles login, registration, SSO, user management, role-based access, notifications, outbound email, and branding. Knows nothing about business domain logic — child apps are separate repos deployed as siblings that reach back into core for auth, session management, email sending, and push notifications.

## Features

- Email/password authentication with bcrypt hashing and automatic rehash on login
- Two-factor authentication (TOTP)
- OAuth 2.0 login (Google, GitHub, Facebook)
- SAML 2.0 login (Shibboleth, InCommon, any standard IdP)
- Role-based access control (admin, owner, manager, employee)
- Horizontal database sharding (main DB for auth, shard DBs for profile/app data)
- Auto-running database migrations (main + per-shard)
- In-app notification system with Web Push support
- Per-category notification preferences (realtime/daily/weekly/off)
- Outbound email with SMTP, queue, throttling, and allowed-sender management
- Parallel auth migration from legacy systems with transparent password rehash
- Branding and PWA (site name, logo, favicon, theme color, manifest)
- Google reCAPTCHA support
- Dark/light theme with Bootswatch theme selector
- Responsive sidebar layout
- Reports dashboard with acquisition, retention, and forecast charts
- Child app integration via shared includes
- Docker and shared hosting deployment

## Requirements

- PHP 8.2+
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- Imagick PHP extension (optional, for image processing)

## Quick Start

### Docker (development)

```bash
cp .env.example .env
# Edit .env with your values
docker compose up -d
```

Visit `http://localhost/install/install.php` to create the initial admin user. The installer directory is automatically removed after setup.

### Shared Hosting

1. Upload the `admin/` directory into your `public_html/`
2. Run `composer install` inside `admin/`
3. Copy `config/config.sample.php` to `config/config.php` and fill in your values
4. Create the `../files/` directory one level above `public_html/`
5. Visit `https://yourdomain.com/admin/install/install.php`
6. The installer directory is automatically removed after setup

## Configuration

**Docker:** All config via environment variables in `.env`. See `.env.example` for the full list.

**Shared hosting:** All config in `admin/config/config.php` (gitignored). See `config/config.sample.php` for reference.

### Core Settings

| Variable | Purpose |
|----------|---------|
| `DB_HOST_MAIN` / `$dbHostSpec` | Main database host |
| `DB_NAME_MAIN` / `$dbInstance` | Main database name |
| `$shardConfigs` | Shard database connection details |
| `APP_SECRET` / `$app_secret` | JWT signing key (64 chars) |
| `HIDDEN_HASH` / `$hiddenhash` | Password salt |
| `FILES_LOCATION` / `$files_location` | Absolute path to file storage (trailing slash) |

### SMTP Settings

| Variable | Purpose |
|----------|---------|
| `$smtp_host` | SMTP server hostname |
| `$smtp_port` | SMTP port (587 for STARTTLS, 465 for SSL) |
| `$smtp_user` / `$smtp_pass` | SMTP credentials |
| `$mail_from` / `$mail_from_name` | Default sender email and name |

### Web Push (VAPID)

| Variable | Purpose |
|----------|---------|
| `$vapid_subject` | Contact email (`mailto:admin@yourdomain.com`) |
| `$vapid_public_key` | VAPID public key |
| `$vapid_private_key` | VAPID private key |

Generate VAPID keys: `php vendor/bin/minishlink-web-push generate-keys`

## Folder Layout

```
public_html/
  admin/               <- this repo
    api/index.php       <- single API endpoint (AJAX only)
    app/index.php       <- authenticated app shell (loads views)
    auth/               <- login, register, OAuth, SAML, 2FA
    assets/             <- CSS, JS, Bootstrap, Bootswatch
    config/             <- config.php (gitignored)
    cron/               <- scheduled tasks (CLI only)
    db_migrations/      <- auto-running SQL migrations
    include/            <- common.php, helpers, action files
    snippets/           <- reusable PHP partials
    sw.js               <- service worker for push notifications
    views/              <- admin UI pages (loaded by app/)
    vendor/             <- Composer dependencies
  child-app/            <- separate repo, uses ../admin/include/common.php
  files/                <- user file storage (above webroot or at FILES_LOCATION)
```

## Architecture

### Shard Routing

- **Main DB** (`wncore_main`): Authentication only — `user`, `device`, `api_key`, `notification_category`, `email_queue`, `email_settings`
- **Shard DBs** (`wncore_shard_1`, `wncore_shard_2`): User profile, notifications, push subscriptions, notification preferences, and all child app data

Login flow: authenticate on main DB, store `shard_id` in session, `prime_shard()` opens shard connection. All subsequent requests route via session — zero extra lookups.

### Action File System

All server-side operations use action files in `include/actions/`. Two invocation methods:

1. **Plain form POST** — `<form method="post">` with a hidden `action` field. The form posts to the current page, the action runs, session flash messages are set, and the view re-renders. No JavaScript needed. Preferred for settings forms, file uploads, and full-page actions.

2. **AJAX** — `apiPost('actionName', {params}, callback)` from `assets/js/bs-init.js`. Posts to `api/index.php`, returns JSON. Use for inline UI updates (mark read, delete row, live search).

Never create new endpoint files. Never point `<form action="">` at `api/index.php`.

### Email Queue

Core provides a shared outbound email service. Emails are queued in the main DB (`email_queue` table) and sent in batches by the cron runner, respecting per-minute, per-hour, and per-day throttle limits configured in the admin panel.

Child apps send email by calling `queue_email()` after including `common.php` — no SMTP configuration needed in the child app.

### Notification System

Notifications are stored per-user on their shard. Categories are defined in the main DB. Users control frequency (realtime, daily, weekly, off) and push toggle per category.

- `send_notification()` — create a notification for a specific user
- `broadcast_notification()` — send to all users across all shards
- `register_notification_category()` — child apps register their own categories

Web Push uses VAPID with the `minishlink/web-push` library. Service worker at `admin/sw.js`.

## Cron Jobs

All cron scripts are CLI-only and live in `admin/cron/`. They bootstrap via `include/common_readonly.php` (config + DB + helpers, no actions, no session guard).

| Script | Schedule | Purpose |
|--------|----------|---------|
| `cron/cron.php` | `* * * * *` | Main cron runner — processes email queue, runs minute/hour/day/week/month tasks |
| `cron/sync_users.php` | `*/15 * * * *` | Incremental user migration sync from external database |
| `cron/push_digest.php` | `0 8 * * *` | Sends batched push notification digests (daily + weekly on Mondays) |
| `cron/cleanup_notifications.php` | `0 3 1 * *` | Deletes notifications older than 90 days, removes stale push subscriptions |

## Admin Panel

Once logged in as an admin, the sidebar provides access to:

- **Dashboard** — user counts, shard distribution, recent activity
- **Users** — search, view, create, edit, delete users across shards
- **Notifications** — view and manage your notifications, preferences, push subscriptions
- **Reports** — user acquisition, retention, and forecast charts
- **Settings** — grouped under a collapsible menu:
  - **Basic Settings** — registration mode (open/invite/closed), reCAPTCHA, branding
  - **Email** — SMTP configuration, throttle limits, allowed senders, email queue log
  - **OAuth Providers** — add/enable Google, GitHub, Facebook login
  - **SAML Providers** — add/enable institutional SSO
  - **Migration** — parallel auth migration from external databases
- **Notification Admin** — manage notification categories, send broadcasts

## SSO Setup

See [docs/SSO.md](docs/SSO.md) for step-by-step instructions on configuring OAuth 2.0 and SAML 2.0 providers.

## User Migration

See [docs/Migration.md](docs/Migration.md) for parallel auth migration from legacy systems with transparent password rehash.

## Shared Assets

Core provides CSS and JS that child apps reference directly (never copy):

### CSS (loaded in child app `<head>`)

| File | Purpose |
|------|---------|
| `assets/css/style.css` | Sidebar, navbar, layout structure, collapse behavior |
| `assets/css/bs-theme-overrides.css` | Dark/light mode overrides for Bootstrap components |

### JS (loaded in child app footer)

| File | Purpose |
|------|---------|
| `assets/js/bs-init.js` | `apiPost()` AJAX helper, Bootstrap component initialization |
| `assets/js/sidebar.js` | Sidebar collapse/expand, mobile toggle, localStorage persistence |
| `assets/js/color-mode.js` | Dark/light mode toggle with localStorage and system preference detection |
| `assets/js/notifications.js` | Notification bell polling, dropdown rendering, mark-read, Web Push |

Child apps reference these via relative paths (`../../admin/assets/...`) and add their own app-specific JS on top.

## Child App Integration

Child apps include core's common file to get auth, sessions, shard routing, email, and notifications:

```php
<?php
// child-app/include/common.php
include(__DIR__ . '/../../admin/include/common.php');

// Session is loaded, shard is primed
// Access $_SESSION['user_id'], $_SESSION['shard_id'], etc.
// Send email: queue_email($to, $subject, $body)
// Send notification: send_notification($user_id, $shard_id, $slug, $title, $body)
```

Child apps create their own databases and tables. They never write to core tables.

### Reference Implementation

The [child-app](https://github.com/WaveNetworks/child-app) repo is a fully working example that demonstrates:

- Three-layer database pattern (core read-only, child main, child shards)
- Custom SCSS theme pipeline with glassmorphism design
- D3.js dashboard charts (line chart, donut chart)
- DataTables with Bootstrap 5 + Responsive extension
- Live search via debounced AJAX (`apiPost`)
- AJAX modals via `modalView()`
- Offcanvas settings panel
- Toast notification system
- Notification bell integration
- Branded auth pages
- Docker development environment (8 services)

Clone it, rename it, and replace the example views with your own.

See [CHILD_APP_SPEC.md](CHILD_APP_SPEC.md) for the full integration specification.

## Documentation

| File | Contents |
|------|----------|
| `CLAUDE.md` | Architecture reference and coding rules for AI assistants |
| `CHILD_APP_SPEC.md` | Child app integration specification |
| `docs/SSO.md` | OAuth 2.0 and SAML 2.0 setup guide |
| `docs/Migration.md` | Parallel auth migration guide |
| `docs/Email.md` | Email system setup and child app integration |
| `docs/Notifications.md` | Notification system and Web Push guide |
| `db_migrations/CLAUDE.md` | Database migration version rules |
| `include/actions/CLAUDE.md` | Action file patterns and rules |

## License

Proprietary. All rights reserved.

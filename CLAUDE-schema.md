# wave-networks-core — Schema & Data Systems

## GDPR compliance system

Full GDPR compliance with consent tracking, data export, and account deletion.
All functions in `include/common/gdprFunctions.php` — auto-included via glob.

### Tables (main DB)

**consent_version** — Policy document versions
| Column | Type | Notes |
|--------|------|-------|
| version_id | INT UNSIGNED PK | Auto-increment |
| consent_type | VARCHAR(50) | e.g. 'privacy_policy', 'terms_of_service', 'marketing' |
| version_label | VARCHAR(50) | e.g. 'v2.0' |
| effective_date | DATE | When this version becomes active |
| document_url | VARCHAR(500) | Link to full policy document |
| summary | TEXT | Human-readable summary of changes |

**user_consent** — Immutable consent event log
| Column | Type | Notes |
|--------|------|-------|
| consent_id | BIGINT UNSIGNED PK | Auto-increment |
| user_id | INT UNSIGNED | FK to user table |
| consent_type | VARCHAR(50) | Matches consent_version.consent_type |
| consent_version_id | INT UNSIGNED | FK to consent_version (nullable) |
| action | ENUM('granted','withdrawn') | What the user did |
| ip_address | VARCHAR(45) | Captured at time of consent |
| user_agent | VARCHAR(512) | Captured at time of consent |
| created | DATETIME | Immutable timestamp |

**account_deletion_request** — Deletion with 30-day cooling-off period
| Column | Type | Notes |
|--------|------|-------|
| request_id | INT UNSIGNED PK | Auto-increment |
| user_id | INT UNSIGNED | FK to user table |
| reason | TEXT | Optional user-provided reason |
| status | ENUM('pending','cancelled','completed') | Current state |
| requested_at | DATETIME | When user requested deletion |
| cancel_before | DATETIME | = requested_at + 30 days |
| cancelled_at | DATETIME | Null until cancelled |
| completed_at | DATETIME | Null until completed |
| completed_by | VARCHAR(50) | 'cron' or admin user identifier |

**data_export_request** — Data export audit trail
| Column | Type | Notes |
|--------|------|-------|
| export_id | INT UNSIGNED PK | Auto-increment |
| user_id | INT UNSIGNED | FK to user table |
| format | ENUM('json','csv') | Export format |
| status | ENUM('pending','processing','ready','expired','failed') | Current state |
| requested_at | DATETIME | When user requested export |
| completed_at | DATETIME | When export finished |
| file_path | VARCHAR(500) | Path to generated export file |
| file_size | INT UNSIGNED | Bytes |

### Helper functions

**Consent tracking:**
- `record_consent($user_id, $consent_type, $action, $version_id)` — log a consent event
- `get_consent_status($user_id, $consent_type)` — returns 'granted', 'withdrawn', or null
- `get_all_consent_statuses($user_id)` — returns `['type' => 'granted'|'withdrawn', ...]`
- `get_consent_history($user_id)` — full audit log with version labels
- `get_latest_consent_version($consent_type)` — latest version row for a type
- `get_all_consent_versions()` — all types with their latest versions

**Account deletion:**
- `request_account_deletion($user_id, $reason)` — creates pending request, returns request_id or false if one exists
- `cancel_account_deletion($user_id)` — cancels pending request
- `get_pending_deletion($user_id)` — returns active pending request or null

**Data export:**
- `request_data_export($user_id, $format)` — creates pending export, returns export_id or false if one is active
- `get_latest_export($user_id)` — returns most recent export request
- `build_export_data($user_id, $shard_id)` — collects user data from admin main + shard (user record minus password, profile, consent history, notifications). Child apps extend this with their own data.

### Login flow (re-consent check)
On login, the auth flow checks whether any consent_version has been published since
the user last granted consent. If the user's last consent for a type is older than the
latest version's `effective_date`, the user is shown a re-consent interstitial before
proceeding to the app. Only `consent_type` values marked as `required_for_login` in
the consent_version table trigger this gate.

### Child app usage
Child apps call the same GDPR functions after including core's common.php.
- Record consent for app-specific types: `record_consent($uid, 'app_specific_type', 'granted')`
- Check consent: `get_consent_status($uid, 'app_specific_type')`
- Child apps add their own data to exports by hooking into the export process

### Admin compliance actions
Admins can view and manage GDPR data via the admin panel:
- View all pending deletion requests and manually complete them
- View consent audit trail per user
- View data export request history
- Consent versions are managed in the admin settings

## Theme management

### Bootswatch themes
25 built-in Bootswatch themes served via CDN. The allowed list is in
`themeFunctions.php` (`$_bootswatch_allowed` array). Default theme: `sandstone`.

Theme is stored in a cookie (`wn_theme`) set by `theme.js` so PHP can render the
correct stylesheet on first paint (no FOUC). `get_active_theme()` reads the cookie
and validates against the allowed list + registered custom themes.

`get_theme_css_url($prefix, $webroot_prefix)` returns the CSS URL:
- Sandstone: local `assets/bootstrap/css/bootstrap.min.css`
- Other Bootswatch: `cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/{theme}/bootstrap.min.css`
- Registered custom: `{webroot_prefix}{css_path}` from the database

### Registered custom themes (themeRegistrationFunctions.php)
Child apps register custom themes at bootstrap:
```php
register_theme($slug, $name, $css_path, $opts);
```
Options: `sidebar_mode` (dark|glass), `created_by_app`, `is_active`.
Idempotent — uses INSERT ... ON DUPLICATE KEY UPDATE on the `registered_theme` table
(main DB). Silently ignores if the table does not exist yet (pre-migration).

`get_registered_themes()` returns all active registered themes (cached per-request).
`get_registered_theme($slug)` returns a single theme by slug.

### Color mode
Light/dark mode is handled client-side via `data-bs-theme` attribute on `<html>`.
The template.php inline script reads `localStorage.wn_color_mode` or falls back to
`prefers-color-scheme`. Two theme-color meta tags are set for light and dark.

## Versioning and update checks

### Version detection
`WN_ADMIN_VERSION` constant defined in common.php. Child app versions detected
via `detect_child_app_versions()` which scans sibling directories.

### Update check (updateCheckFunctions.php)
`check_for_updates($force)` calls the `https://subtheme.com/api/versions` API.
Results are cached to `config/.update_check_cache.json` with a 24-hour TTL.
Returns stale cache if the API is unreachable.

Response structure:
```php
[
  'admin'      => ['current' => '1.0.0', 'latest' => '1.2.0', 'outdated' => true],
  'child_apps' => [
    'child-app' => ['current' => '1.0.0', 'latest' => '1.1.0', 'outdated' => true],
  ],
  'checked_at' => '2026-03-17T10:00:00+00:00',
]
```

The admin dashboard shows update badges when `outdated` is true.

## Branding settings

### Storage
Branding settings are stored in the `auth_settings` table (setting_id = 1) on main DB.
Branding files (logos, favicons, PWA icons) are stored in `$files_location/branding/`
(outside webroot) and served via `branding.php` proxy with .htaccess rewrite.

URL pattern: `/admin/branding/filename` (e.g. `/admin/branding/branding_logo.svg`).

### Settings fields
| Field | Description |
|-------|-------------|
| `site_name` | Full site name (shown in sidebar, page titles) |
| `site_short_name` | Short name for PWA manifest |
| `site_description` | Used in PWA manifest |
| `theme_color` | Default theme color for meta tags |
| `theme_color_light` | Light mode theme-color (falls back to theme_color) |
| `theme_color_dark` | Dark mode theme-color (falls back to theme_color) |
| `logo_path` | Light-mode logo filename |
| `logo_dark_path` | Dark-mode logo filename (optional, falls back to logo_path) |
| `favicon_path` | Favicon filename |
| `pwa_screenshot_wide` | PWA install screenshot (desktop) |
| `pwa_screenshot_mobile` | PWA install screenshot (mobile) |

### Helper functions (brandingFunctions.php)
`get_branding()` returns all settings with defaults. Cached per-request.
Appends cache-busting `?v={mtime}` to file paths automatically.
`get_site_name()`, `get_site_short_name()` — convenience wrappers.

### Dark mode logo switching
The template supports dual logos. The `<img>` tag includes `data-logo-dark` and
`data-logo-light` attributes. JavaScript in `bs-init.js` swaps the `src` based
on the active color mode.

# Notification System Guide

Wave Networks Core provides an in-app notification system with Web Push support, per-category user preferences, and child app integration. Notifications are stored on the user's shard for horizontal scalability.

---

## Architecture

```
Main DB (wncore_main)                 Shard DBs (wncore_shard_N)
 ┌──────────────────────┐             ┌──────────────────────────┐
 │ notification_category │             │ notification             │
 │  (shared definitions) │             │  (per-user messages)     │
 └──────────────────────┘             │                          │
                                       │ notification_preference  │
                                       │  (per-user per-category) │
                                       │                          │
                                       │ push_subscription        │
                                       │  (per-user per-device)   │
                                       └──────────────────────────┘
```

- **Categories** are defined once in the main DB (shared across all users)
- **Notifications, preferences, and push subscriptions** live on the user's shard
- Categories are referenced by slug (string), not foreign key, to avoid cross-DB FK issues

---

## Notification Categories

Categories group notifications by type and control default behavior.

| Field | Purpose |
|-------|---------|
| `slug` | URL-safe unique identifier (e.g. `security`, `system_updates`) |
| `name` | Display name shown to users |
| `description` | Explains what this category sends |
| `icon` | Bootstrap icon class (e.g. `bi-shield-lock`) |
| `is_system` | System categories cannot be turned off by users |
| `allow_frequency_override` | Whether users can change the frequency |
| `default_frequency` | Default: `realtime`, `daily`, `weekly`, or `off` |
| `created_by_app` | Which app registered this category |

### Default Categories (installed with migration 1.6)

| Slug | Name | System? | Default |
|------|------|---------|---------|
| `security` | Security Alerts | Yes (locked) | Realtime |
| `system_updates` | System Updates | No | Realtime |
| `admin_broadcast` | Admin Broadcast | No | Realtime |

### Registering Categories from Child Apps

Call `register_notification_category()` at bootstrap. It's idempotent (INSERT ON DUPLICATE KEY UPDATE):

```php
register_notification_category('my-app-alerts', 'My App Alerts',
    'Important notifications from My App', [
        'icon'             => 'bi-bell',
        'default_frequency' => 'realtime',
        'created_by_app'   => 'my-app',
    ]);
```

---

## Sending Notifications

### To a Specific User

```php
send_notification(
    $user_id,              // core user_id
    $shard_id,             // user's shard_id (from session or lookup)
    'my-app-alerts',       // category slug
    'New Report Ready',    // title
    'Your weekly report has been generated.', // body
    [
        'action_url'   => '../my-app/app/index.php?page=report&id=42',
        'action_label' => 'View Report',
        'source_app'   => 'my-app',
    ]
);
```

If the user's preference for this category is `realtime` and push is enabled, a Web Push notification is sent immediately. For `daily` or `weekly`, the notification is stored with `push_sent = 0` and batched by the cron digest.

### Broadcast to All Users

```php
broadcast_notification('admin_broadcast', 'Maintenance Window',
    'The system will be down for maintenance on Saturday at 2 AM.',
    ['source_app' => 'core']);
```

This queries all users from the main DB and sends a notification to each on their shard.

---

## User Preferences

Users control two settings per category:

1. **Frequency** — `realtime`, `daily`, `weekly`, or `off`
2. **Push Enabled** — whether to send Web Push for this category

System categories (`is_system = 1`) have `allow_frequency_override = 0`, meaning users cannot change the frequency or disable them.

### Preference Functions

```php
// Get all categories with user's preferences merged
$prefs = get_user_notification_preferences($user_id, $shard_id);

// Set a preference
set_notification_preference($user_id, $shard_id, 'system_updates', 'daily', true);
```

---

## Web Push

### VAPID Configuration

Generate VAPID keys:

```bash
php vendor/bin/minishlink-web-push generate-keys
```

Add to `config/config.php`:

```php
$vapid_subject     = 'mailto:admin@yourdomain.com';
$vapid_public_key  = 'BIjK...';     // from generate-keys output
$vapid_private_key = 'bXkR...';     // from generate-keys output
```

Or via environment variables: `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`.

### Service Worker

The service worker at `admin/sw.js` handles:

- `push` event — parse payload, display notification
- `notificationclick` — open or focus the action URL
- `pushsubscriptionchange` — re-subscribe automatically

### Push Subscription Flow

1. User clicks "Enable Push Notifications" in notification preferences
2. Browser requests notification permission
3. Service worker is registered at `/admin/sw.js` scope
4. Browser creates a PushSubscription using the VAPID public key
5. Subscription (endpoint, p256dh, auth) is saved to `push_subscription` on the shard
6. Push notifications are sent via `minishlink/web-push` library

### Expired Subscriptions

When a push send returns HTTP 410 (Gone), the subscription is automatically deleted from the database. The `last_used` timestamp is updated on successful sends for staleness tracking.

---

## Cron Jobs

### Push Digest (`cron/push_digest.php`)

**Schedule:** `0 8 * * *` (daily at 8 AM)

Processes notifications with `push_sent = 0` across all shards:

- **Daily** users: digest sent every run
- **Weekly** users: digest sent only on Mondays
- **Realtime** catch-up: any missed realtime notifications included in daily batch
- **Disabled/off** preferences: marked as `push_sent = 1` (skipped)

Sends a single summary push per user ("You have X new notifications").

### Cleanup (`cron/cleanup_notifications.php`)

**Schedule:** `0 3 1 * *` (monthly, 1st at 3 AM)

- Deletes notifications older than 90 days from all shards
- Deletes push subscriptions not used in 90 days

---

## Admin Panel

### Notification Admin (`index.php?page=notification_admin`)

Admin-only page with:

- **Send Broadcast** — select category, enter title/body/action, send to all users
- **Manage Categories** — add, edit, delete notification categories (system categories protected)

### User-Facing Pages

- **Notifications** (`index.php?page=notifications`) — list all notifications, mark read
- **Notification Preferences** (`index.php?page=notification_preferences`) — per-category frequency/push toggles, device list

### Bell Icon

The top navigation bar includes a notification bell with:

- Unread count badge (polled every 60 seconds)
- Dropdown with recent notifications
- Mark all read button
- Link to full notification list

---

## Database Tables

### Main DB: `notification_category`

| Column | Type | Purpose |
|--------|------|---------|
| category_id | INT PK | Auto-increment |
| slug | VARCHAR(100) UNIQUE | URL-safe identifier |
| name | VARCHAR(255) | Display name |
| description | TEXT | User-facing description |
| icon | VARCHAR(100) | Bootstrap icon class |
| is_system | TINYINT | 1 = cannot be disabled by users |
| allow_frequency_override | TINYINT | 1 = users can change frequency |
| default_frequency | ENUM | realtime, daily, weekly, off |
| created_by_app | VARCHAR(100) | Which app registered this |

### Shard DB: `notification`

| Column | Type | Purpose |
|--------|------|---------|
| notification_id | INT PK | Auto-increment |
| user_id | INT | Core user_id |
| category_slug | VARCHAR(100) | References category by slug |
| title | VARCHAR(255) | Notification title |
| body | TEXT | Notification body |
| action_url | VARCHAR(500) | Optional link |
| action_label | VARCHAR(100) | Optional button text |
| is_read | TINYINT | 0 = unread, 1 = read |
| push_sent | TINYINT | 0 = pending push, 1 = sent/skipped |
| source_app | VARCHAR(100) | Which app created this |
| created | DATETIME | Creation timestamp |

### Shard DB: `notification_preference`

Composite primary key: (user_id, category_slug)

| Column | Type | Purpose |
|--------|------|---------|
| frequency | ENUM | realtime, daily, weekly, off |
| push_enabled | TINYINT | Whether push is on for this category |

### Shard DB: `push_subscription`

| Column | Type | Purpose |
|--------|------|---------|
| subscription_id | INT PK | Auto-increment |
| user_id | INT | Core user_id |
| endpoint | TEXT UNIQUE | Web Push endpoint URL |
| p256dh_key | VARCHAR(255) | Client public key |
| auth_key | VARCHAR(255) | Client auth secret |
| user_agent | VARCHAR(500) | Browser info |
| created | DATETIME | When subscription was created |
| last_used | DATETIME | Last successful push send |

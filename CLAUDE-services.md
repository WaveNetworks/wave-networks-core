# wave-networks-core — Services

## Email queue system

All email is sent via an async queue stored in the `email_queue` table on the main DB.
No email is ever sent synchronously during a request.

### Sending email (from any app)
```php
queue_email($to_email, $to_name, $subject, $body_html, $opts);
```
Options: `from_email`, `from_name`, `reply_to`, `source_app`, `priority`.
Validates recipient and subject before inserting. Returns queue ID or false.

### Allowed senders
The `email_allowed_sender` table whitelists from-addresses. If the table is empty,
any sender is allowed (backward compat). `is_allowed_sender($email)` checks the list.
`get_default_sender()` returns the default row or falls back to email_settings.

### Settings and throttling
`email_settings` table (setting_id = 1) stores SMTP config, throttle limits, and max
retry attempts. Falls back to config.php globals (`$smtp_host`, `$smtp_port`, etc.)
for any empty DB field. Settings are cached per-request via `get_email_settings()`.

Throttle defaults: 10/minute, 200/hour, 3 max attempts.

### Queue processing (cron)
`cron/minutes/1/process_email_queue.php` runs every minute via cron.php.
Picks up queued messages, sends via PHPMailer (vendor/autoload.php), updates status.
Failed messages are retried up to `max_attempts` times before being marked failed.

### DNS deliverability
Helper functions check SPF, DKIM, and DMARC records for configured sender domains.
Used by the Settings > Email admin view to surface deliverability warnings.

## Notification system

Notifications are stored on shard databases. Category definitions live on main DB.

### Sending notifications
```php
send_notification($user_id, $shard_id, $category_slug, $title, $body, $opts);
```
Options: `action_url`, `action_label`, `source_app`.
Creates an in-app notification on the user's shard. Sends push notification based
on user preference (realtime = immediate, daily/weekly = batched by cron, off = skip).

### Broadcasting
```php
broadcast_notification($category_slug, $title, $body, $opts);
```
Iterates all shards and sends to every user. Respects per-user preferences.

### Categories
```php
register_notification_category($slug, $name, $description, $opts);
```
Idempotent — child apps call this at bootstrap to register app-specific categories.
Stored in `notification_category` on main DB. Uses INSERT ... ON DUPLICATE KEY UPDATE.

### User preferences
Per-user preferences stored in `notification_preference` on shard.
Users choose frequency (realtime, daily, weekly, off) and push enable/disable per category.
`_get_user_pref($user_id, $shard_id, $category_slug)` retrieves preferences.

### Push notifications
`send_push_to_user($user_id, $shard_id, $title, $body, $payload)` sends via Web Push.
Push subscriptions stored in `push_subscription` on shard.
`cron/push_digest.php` batches daily/weekly notifications.

### Child app usage
After including core's common.php, child apps can call:
- `queue_email(...)` — send via core's email queue
- `send_notification(...)` — create user notification on shard
- `register_notification_category(...)` — register app-specific categories

## Cron jobs

### Architecture
`cron/cron.php` is the single entry point. Run via server crontab every minute:
```
* * * * * php /path/to/admin/cron/cron.php
```
CLI-only (returns 403 for HTTP requests). Bootstraps via `common_readonly.php`.

### Folder-based scheduling
Jobs are organized into schedule directories:
- `cron/minutes/{N}/*.php` — every N minutes (runs when minute % N == 0)
- `cron/days/{day}/*.php` — on that day of the month (1-31), checked at midnight
- `cron/months/{month}/*.php` — on the 1st of that month (1-12)

Each .php file is included directly by cron.php. Just drop a file in the right
directory — no registration needed.

### Current jobs
| Schedule | File | Purpose |
|----------|------|---------|
| Every 1 min | `minutes/1/process_email_queue.php` | Send queued emails |
| Day 1 | `days/1/cleanup_error_log.php` | Purge old error log entries |
| Day 1 | `days/1/cleanup_expired_tokens.php` | Remove expired auth tokens |

### Logging
Every job execution is logged via `log_cron($job, $result)` to the `cron_log` table.
`get_cron_logs($limit)` retrieves recent entries for the admin dashboard.

## Error logging system

### Custom handlers (errorHandler.php)
Registers custom PHP error, exception, and shutdown handlers.
Maps PHP error constants to severity levels: DEBUG, INFO, WARNING, ERROR, FATAL.
Captures stack traces and request context (GET params, POST action, session user).

### Database logging (errorLogFunctions.php)
`log_error_to_db($level, $message, $file, $line, $trace)` writes to the `error_log`
table on main DB. Uses direct PDO to avoid recursion if `db_query()` itself errors.
Falls back to PHP `error_log()` if DB is unavailable.

Features:
- Per-request deduplication (same file:line:message logged only once)
- Auto-detects source app from file path
- Captures request context as JSON (GET, POST action, session user, page)
- Entries can be marked resolved/unresolved by admins
- Visible in Settings > Error Log view, filterable by level and source app

### Migration failures
When a migration fails, the error is:
1. Set in `$_SESSION['error']` (shown as toast)
2. Written to PHP `error_log()` (Apache/container logs)
3. Logged to the `error_log` DB table as WARNING level

## Service API keys

Service API keys provide programmatic access to the admin HTTP API without user
sessions. Separate from `apiKeyFunctions.php` (which handles remember-me cookies).

### Key format
Prefix: `wn_sk_` + 58-char random string. Only the bcrypt hash is stored in the
`service_api_key` table. The full key is returned once at creation time.
Prefix (first 12 chars) is stored for identification.

### Scopes
Keys are scoped. Available scopes (from `get_available_scopes()`):
| Scope | Description |
|-------|-------------|
| `error_log:read` | Read error logs |
| `error_log:write` | Resolve/unresolve error logs |
| `users:read` | Read user list |
| `costs:write` | Record cost entries (COGS, CAC, support) |
| `costs:read` | Read cost data and reports |
| `feedback:read` | Read user feedback |
| `feedback:write` | Submit and manage feedback |
| `feedback:admin` | Manage change requests and feedback status |
| `stripe:read` | Read Stripe transactions, revenue, LTV data |
| `stripe:write` | Record Stripe transactions |

Child apps can extend this list by registering their own scopes.

### Authentication
API callers pass the key via `Authorization: Bearer wn_sk_...` header.
The API middleware verifies by bcrypt-comparing against stored hashes.

### Management
- `create_service_api_key($name, $scopes, $created_by)` — returns full key once
- Keys can be revoked (soft-deleted) by admins via Settings > API Keys view
- Created/last-used timestamps tracked for audit

## MCP server

Model Context Protocol server for Claude Desktop integration.
Located at `mcp/server.php`. JSON-RPC 2.0 over stdio.

### Architecture
The MCP server wraps the admin HTTP API — it does NOT include common.php directly.
All operations go through HTTP using a service API key for authentication.

### Configuration (environment variables)
| Variable | Default | Description |
|----------|---------|-------------|
| `WN_API_URL` | `http://localhost/admin/api/index.php` | Admin API endpoint |
| `WN_API_KEY` | (none) | Service API key with required scopes |

### Claude Desktop config example
```json
{
  "mcpServers": {
    "wave-networks-admin": {
      "command": "php",
      "args": ["/path/to/admin/mcp/server.php"],
      "env": {
        "WN_API_URL": "http://localhost/admin/api/index.php",
        "WN_API_KEY": "wn_sk_..."
      }
    }
  }
}
```

### Available tools
**Error log tools** (require `error_log:read` and/or `error_log:write` scopes):
| Tool | Description |
|------|-------------|
| `list_errors` | List error log entries with filters (level, source_app, status, search) |
| `get_error` | Get single error with full stack trace and context |
| `resolve_error` | Mark error as resolved |
| `unresolve_error` | Reopen a resolved error |
| `get_error_stats` | Counts by level (today), resolved/open counts, source apps |

**Feedback tools** (require `feedback:read` and/or `feedback:write`/`feedback:admin` scopes):
| Tool | Description |
|------|-------------|
| `list_feedback` | List feedback with filters (type, source_app, status, user_id) |
| `get_feedback` | Get single feedback entry with full details |
| `get_feedback_stats` | Counts by status and type, source apps |
| `list_change_requests` | List change requests with filters (status, priority, type) |
| `get_change_request` | Get change request with all grouped feedback entries |
| `create_change_request` | Create new change request or addition |
| `update_change_request` | Update status, priority, or details |
| `group_feedback` | Link feedback entry to a change request |

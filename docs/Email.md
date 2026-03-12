# Email System Guide

Wave Networks Core provides a centralized outbound email service with SMTP configuration, queue-based sending, throttle limits, and allowed-sender management. Child apps use `queue_email()` to send email through core's queue — no SMTP setup required in child apps.

---

## Architecture

```
Child App                    Core Admin
    |                            |
    |-- queue_email() ---------> email_queue table (main DB)
                                 |
                          cron/cron.php (every minute)
                                 |
                          process_email_queue()
                                 |
                          send_email() via PHPMailer
                                 |
                          SMTP server
```

Emails are never sent inline during a web request. `queue_email()` inserts a row into the `email_queue` table with status `pending`. The cron runner picks up pending emails in batches, respecting throttle limits.

---

## Setup

### Step 1: Configure SMTP

**Option A — Admin Panel (recommended):**

1. Log in as admin
2. Go to **Settings > Email**
3. Fill in SMTP Host, Port, Username, Password
4. Select Encryption (STARTTLS for port 587, SSL for port 465, None for port 25)
5. Set Default From Email and From Name
6. Click **Save SMTP Settings**

**Option B — Config file (fallback):**

Settings in `config/config.php` are used when the DB settings are empty:

```php
$smtp_host      = 'smtp.yourdomain.com';
$smtp_port      = 587;
$smtp_user      = 'your_smtp_user';
$smtp_pass      = 'your_smtp_password';
$mail_from      = 'noreply@yourdomain.com';
$mail_from_name = 'Wave Networks';
```

DB settings always take precedence over config file values.

### Step 2: Configure Throttle Limits

From the Email settings page, set:

| Limit | Purpose |
|-------|---------|
| **Per Minute** | Max emails sent per minute (default: 10) |
| **Per Hour** | Max emails sent per hour (default: 200) |
| **Per Day** | Max emails sent per day (default: 3000) |

The cron runner checks these limits before each batch. If a limit is reached, remaining emails stay queued until the window resets.

### Step 3: Add Allowed Senders

Only email addresses listed as allowed senders can appear in the From header. This prevents spoofing and helps maintain sender reputation.

1. In the Email settings page, find the "Allowed Senders" section
2. Enter the email address and click "Add"
3. The default From Email is automatically allowed

If a `queue_email()` call specifies a `from_email` that isn't in the allowed list, the default From Email is used instead.

### Step 4: Set Up Cron

Add the main cron runner to your crontab:

```bash
* * * * * php /path/to/admin/cron/cron.php
```

This processes the email queue every minute (among other tasks).

---

## Sending Email from Code

### Basic Usage

```php
queue_email(
    'user@example.com',       // recipient email
    'Jane Smith',             // recipient name
    'Welcome!',               // subject
    '<h1>Hello</h1><p>...</p>' // HTML body
);
```

### With Options

```php
queue_email('user@example.com', 'Jane Smith', 'Subject', $html, [
    'from_email'  => 'alerts@yourdomain.com',  // must be in allowed senders
    'from_name'   => 'Alert System',
    'reply_to'    => 'support@yourdomain.com',
    'alt_body'    => 'Plain text version',
    'source_app'  => 'my-child-app',           // for queue log filtering
    'priority'    => 1,                         // 1 = high, 3 = normal (default), 5 = low
]);
```

### From a Child App

After including `../admin/include/common.php`, all email functions are available:

```php
<?php
include(__DIR__ . '/../admin/include/common.php');

queue_email(
    $user_email,
    $user_name,
    'Your report is ready',
    '<p>Your weekly report has been generated.</p>',
    ['source_app' => 'reports-app']
);
```

No SMTP configuration needed in the child app.

---

## Test Email

The Email settings page has a "Send Test Email" button that sends a test message to the logged-in admin's email address. This bypasses the queue and sends immediately to verify SMTP settings.

---

## DNS Deliverability

The Email settings page displays the current server's DNS deliverability records:

- **SPF** — Sender Policy Framework record
- **DKIM** — DomainKeys Identified Mail status
- **MX** — Mail exchanger records

These are read-only displays to help diagnose deliverability issues. Actual DNS records must be configured at your domain registrar or DNS provider.

---

## Queue Management

The Email settings page shows recent queue entries with status, recipient, subject, send time, and any error messages. Admins can:

- View the queue log with pagination
- Retry failed emails
- Monitor throttle usage

### Queue Statuses

| Status | Meaning |
|--------|---------|
| `pending` | Waiting to be sent |
| `sent` | Successfully delivered to SMTP server |
| `failed` | Send attempt failed (error stored in `error_message` column) |

---

## Database Tables (Main DB)

### email_settings
Single-row config table (setting_id = 1). Stores SMTP host, port, credentials, encryption, default from/reply-to, throttle limits.

### email_queue
Queue of outbound messages. Columns: to_email, to_name, from_email, from_name, reply_to, subject, body_html, alt_body, status, priority, attempts, error_message, source_app, created_at, sent_at.

### email_allowed_senders
List of approved From addresses. Only these can be used as the sender.

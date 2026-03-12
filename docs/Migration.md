# Parallel Auth Migration Guide

Migrate users from an existing application into Wave Networks Core **without** breaking the old app. The old system keeps running — user IDs and references in dependent tables stay intact. Core becomes the new central auth layer, and users are synced in two ways:

- **Batch sync** — Admin-triggered or cron-scheduled import from the external database
- **On-the-fly** — Users are created in core automatically when they log in via SAML/OAuth, or when child app code calls `ensure_core_user()`

Legacy passwords are rehashed transparently on first login — no forced resets needed.

---

## Architecture

```
┌─────────────────────┐         ┌───────────────────────────────┐
│   External App DB   │         │       Core Main DB            │
│   (old system)      │─ sync ─▶│   user table                  │
│   users table       │         │   user_migration_map          │
│   (keeps all IDs)   │         │   migration_source (config)   │
└─────────────────────┘         └──────────┬────────────────────┘
                                           │
                                ┌──────────▼────────────────────┐
                                │       Core Shard DB           │
                                │   user_profile                │
                                │   (first/last name, homedir)  │
                                └───────────────────────────────┘
```

The `user_migration_map` table links each external user ID to its core user ID. Child apps can look up this mapping to translate between old and new IDs.

---

## Setup

### Step 1: Configure the External Database

1. Log in as admin and go to **Migration** in the sidebar
2. Fill in the connection details for the external (old) database:

| Field | Description |
|-------|-------------|
| **Source Name** | Friendly label (e.g. "Legacy CRM") |
| **Host** | External database hostname or IP |
| **Port** | MySQL port (default 3306) |
| **Database Name** | Name of the external database |
| **User / Password** | Credentials with SELECT access to the user table |

The password is encrypted at rest using AES-256-CBC with the application's `$app_secret` key.

### Step 2: Map Columns

Tell core which columns in the external table correspond to user fields:

| Field | Default | Description |
|-------|---------|-------------|
| **User Table** | `users` | Name of the table containing users |
| **ID Column** | `id` | Primary key column |
| **Email Column** | `email` | Email address column |
| **Password Column** | `password` | Hashed password column (optional — leave blank if passwords shouldn't be migrated) |
| **First Name Column** | `first_name` | First name (optional) |
| **Last Name Column** | `last_name` | Last name (optional) |

### Step 3: Configure Password Migration

| Field | Description |
|-------|-------------|
| **Password Hash Algorithm** | How the old app hashed passwords. Supported: `bcrypt`, `argon2`, `argon2id`, `md5`, `sha256`, `sha512`, `sha1` |
| **Global Salt** | If the old app used a global salt combined with passwords before hashing, enter it here |
| **Salt Position** | Whether the salt was appended (`password + salt`) or prepended (`salt + password`) before hashing. Default: append |

When a migrated user logs in to core for the first time with their old password:

1. Core verifies the password against the legacy hash stored in `user_migration_map`
2. If valid, the password is rehashed with core's bcrypt algorithm
3. The legacy hash is cleared and `password_migrated` is set to 1
4. All future logins use the core hash — the process is invisible to the user

### Step 4: Link SAML Provider (Optional)

If the old app used SAML for authentication:

1. Configure the same IdP in **SAML Providers** (see `docs/SSO.md`)
2. In the Migration config, select that SAML provider from the dropdown
3. When users log in via SAML, core automatically looks up their email in the external DB and creates the migration mapping

This means SAML users are migrated on-the-fly without ever needing a batch sync.

### Step 5: Set a Sync Filter (Optional)

The **Filter SQL** field accepts a WHERE clause applied when querying the external table:

```sql
is_active = 1 AND role != 'bot'
```

This lets you exclude test accounts, bots, or deactivated users from the sync.

### Step 6: Test the Connection

Click **Test Connection** — it will report the number of users found in the external table (after applying the filter). If it fails, check credentials and ensure the core server can reach the external database host.

### Step 7: Save Configuration

Click **Save Configuration**. The tables `migration_source` and `user_migration_map` were created automatically by migration 1.4.

---

## Running a Sync

### Manual Sync (Admin Panel)

From the Migration page:

- **Run Full Sync** — Processes all users in the external table. Safe to run multiple times — already-synced users are skipped.
- **Incremental Sync** — Only processes users created or modified since the last sync. Uses common date columns (`created_at`, `updated_at`, `created`, `updated`) to filter.

Both show real-time progress and a summary when complete.

### Recurring Sync (Cron)

1. Enable recurring sync by clicking **Enable Recurring Sync** on the Migration page
2. Add the cron job to your server's crontab:

```bash
# Every 15 minutes
*/15 * * * * php /path/to/admin/cron/sync_users.php

# Or hourly
0 * * * * php /path/to/admin/cron/sync_users.php
```

The cron script:
- Only runs if a migration source exists and `sync_enabled = 1`
- Runs an incremental sync (since `last_sync_at`)
- Processes in batches of 500
- Logs results to the `cron_log` table
- Updates `last_sync_at` on completion

**Docker:** Add to your container's crontab or use a sidecar cron container.

### What Happens During Sync

For each user in the external database:

1. **Already mapped?** → Skip (status: `already_synced`)
2. **Email exists in core?** → Link the mapping to the existing core user (status: `synced`)
3. **New user** → Create in core:
   - Assign to least-loaded shard
   - Insert `user` row (password = NULL, is_confirmed = 1)
   - Insert `user_profile` on shard with first/last name
   - Create homedir
   - Store legacy password hash in `user_migration_map`
   - Status: `synced`
4. **Error** → Record as conflict with reason (status: `conflict`)

---

## On-the-fly Migration

### Via SAML/OAuth Login

If a SAML provider is linked to the migration source, users logging in via SAML are automatically mapped. Core looks up their email in the external database and creates the `user_migration_map` entry. This works for both existing and newly created core users.

OAuth works the same way — if the email matches an external user, the mapping is created on first login.

### Via Child App Code

Child apps that include `../admin/include/common.php` have access to the `ensure_core_user()` function:

```php
// In your child app code, after identifying the user in the old system:
$core_user_id = ensure_core_user(
    $old_user_id,           // The user's ID in the old system
    $user_email,            // Their email address
    $first_name,            // First name (optional, default '')
    $last_name,             // Last name (optional, default '')
    $old_password_hash,     // Legacy password hash (optional)
    'bcrypt'                // Hash algorithm (optional, defaults to source config)
);

if ($core_user_id) {
    // User exists in core — $core_user_id is their core user_id
    // You can now load their core session, redirect to core, etc.
} else {
    // Migration source not configured or creation failed
}
```

This function:
1. Checks the mapping table — if already synced, returns the core user ID immediately
2. Checks if the email already exists in core — if so, links and returns
3. Creates a new core user with shard assignment, profile, and homedir
4. Returns the core `user_id` or `false` on failure

**Use case:** A child app's login page verifies credentials against the old database, then calls `ensure_core_user()` to guarantee the user exists in core before redirecting them.

---

## Managing Conflicts

Conflicts occur when a user can't be automatically synced — typically because of a database error during creation. View and resolve conflicts from the Migration page:

| Resolution | Effect |
|------------|--------|
| **Link** (chain icon) | Links the mapping to an existing core user with the same email |
| **Create** (person+ icon) | Retries creating a new core user |
| **Skip** (X icon) | Marks the entry as skipped — won't be retried |

---

## Mapping Table Reference

The `user_migration_map` table is the central lookup for translating between old and new user IDs:

```sql
-- Look up core user ID from external ID
SELECT core_user_id FROM user_migration_map
WHERE source_id = 1 AND external_user_id = '42';

-- Look up external ID from core user ID
SELECT external_user_id FROM user_migration_map
WHERE core_user_id = 7;

-- Check if a user's password has been migrated
SELECT password_migrated FROM user_migration_map
WHERE external_email = 'user@example.com';
```

Child apps can query this table directly (it's in the main database) to translate IDs when needed.

---

## Password Rehash Flow

```
User enters old password on core login page
        │
        ▼
Core checks user.password → NULL (migrated user, no core password yet)
        │
        ▼
attempt_legacy_login() looks up user_migration_map
        │
        ▼
Verifies password against legacy_password_hash using legacy_hash_algo
        │
        ├── FAIL → "Invalid email or password"
        │
        └── SUCCESS
              │
              ▼
        hash_password() with core's bcrypt + hidden salt
              │
              ▼
        UPDATE user.password = new_hash
        UPDATE user_migration_map: password_migrated = 1, legacy_password_hash = NULL
              │
              ▼
        Login proceeds normally — user never notices
```

Supported legacy algorithms: `bcrypt`, `argon2`, `argon2id`, `md5`, `sha256`, `sha512`, `sha1`. The `password_salt` field supports apps that used a global salt, and `salt_position` controls whether the salt was appended (`password + salt`) or prepended (`salt + password`) before hashing.

---

## Migration Status Dashboard

The Migration page shows six status cards:

| Card | Meaning |
|------|---------|
| **Synced** | Users successfully mapped to core accounts |
| **Pending** | Mapping entries created but not yet linked to core users |
| **Conflicts** | Entries that failed and need admin review |
| **Skipped** | Entries manually skipped by admin |
| **Passwords Rehashed** | Users who have logged in and had their password converted |
| **Last Sync** | Timestamp of the most recent batch sync |

---

## Cron Schedule Recommendations

| Scenario | Schedule |
|----------|----------|
| Old app still actively creating users | Every 15 minutes |
| Old app is read-only, just catching stragglers | Hourly |
| One-time migration, then disable | Run full sync once, then disable recurring |

---

## Decommissioning the Old System

Once all users have been migrated and are logging in through core:

1. Verify the **Passwords Rehashed** count matches **Synced** (all users have logged in at least once)
2. Disable recurring sync
3. Update child apps to authenticate through core instead of the old system
4. The `user_migration_map` table remains as a permanent ID translation reference
5. The external database connection can be removed from the Migration config when no longer needed

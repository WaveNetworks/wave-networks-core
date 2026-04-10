# GDPR compliance system

Admin core provides all GDPR infrastructure. Child apps consume it via shared
helper functions loaded through common.php. All GDPR data lives in the admin
main DB so it's shared across all child apps.

## Database tables (main DB, migrations 2.5 + 2.6)

**consent_version** — versioned legal documents
| Column | Type | Notes |
|--------|------|-------|
| version_id | INT PK AUTO_INCREMENT | |
| consent_type | VARCHAR(50) | e.g. terms_of_service, privacy_policy, marketing_email |
| version_label | VARCHAR(50) | e.g. "1.0", "2.0" |
| effective_date | DATE | When this version takes effect |
| document_url | VARCHAR(500) NULL | Link to full document |
| summary | TEXT NULL | Short description shown to users |
| content | LONGTEXT NULL | Full legal text (for inline display) |
| is_active | TINYINT(1) DEFAULT 1 | Whether this version is current |
| created | DATETIME | |

Seeded with 5 initial types: terms_of_service, privacy_policy, marketing_email,
cookie_analytics, cookie_marketing (all version 1.0).

**user_consent** — immutable audit log of consent events
| Column | Type | Notes |
|--------|------|-------|
| consent_id | BIGINT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | |
| consent_type | VARCHAR(50) | |
| consent_version_id | INT NULL | FK to consent_version |
| action | ENUM('granted','withdrawn') | |
| ip_address | VARCHAR(45) NULL | |
| user_agent | VARCHAR(512) NULL | |
| created | DATETIME | |

NEVER update or delete rows — this is an append-only audit trail.

**account_deletion_request** — GDPR Article 17 right to erasure
| Column | Type | Notes |
|--------|------|-------|
| request_id | INT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | Uniqueness enforced in application code |
| reason | TEXT NULL | Optional user-provided reason |
| status | ENUM('pending','completed','cancelled') | |
| requested_at | DATETIME | |
| cancel_before | DATETIME | 30 days after requested_at |
| cancelled_at | DATETIME NULL | Set when request is cancelled |
| completed_by | VARCHAR(50) NULL | Who completed the deletion |
| completed_at | DATETIME NULL | |

**data_export_request** — GDPR Article 20 data portability
| Column | Type | Notes |
|--------|------|-------|
| export_id | INT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | |
| format | ENUM('json','csv') DEFAULT 'json' | |
| status | ENUM('pending','processing','ready','expired') | |
| file_path | VARCHAR(500) NULL | Server path to export file |
| file_size | INT UNSIGNED NULL | |
| requested_at | DATETIME | |
| completed_at | DATETIME NULL | |
| expires_at | DATETIME NULL | 7 days after completion |

**login_history** — tracks every login attempt (migration 2.6)
| Column | Type | Notes |
|--------|------|-------|
| history_id | BIGINT PK AUTO_INCREMENT | |
| user_id | INT UNSIGNED | |
| ip_address | VARCHAR(45) NULL | |
| user_agent | VARCHAR(512) NULL | |
| browser | VARCHAR(100) NULL | Parsed from user agent |
| login_method | ENUM('password','oauth','remember_me','saml','2fa') | |
| status | ENUM('success','failed') | |
| created | DATETIME | |

**device table upgrades** (migration 2.6) — added columns:
  user_id INT UNSIGNED, browser VARCHAR(100), last_used DATETIME, idx_user_id

## Helper functions

Helpers: include/common/gdprFunctions.php
  record_consent($user_id, $consent_type, $action, $version_id) — append to audit log
  get_consent_status($user_id, $consent_type) — latest status: 'granted', 'withdrawn', or null
  get_all_consent_statuses($user_id) — all types with current status
  get_consent_history($user_id) — full audit trail with version labels
  get_latest_consent_version($consent_type) — latest version row for a type
  get_all_consent_versions() — all types with their latest versions
  request_account_deletion($user_id, $reason) — creates pending request, 30-day cancel_before
  cancel_account_deletion($user_id) — cancels pending request
  get_pending_deletion($user_id) — returns active pending deletion or null
  request_data_export($user_id, $format) — creates export request (blocks duplicates)
  get_latest_export($user_id) — latest export request
  build_export_data($user_id, $shard_id) — collects user data from admin main+shard
  complete_data_export($export_id, $file_path, $file_size) — marks ready, 7-day expiry

Helpers: include/common/loginHistoryFunctions.php
  record_login($user_id, $method, $status) — insert login_history row
  get_login_history($user_id, $limit, $offset) — paginated history
  count_login_history($user_id) — total count
  check_reconsent_needed($user_id) — checks if user must re-accept terms_of_service
    or privacy_policy; returns array of types needing re-consent with version info

Helpers: include/common/deviceFunctions.php (rewritten for session management)
  parse_browser_name($ua) — extracts browser name from user agent
  register_device($cookie_id, $user_id) — stores browser, last_used
  touch_device($device_id) — updates last_used timestamp
  get_user_devices($user_id) — lists all devices with api_key join
  revoke_device($device_id, $user_id) — deletes device + api_key (user-scoped)
  revoke_all_other_devices($user_id, $current_cookie_id) — revokes all except current

## Login flow integration
loginActions.php modifications:
  LOGIN: records login history (success/failed), registers device with user_id,
    calls check_reconsent_needed() -> redirects to auth/consent.php if needed.
  REGISTER: validates agree_terms checkbox, records consent for ToS + Privacy Policy.
  VERIFY 2FA: records login history, checks re-consent.

## Re-consent flow
When consent_version gets a new row for terms_of_service or privacy_policy,
check_reconsent_needed() detects users who haven't accepted the latest version.
On next login, they're redirected to auth/consent.php which displays updated
policies and requires acceptance before entering the app.

Auth pages: auth/consent.php (admin), auth/consent.php (child-app — same UI,
  child-app template). Action: consentActions.php -> acceptReconsent.

## Child app usage
Child apps get all GDPR functions for free via common.php include chain.
Child apps build their own Privacy & Data UI (views/privacy.php) calling
the shared helper functions. Child apps add app-specific data to exports
(items, preferences, history) on top of admin's build_export_data() base.

## Admin compliance actions
Action file: include/actions/memberActions/userComplianceActions.php
  adminResetPassword     — reset user password with optional email notification
  adminRevokeSession     — revoke a single device session for a user
  adminRevokeAllSessions — revoke all device sessions for a user
  adminCancelDeletion    — cancel a pending account deletion request

Admin UI: views/user_edit.php — 5-tab compliance dashboard (Profile, Consent,
  Login History, Sessions, Data & Deletion) accessed from Users list.

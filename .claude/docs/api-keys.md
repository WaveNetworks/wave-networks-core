# Service API keys

Programmatic API access for external tools and agents. Separate from the
remember-me api_key table used for device cookies.

DB table: service_api_key (main DB). Columns: service_key_id, key_name,
  key_prefix, key_hash, scopes (JSON array), created_by, created_at,
  last_used_at, revoked_at, revoked_by.

Key format: wn_sk_ + 58-char random hash. Only the bcrypt hash is stored.
The full key is returned once at creation and never shown again.
Validation: prefix lookup (first 12 chars) narrows candidates, then bcrypt verify.
last_used_at updated on each successful validation.

Scopes: JSON array of scope strings. Available scopes defined in get_available_scopes():
  error_log:read, error_log:write, users:read.
  Child apps can extend by adding scopes to this function.
  require_api_scope($scope) checks the current key's scopes and sets $_SESSION['error']
  if missing. API action files call this before processing.

Authentication: Bearer token in Authorization header. common_api.php extracts
  the token, calls validate_service_api_key(), populates global $_SERVICE_API_KEY.

Admin view: views/api_keys.php (admin-only, page=api_keys).
  Lists keys with name, prefix, scopes, created/last-used timestamps, status.
  Create modal with name + scope checkboxes. Key revealed once after creation.
  Revoke button (soft delete via revoked_at).

Member actions (include/actions/memberActions/serviceApiKeyActions.php):
  createServiceApiKey — name + scopes[] required, returns full_key once
  revokeServiceApiKey — sets revoked_at, cannot be undone
  getServiceApiKeys   — list all keys (never returns key_hash)

Helpers: include/common/serviceApiKeyFunctions.php

## Available API scopes (from get_available_scopes())
  error_log:read  — Read error logs
  error_log:write — Resolve/unresolve error logs
  users:read      — Read user list
  costs:write     — Record cost entries (COGS, CAC, support)
  costs:read      — Read cost data and reports
  feedback:read   — Read user feedback
  feedback:write  — Submit and manage feedback
  feedback:admin  — Manage change requests and feedback status
  stripe:read     — Read Stripe transactions, revenue, and LTV data
  stripe:write    — Record Stripe transactions

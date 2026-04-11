# Service API Keys & MCP Server

## Service API keys
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

## MCP server
JSON-RPC 2.0 over stdio server for AI agent integration.
Located at: admin/mcp/server.php

Does NOT include common.php — wraps the admin HTTP API via cURL.
Authenticates using a service API key (Bearer token).

Environment variables:
  WN_API_URL — Admin API endpoint (default: http://localhost/admin/api/index.php)
  WN_API_KEY — Service API key with scopes for the tools you need (see below)

### MCP tools exposed

Error log tools (scopes: error_log:read, error_log:write):
  list_errors     — paginated error list. Params: page, per_page, level, source_app, search, status
  get_error       — single error entry. Params: error_id (required)
  resolve_error   — mark resolved. Params: error_id (required)
  unresolve_error — reopen. Params: error_id (required)
  get_error_stats — dashboard stats (no params)

Feedback tools (scopes: feedback:read, feedback:write, feedback:admin):
  list_feedback          — paginated feedback list. Params: page, per_page, feedback_type, source_app, status, search, user_id, change_request_id
  get_feedback           — single feedback entry. Params: feedback_id (required)
  get_feedback_stats     — feedback counts by status/type + source apps (no params)
  list_change_requests   — paginated change request list. Params: page, per_page, status, request_type, priority, search
  get_change_request     — single change request with grouped feedback. Params: change_request_id (required)
  create_change_request  — create new CR. Params: title (required), request_type (required: change|addition), description, priority, source_app
  update_change_request  — update CR fields. Params: change_request_id (required), title, description, status, priority, request_type
  group_feedback         — link feedback to a CR. Params: feedback_id (required), change_request_id (required)

### Tool → apiAction mapping
  list_errors → apiGetErrorLogs, get_error → apiGetErrorLog,
  resolve_error → apiResolveErrorLog, unresolve_error → apiUnresolveErrorLog,
  get_error_stats → apiGetErrorStats, list_feedback → apiListFeedback,
  get_feedback → apiGetFeedback, get_feedback_stats → apiGetFeedbackStats,
  list_change_requests → apiListChangeRequests, get_change_request → apiGetChangeRequest,
  create_change_request → apiCreateChangeRequest, update_change_request → apiUpdateChangeRequest,
  group_feedback → apiGroupFeedback

### Available API scopes (from get_available_scopes())
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

Not all scopes have MCP tools yet — cost and stripe APIs exist as apiActions
but are not wired into server.php. Add handlers to server.php as needed.

### Claude Desktop / Claude Code config
  "mcpServers": { "wave-networks-admin": {
    "command": "php",
    "args": ["/path/to/admin/mcp/server.php"],
    "env": { "WN_API_URL": "https://dswa.org/admin/api/index.php", "WN_API_KEY": "wn_sk_..." }
  }}

Logs to stderr via mcp_log(). Warns if WN_API_KEY is unset.

## API response format (from admin/api/index.php)
{
  "error":   "",
  "success": "Action completed.",
  "info":    "",
  "warning": "",
  "results": { /* $data array */ }
}

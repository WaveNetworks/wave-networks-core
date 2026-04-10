# MCP server

JSON-RPC 2.0 over stdio server for AI agent integration.
Located at: admin/mcp/server.php

Does NOT include common.php — wraps the admin HTTP API via cURL.
Authenticates using a service API key (Bearer token).

Environment variables:
  WN_API_URL — Admin API endpoint (default: http://localhost/admin/api/index.php)
  WN_API_KEY — Service API key with scopes for the tools you need (see below)

## MCP tools exposed

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

## Tool -> apiAction mapping
  list_errors -> apiGetErrorLogs, get_error -> apiGetErrorLog,
  resolve_error -> apiResolveErrorLog, unresolve_error -> apiUnresolveErrorLog,
  get_error_stats -> apiGetErrorStats, list_feedback -> apiListFeedback,
  get_feedback -> apiGetFeedback, get_feedback_stats -> apiGetFeedbackStats,
  list_change_requests -> apiListChangeRequests, get_change_request -> apiGetChangeRequest,
  create_change_request -> apiCreateChangeRequest, update_change_request -> apiUpdateChangeRequest,
  group_feedback -> apiGroupFeedback

Not all scopes have MCP tools yet — cost and stripe APIs exist as apiActions
but are not wired into server.php. Add handlers to server.php as needed.

## Claude Desktop / Claude Code config
```json
"mcpServers": { "wave-networks-admin": {
  "command": "php",
  "args": ["/path/to/admin/mcp/server.php"],
  "env": { "WN_API_URL": "https://dswa.org/admin/api/index.php", "WN_API_KEY": "wn_sk_..." }
}}
```

Logs to stderr via mcp_log(). Warns if WN_API_KEY is unset.

# Error logging system

Custom error/exception/shutdown handlers capture all PHP errors to the DB.
errorHandler.php registers three handlers via set_error_handler, set_exception_handler,
register_shutdown_function. Errors are classified: FATAL, ERROR, WARNING, INFO.
Falls back to error_log() if DB is unavailable or log_error_to_db() not yet loaded.
In production, errors are suppressed from display. In development (ENVIRONMENT=development),
errors render to screen.

DB table: error_log (main DB). Columns: error_id, level, message, file, line,
  stack_trace, context_json, source_app, page, request_uri, request_method,
  user_id, ip_address, user_agent, php_version, memory_usage, resolved_at,
  resolved_by, created.

log_error_to_db() deduplicates per-request via md5(file:line:message).
Detects source_app from file path (regex matches directory name before include/views/etc).
context_json captures: GET params, POST action name, session user info, memory stats.

Admin view: views/error_log.php (admin-only, page=error_log).
  Filterable by level, source_app, status (open/resolved), free-text search.
  Paginated, expandable detail rows with stack trace and context JSON.

Member actions (include/actions/memberActions/errorLogActions.php):
  getErrorLogs    — paginated list with filters, stats, sources
  deleteErrorLog  — delete single entry by error_id
  clearErrorLogs  — bulk delete older than N days (default 30)
  resolveErrorLog — mark resolved (sets resolved_at + resolved_by)
  unresolveErrorLog — reopen

API actions (include/actions/apiActions/errorLogApiActions.php):
  Authenticated via service API key (Bearer token). Scope-gated.
  apiGetErrorLogs     — list (scope: error_log:read)
  apiGetErrorLog      — single entry (scope: error_log:read)
  apiResolveErrorLog  — resolve (scope: error_log:write)
  apiUnresolveErrorLog — reopen (scope: error_log:write)
  apiGetErrorStats    — dashboard stats + source list (scope: error_log:read)

Cron: cron/days/1/cleanup_error_log.php — deletes entries older than 30 days.

Child app benefit: errors from child apps are captured automatically because
  errorHandler.php is loaded via common.php. source_app is auto-detected from
  the file path, so child app errors appear in the admin error log tagged by app name.

Helpers: include/common/errorHandler.php, include/common/errorLogFunctions.php

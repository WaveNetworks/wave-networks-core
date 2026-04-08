#!/usr/bin/env php
<?php
/**
 * MCP Server for Wave Networks Admin
 * JSON-RPC 2.0 over stdio.
 * Wraps the admin HTTP API — does NOT include common.php.
 *
 * Configuration via environment variables:
 *   WN_API_URL  — Admin API endpoint (default: http://localhost/admin/api/index.php)
 *   WN_API_KEY  — Service API key with error_log + feedback scopes
 *
 * Usage:
 *   php server.php
 *
 * Claude Desktop config example:
 *   {
 *     "mcpServers": {
 *       "wave-networks-admin": {
 *         "command": "php",
 *         "args": ["/path/to/admin/mcp/server.php"],
 *         "env": {
 *           "WN_API_URL": "http://localhost/admin/api/index.php",
 *           "WN_API_KEY": "wn_sk_..."
 *         }
 *       }
 *     }
 *   }
 */

$MCP_API_URL = getenv('WN_API_URL') ?: 'http://localhost/admin/api/index.php';
$MCP_API_KEY = getenv('WN_API_KEY') ?: '';

// ─── Tool Definitions ────────────────────────────────────────────────────────

$tools = [
    [
        'name'        => 'list_errors',
        'description' => 'List error log entries with optional filters. Returns paginated results.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'page'       => ['type' => 'integer', 'description' => 'Page number (default 1)'],
                'per_page'   => ['type' => 'integer', 'description' => 'Items per page (default 50, max 100)'],
                'level'      => ['type' => 'string',  'description' => 'Filter by level: DEBUG, INFO, WARNING, ERROR, FATAL'],
                'source_app' => ['type' => 'string',  'description' => 'Filter by source application name'],
                'search'     => ['type' => 'string',  'description' => 'Search in error message and file path'],
                'status'     => ['type' => 'string',  'description' => 'Filter by status: open, resolved'],
            ],
        ],
    ],
    [
        'name'        => 'get_error',
        'description' => 'Get a single error log entry with full details including stack trace and context.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'error_id' => ['type' => 'integer', 'description' => 'The error log entry ID'],
            ],
            'required' => ['error_id'],
        ],
    ],
    [
        'name'        => 'resolve_error',
        'description' => 'Mark an error log entry as resolved.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'error_id' => ['type' => 'integer', 'description' => 'The error log entry ID to resolve'],
            ],
            'required' => ['error_id'],
        ],
    ],
    [
        'name'        => 'unresolve_error',
        'description' => 'Reopen a previously resolved error log entry.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'error_id' => ['type' => 'integer', 'description' => 'The error log entry ID to reopen'],
            ],
            'required' => ['error_id'],
        ],
    ],
    [
        'name'        => 'get_error_stats',
        'description' => 'Get error log statistics: counts by level (today), resolved/open counts, total, and available source apps.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => (object)[],
        ],
    ],

    // ─── Feedback Tools ─────────────────────────────────────────────────────
    [
        'name'        => 'list_feedback',
        'description' => 'List user feedback entries with optional filters. Returns paginated results with user email and role.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'page'              => ['type' => 'integer', 'description' => 'Page number (default 1)'],
                'per_page'          => ['type' => 'integer', 'description' => 'Items per page (default 25, max 100)'],
                'feedback_type'     => ['type' => 'string',  'description' => 'Filter by type: bug, suggestion, general'],
                'source_app'        => ['type' => 'string',  'description' => 'Filter by source application name'],
                'status'            => ['type' => 'string',  'description' => 'Filter by status: new, reviewed, grouped, dismissed'],
                'search'            => ['type' => 'string',  'description' => 'Search in feedback message and page URL'],
                'user_id'           => ['type' => 'integer', 'description' => 'Filter by user ID'],
                'change_request_id' => ['type' => 'integer', 'description' => 'Filter by linked change request ID'],
            ],
        ],
    ],
    [
        'name'        => 'get_feedback',
        'description' => 'Get a single feedback entry with full details.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'feedback_id' => ['type' => 'integer', 'description' => 'The feedback entry ID'],
            ],
            'required' => ['feedback_id'],
        ],
    ],
    [
        'name'        => 'get_feedback_stats',
        'description' => 'Get feedback statistics: counts by status and type, total, and available source apps.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => (object)[],
        ],
    ],
    [
        'name'        => 'list_change_requests',
        'description' => 'List change requests/additions with optional filters. Returns paginated results with grouped feedback count.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'page'         => ['type' => 'integer', 'description' => 'Page number (default 1)'],
                'per_page'     => ['type' => 'integer', 'description' => 'Items per page (default 25, max 100)'],
                'status'       => ['type' => 'string',  'description' => 'Filter: proposed, approved, in_progress, completed, paused, rejected'],
                'request_type' => ['type' => 'string',  'description' => 'Filter: change, addition'],
                'priority'     => ['type' => 'string',  'description' => 'Filter: low, medium, high, critical'],
                'search'       => ['type' => 'string',  'description' => 'Search in title and description'],
            ],
        ],
    ],
    [
        'name'        => 'get_change_request',
        'description' => 'Get a single change request with full details and all grouped feedback entries.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'change_request_id' => ['type' => 'integer', 'description' => 'The change request ID'],
            ],
            'required' => ['change_request_id'],
        ],
    ],
    [
        'name'        => 'create_change_request',
        'description' => 'Create a new change request or addition. Use after analyzing feedback to group related items.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'title'        => ['type' => 'string', 'description' => 'Short descriptive title'],
                'description'  => ['type' => 'string', 'description' => 'Detailed description of the change or addition'],
                'request_type' => ['type' => 'string', 'description' => 'Type: change (modify existing) or addition (new feature)'],
                'priority'     => ['type' => 'string', 'description' => 'Priority: low, medium, high, critical (default medium)'],
                'source_app'   => ['type' => 'string', 'description' => 'Related application name'],
            ],
            'required' => ['title', 'request_type'],
        ],
    ],
    [
        'name'        => 'update_change_request',
        'description' => 'Update a change request status, priority, or details. Use to mark as in_progress when working on it, or completed when done.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'change_request_id' => ['type' => 'integer', 'description' => 'The change request ID'],
                'title'             => ['type' => 'string',  'description' => 'Updated title'],
                'description'       => ['type' => 'string',  'description' => 'Updated description'],
                'status'            => ['type' => 'string',  'description' => 'New status: proposed, approved, in_progress, completed, paused, rejected'],
                'priority'          => ['type' => 'string',  'description' => 'New priority: low, medium, high, critical'],
                'request_type'      => ['type' => 'string',  'description' => 'Change type: change, addition'],
            ],
            'required' => ['change_request_id'],
        ],
    ],
    [
        'name'        => 'group_feedback',
        'description' => 'Link a feedback entry to a change request. The feedback status is set to "grouped". Use after creating a change request to associate related feedback.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'feedback_id'       => ['type' => 'integer', 'description' => 'The feedback entry ID to group'],
                'change_request_id' => ['type' => 'integer', 'description' => 'The change request ID to group with'],
            ],
            'required' => ['feedback_id', 'change_request_id'],
        ],
    ],
];

// ─── HTTP API Helper ─────────────────────────────────────────────────────────

function api_call($action, $params = []) {
    global $MCP_API_URL, $MCP_API_KEY;

    $params['action'] = $action;

    $ch = curl_init($MCP_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $MCP_API_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['error' => "HTTP request failed: $curlErr", 'results' => []];
    }

    $decoded = json_decode($body, true);
    if (!$decoded) {
        return ['error' => "Invalid JSON response (HTTP $httpCode)", 'results' => []];
    }

    return $decoded;
}

// ─── JSON-RPC Helpers ────────────────────────────────────────────────────────

function make_result($id, $result) {
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => $result,
    ];
}

function make_error($id, $code, $message) {
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => [
            'code'    => $code,
            'message' => $message,
        ],
    ];
}

function make_tool_result($id, $content, $isError = false) {
    return make_result($id, [
        'content' => [
            [
                'type' => 'text',
                'text' => is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
        ],
        'isError' => $isError,
    ]);
}

// ─── Tool Handlers ───────────────────────────────────────────────────────────

function handle_tool_call($id, $toolName, $args) {
    switch ($toolName) {
        case 'list_errors':
            $params = [];
            if (isset($args['page']))       $params['page']       = $args['page'];
            if (isset($args['per_page']))   $params['per_page']   = $args['per_page'];
            if (isset($args['level']))      $params['level']      = $args['level'];
            if (isset($args['source_app'])) $params['source_app'] = $args['source_app'];
            if (isset($args['search']))     $params['search']     = $args['search'];
            if (isset($args['status']))     $params['status']     = $args['status'];

            $resp = api_call('apiGetErrorLogs', $params);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results'] ?? []);

        case 'get_error':
            if (!isset($args['error_id'])) {
                return make_tool_result($id, 'error_id is required', true);
            }
            $resp = api_call('apiGetErrorLog', ['error_id' => $args['error_id']]);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results']['error_log'] ?? []);

        case 'resolve_error':
            if (!isset($args['error_id'])) {
                return make_tool_result($id, 'error_id is required', true);
            }
            $resp = api_call('apiResolveErrorLog', ['error_id' => $args['error_id']]);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['success'] ?? 'Error resolved.');

        case 'unresolve_error':
            if (!isset($args['error_id'])) {
                return make_tool_result($id, 'error_id is required', true);
            }
            $resp = api_call('apiUnresolveErrorLog', ['error_id' => $args['error_id']]);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['success'] ?? 'Error reopened.');

        case 'get_error_stats':
            $resp = api_call('apiGetErrorStats');
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results'] ?? []);

        // ─── Feedback Handlers ──────────────────────────────────────────────

        case 'list_feedback':
            $params = [];
            foreach (['page','per_page','feedback_type','source_app','status','search','user_id','change_request_id'] as $k) {
                if (isset($args[$k])) $params[$k] = $args[$k];
            }
            $resp = api_call('apiListFeedback', $params);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results'] ?? []);

        case 'get_feedback':
            if (!isset($args['feedback_id'])) {
                return make_tool_result($id, 'feedback_id is required', true);
            }
            $resp = api_call('apiGetFeedback', ['feedback_id' => $args['feedback_id']]);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results']['feedback'] ?? []);

        case 'get_feedback_stats':
            $resp = api_call('apiGetFeedbackStats');
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results'] ?? []);

        case 'list_change_requests':
            $params = [];
            foreach (['page','per_page','status','request_type','priority','search'] as $k) {
                if (isset($args[$k])) $params[$k] = $args[$k];
            }
            $resp = api_call('apiListChangeRequests', $params);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results'] ?? []);

        case 'get_change_request':
            if (!isset($args['change_request_id'])) {
                return make_tool_result($id, 'change_request_id is required', true);
            }
            $resp = api_call('apiGetChangeRequest', ['change_request_id' => $args['change_request_id']]);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results']['change_request'] ?? []);

        case 'create_change_request':
            if (!isset($args['title']) || !isset($args['request_type'])) {
                return make_tool_result($id, 'title and request_type are required', true);
            }
            $params = [];
            foreach (['title','description','request_type','priority','source_app'] as $k) {
                if (isset($args[$k])) $params[$k] = $args[$k];
            }
            $resp = api_call('apiCreateChangeRequest', $params);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['results'] ?? $resp['success'] ?? 'Change request created.');

        case 'update_change_request':
            if (!isset($args['change_request_id'])) {
                return make_tool_result($id, 'change_request_id is required', true);
            }
            $params = [];
            foreach (['change_request_id','title','description','status','priority','request_type'] as $k) {
                if (isset($args[$k])) $params[$k] = $args[$k];
            }
            $resp = api_call('apiUpdateChangeRequest', $params);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['success'] ?? 'Change request updated.');

        case 'group_feedback':
            if (!isset($args['feedback_id']) || !isset($args['change_request_id'])) {
                return make_tool_result($id, 'feedback_id and change_request_id are required', true);
            }
            $resp = api_call('apiGroupFeedback', [
                'feedback_id'       => $args['feedback_id'],
                'change_request_id' => $args['change_request_id'],
            ]);
            if (!empty($resp['error'])) {
                return make_tool_result($id, $resp['error'], true);
            }
            return make_tool_result($id, $resp['success'] ?? 'Feedback grouped.');

        default:
            return make_error($id, -32601, "Unknown tool: $toolName");
    }
}

// ─── Main Loop ───────────────────────────────────────────────────────────────

// Disable output buffering for realtime stdio
if (function_exists('ob_implicit_flush')) {
    ob_implicit_flush(true);
}
while (ob_get_level()) {
    ob_end_flush();
}

// Log to stderr (visible in MCP client logs, not in protocol)
function mcp_log($msg) {
    fwrite(STDERR, "[wave-networks-mcp] $msg\n");
}

mcp_log("Starting MCP server. API URL: $MCP_API_URL");

if (empty($MCP_API_KEY)) {
    mcp_log("WARNING: WN_API_KEY is not set. API calls will fail with 401.");
    mcp_log("  Set env var WN_API_KEY=wn_sk_... in your MCP client config.");
}

// Startup connectivity test — runs once to catch config/deploy issues early.
// Outage 2026-04-07: deploy was broken (GITHUB_TOKEN couldn't access wave-networks-core),
// leaving stale/missing admin code on the server and causing PHP errors in the API bootstrap.
// Fix: use CROSS_REPO_PAT in .github/workflows/deploy.yml of the child app repos.
// Also verify WN_API_URL and WN_API_KEY are set correctly in MCP client config.
(function () use ($MCP_API_URL, $MCP_API_KEY) {
    $ch = curl_init($MCP_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['action' => 'apiGetErrorStats']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $MCP_API_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        mcp_log("STARTUP CHECK FAILED — curl error: $curlErr");
        mcp_log("  Verify WN_API_URL is reachable: $MCP_API_URL");
        return;
    }
    if ($code === 401 || $code === 403) {
        mcp_log("STARTUP CHECK FAILED — HTTP $code (auth error). Check WN_API_KEY.");
        return;
    }
    if ($code !== 200) {
        mcp_log("STARTUP CHECK WARNING — HTTP $code from API.");
        mcp_log("  May indicate a PHP error in the bootstrap. Check server error logs.");
        mcp_log("  Response preview: " . substr($body, 0, 300));
        return;
    }
    $decoded = json_decode($body, true);
    if (!$decoded) {
        mcp_log("STARTUP CHECK WARNING — API returned non-JSON (HTTP $code).");
        mcp_log("  Likely a PHP error in common_api.php or admin bootstrap chain.");
        mcp_log("  Response preview: " . substr($body, 0, 300));
        return;
    }
    if (!empty($decoded['error'])) {
        mcp_log("STARTUP CHECK WARNING — API error: " . $decoded['error']);
    } else {
        mcp_log("STARTUP CHECK OK — API is reachable and responding correctly.");
    }
})();

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;

    $request = json_decode($line, true);
    if (!$request) {
        mcp_log("Invalid JSON received: $line");
        continue;
    }

    $id     = $request['id'] ?? null;
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];

    $response = null;

    switch ($method) {
        case 'initialize':
            $response = make_result($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities'   => [
                    'tools' => (object)[],
                ],
                'serverInfo' => [
                    'name'    => 'wave-networks-admin',
                    'version' => '1.0.0',
                ],
            ]);
            mcp_log("Initialized.");
            break;

        case 'notifications/initialized':
            // No response for notifications
            continue 2;

        case 'tools/list':
            $response = make_result($id, ['tools' => $tools]);
            break;

        case 'tools/call':
            $toolName = $params['name'] ?? '';
            $args     = $params['arguments'] ?? [];
            mcp_log("Tool call: $toolName");
            $response = handle_tool_call($id, $toolName, $args);
            break;

        case 'ping':
            $response = make_result($id, (object)[]);
            break;

        default:
            $response = make_error($id, -32601, "Method not found: $method");
            mcp_log("Unknown method: $method");
    }

    if ($response !== null) {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, $json . "\n");
        fflush(STDOUT);
    }
}

mcp_log("Stdin closed. Exiting.");

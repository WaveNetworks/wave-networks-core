#!/usr/bin/env php
<?php
/**
 * MCP Server for Wave Networks Admin
 * JSON-RPC 2.0 over stdio.
 * Wraps the admin HTTP API — does NOT include common.php.
 *
 * Configuration via environment variables:
 *   WN_API_URL  — Admin API endpoint (default: http://localhost/admin/api/index.php)
 *   WN_API_KEY  — Service API key with error_log:read + error_log:write scopes
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
}

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

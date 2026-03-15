<?php
/**
 * errorHandler.php
 * Custom error, exception, and shutdown handlers.
 * Logs errors to the error_log DB table with full context.
 * Falls back to error_log() if DB is unavailable.
 */

/**
 * Map PHP error constant to severity level string.
 */
function wn_error_level($errno) {
    switch ($errno) {
        case E_ERROR:
        case E_PARSE:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
            return 'FATAL';
        case E_WARNING:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_USER_WARNING:
            return 'WARNING';
        case E_NOTICE:
        case E_STRICT:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            return 'INFO';
        case E_USER_ERROR:
            return 'ERROR';
        default:
            return 'ERROR';
    }
}

/**
 * Custom error handler — logs to DB, suppresses in production.
 */
function wn_error_handler($errno, $errstr, $errfile, $errline) {
    // Respect error_reporting() — suppressed errors (@) return 0
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $level = wn_error_level($errno);

    // Build a stack trace (skip this handler frame)
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    array_shift($bt); // remove wn_error_handler itself
    $trace = '';
    foreach ($bt as $i => $frame) {
        $trace .= '#' . $i . ' ';
        $trace .= ($frame['file'] ?? '[internal]') . '(' . ($frame['line'] ?? '?') . '): ';
        if (!empty($frame['class'])) {
            $trace .= $frame['class'] . $frame['type'];
        }
        $trace .= ($frame['function'] ?? '') . "()\n";
    }

    if (function_exists('log_error_to_db')) {
        log_error_to_db($level, $errstr, $errfile, $errline, $trace ?: null);
    } else {
        error_log("[$level] $errstr in $errfile on line $errline");
    }

    $env = getenv('ENVIRONMENT') ?: 'production';
    if ($env === 'development') {
        return false; // let PHP display it
    }

    return true; // suppress display in production
}

/**
 * Custom exception handler — logs to DB, shows friendly message in production.
 */
function wn_exception_handler($exception) {
    $level = 'ERROR';
    $message = $exception->getMessage();
    $file = $exception->getFile();
    $line = $exception->getLine();
    $trace = $exception->getTraceAsString();

    if (function_exists('log_error_to_db')) {
        log_error_to_db($level, $message, $file, $line, $trace);
    } else {
        error_log("Uncaught exception: $message in $file:$line");
    }

    $env = getenv('ENVIRONMENT') ?: 'production';
    if ($env === 'development') {
        echo '<h1>Error</h1>';
        echo '<p>' . htmlspecialchars($message) . '</p>';
        echo '<pre>' . htmlspecialchars($trace) . '</pre>';
    } else {
        http_response_code(500);
        echo 'An unexpected error occurred. Please try again later.';
    }
    exit;
}

/**
 * Shutdown handler — catches fatal errors that bypass set_error_handler.
 */
function wn_shutdown_handler() {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    // Only handle fatal error types
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($error['type'], $fatal_types)) {
        return;
    }

    if (function_exists('log_error_to_db')) {
        log_error_to_db('FATAL', $error['message'], $error['file'], $error['line'], null);
    } else {
        error_log("[FATAL] {$error['message']} in {$error['file']} on line {$error['line']}");
    }
}

set_error_handler('wn_error_handler');
set_exception_handler('wn_exception_handler');
register_shutdown_function('wn_shutdown_handler');

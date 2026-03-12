<?php
/**
 * errorHandler.php
 * Custom error and exception handlers.
 */

/**
 * Custom error handler — converts errors to exceptions in development,
 * logs silently in production.
 */
function wn_error_handler($errno, $errstr, $errfile, $errline) {
    $env = getenv('ENVIRONMENT') ?: 'production';

    if ($env === 'development') {
        // In dev, show errors
        return false; // let PHP handle it with display_errors
    }

    // In production, log and suppress
    error_log("[$errno] $errstr in $errfile on line $errline");
    return true;
}

/**
 * Custom exception handler — shows friendly message in production.
 */
function wn_exception_handler($exception) {
    $env = getenv('ENVIRONMENT') ?: 'production';

    error_log('Uncaught exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());

    if ($env === 'development') {
        echo '<h1>Error</h1>';
        echo '<p>' . htmlspecialchars($exception->getMessage()) . '</p>';
        echo '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
    } else {
        http_response_code(500);
        echo 'An unexpected error occurred. Please try again later.';
    }
    exit;
}

set_error_handler('wn_error_handler');
set_exception_handler('wn_exception_handler');

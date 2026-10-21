<?php
/**
 * runtimeApiActions.php
 * Public API action, authenticated via service API key (Bearer token).
 * What this app actually runs on, so nokemo's daily security feed (openclaw-ops
 * scripts/security-feed.py, nokemo task #2566) can match PHP advisories against
 * the real serving version instead of guessing from a header.
 * Action: apiGetRuntime (scope: error_log:read — the scope every monitoring key already holds)
 *   -> php_version, php_major_minor, sapi, os_family, extensions[]
 * Versions only. Never paths, hostnames or config values.
 */

if (($action ?? null) == 'apiGetRuntime') {
    if (require_api_scope('error_log:read')) {
        $data['php_version']     = PHP_VERSION;
        $data['php_major_minor'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $data['sapi']            = PHP_SAPI;
        $data['os_family']       = PHP_OS_FAMILY;
        $ext = get_loaded_extensions();
        sort($ext);
        $data['extensions']      = $ext;
        $_SESSION['success'] = 'OK';
    }
}

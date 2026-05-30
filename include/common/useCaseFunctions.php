<?php
/**
 * useCaseFunctions.php
 * Shared helpers for the Playwright use_case screenshot pipeline.
 *
 * Screenshots are captured on the test-runner host and uploaded to the
 * admin server (apiUploadUseCaseScreenshot), stored under
 * $files_location/use_case_screenshots/{run_id}/{name}.png, and served
 * back to the admin UI via use_case_screenshot.php. This mirrors the
 * feedback-screenshot pattern (outside-webroot storage + PHP proxy).
 */

/**
 * Resolve $files_location/use_case_screenshots/ regardless of how config
 * was loaded. Mirrors feedback_screenshots_base_dir().
 * @return string|null absolute base directory, or null if config missing
 */
function use_case_screenshots_base_dir() {
    global $files_location;
    if (empty($files_location)) {
        if (getenv('FILES_LOCATION')) {
            $files_location = getenv('FILES_LOCATION');
        } else {
            $files_location = __DIR__ . '/../../../../files/';
        }
    }
    if (empty($files_location)) return null;
    $base = rtrim($files_location, '/') . '/use_case_screenshots/';
    if (!is_dir($base)) { @mkdir($base, 0755, true); }
    return $base;
}

/**
 * Sanitise a screenshot filename to a safe basename (no path traversal).
 * Only PNGs are stored/served. Returns null if the name is unusable.
 */
function use_case_screenshot_safe_name($name) {
    $name = basename((string)$name);
    $name = preg_replace('#[^A-Za-z0-9_.\-]#', '', $name);
    if ($name === '' || strpos($name, '..') !== false) return null;
    if (!preg_match('/\.png$/i', $name)) return null;
    return $name;
}

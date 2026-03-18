<?php
/**
 * updateCheckFunctions.php
 * Checks subtheme.com for the latest Wave Networks versions.
 * Caches results to a local file to avoid excessive API calls.
 */

/**
 * Check for available updates. Uses a 24-hour file cache.
 *
 * @param bool $force  If true, bypass cache and fetch fresh data
 * @return array|null  Update info or null on failure
 *   [
 *     'admin'      => ['current' => '1.0.0', 'latest' => '1.2.0', 'outdated' => true, ...],
 *     'child_apps' => [
 *       'child-app' => ['current' => '1.0.0', 'latest' => '1.1.0', 'outdated' => true, ...],
 *       'p3sig'     => ['current' => '1.0.0', 'latest' => '1.1.0', 'outdated' => true, ...],
 *     ],
 *     'checked_at' => '2026-03-17T10:00:00+00:00',
 *   ]
 */
function check_for_updates($force = false) {
    $cacheFile = __DIR__ . '/../../config/.update_check_cache.json';
    $cacheTTL = 86400; // 24 hours

    // Try cache first
    if (!$force && file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['checked_at'])) {
            $cacheAge = time() - strtotime($cached['checked_at']);
            if ($cacheAge < $cacheTTL) {
                return $cached;
            }
        }
    }

    // Fetch from subtheme.com
    $apiUrl = 'https://subtheme.com/api/versions';
    $result = fetch_version_api($apiUrl);

    if (!$result) {
        // Return stale cache if available
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) {
                $cached['stale'] = true;
                return $cached;
            }
        }
        return null;
    }

    // Build comparison
    $currentAdmin = defined('WN_ADMIN_VERSION') ? WN_ADMIN_VERSION : '0.0.0';
    $detectedApps = detect_child_app_versions();

    $components = $result['components'] ?? [];

    $latestAdmin = $components['admin']['version'] ?? '0.0.0';
    $latestChildApp = $components['child-app']['version'] ?? '0.0.0';

    // Build child apps comparison — each detected app compared against the template version
    $childApps = [];
    foreach ($detectedApps as $appName => $appVersion) {
        $childApps[$appName] = [
            'current' => $appVersion,
            'latest' => $latestChildApp,
            'outdated' => version_compare($appVersion, $latestChildApp, '<'),
            'date' => $components['child-app']['date'] ?? null,
            'summary' => $components['child-app']['summary'] ?? null,
            'migration_required' => $components['child-app']['migration_required'] ?? false,
        ];
    }

    $updateInfo = [
        'admin' => [
            'current' => $currentAdmin,
            'latest' => $latestAdmin,
            'outdated' => version_compare($currentAdmin, $latestAdmin, '<'),
            'date' => $components['admin']['date'] ?? null,
            'summary' => $components['admin']['summary'] ?? null,
            'migration_required' => $components['admin']['migration_required'] ?? false,
        ],
        'child_apps' => $childApps,
        'checked_at' => date('c'),
        'stale' => false,
    ];

    // Write cache
    $configDir = dirname($cacheFile);
    if (is_dir($configDir) && is_writable($configDir)) {
        file_put_contents($cacheFile, json_encode($updateInfo, JSON_PRETTY_PRINT));
    }

    return $updateInfo;
}

/**
 * Fetch the version API response from subtheme.com.
 *
 * @param string $url
 * @return array|null
 */
function fetch_version_api($url) {
    // Prefer cURL if available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'WaveNetworks-UpdateCheck/' . (defined('WN_ADMIN_VERSION') ? WN_ADMIN_VERSION : '0.0.0'),
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            return json_decode($response, true);
        }
        return null;
    }

    // Fallback to file_get_contents
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'WaveNetworks-UpdateCheck/' . (defined('WN_ADMIN_VERSION') ? WN_ADMIN_VERSION : '0.0.0'),
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response) {
        return json_decode($response, true);
    }
    return null;
}

/**
 * Get the changelog for a specific component from the version API.
 *
 * @param string $component  'admin' or 'child-app'
 * @param string|null $fromVersion  Only return releases after this version
 * @return array|null
 */
function fetch_changelog($component, $fromVersion = null) {
    $url = 'https://subtheme.com/api/versions?component=' . urlencode($component);
    if ($fromVersion) {
        $url .= '&from=' . urlencode($fromVersion);
    }
    return fetch_version_api($url);
}

/**
 * Detect all child app versions by scanning sibling directories.
 * Admin doesn't include child-app definition files, so this reads them
 * directly via file_get_contents + regex.
 *
 * @return array  ['child-app' => '1.0.0', 'p3sig' => '1.0.0', ...]
 */
function detect_child_app_versions() {
    $adminDir = realpath(__DIR__ . '/../../');       // admin/
    $webroot  = dirname($adminDir);                  // public_html/
    $apps     = [];

    // Skip known non-app directories
    $skip = ['.' => 1, '..' => 1, 'admin' => 1, 'site' => 1, '.claude' => 1];

    foreach (scandir($webroot) as $dir) {
        if (isset($skip[$dir])) continue;
        $defFile = $webroot . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'include' . DIRECTORY_SEPARATOR . 'definition.php';
        if (!file_exists($defFile)) continue;

        // Read the file and extract the version constant
        $contents = file_get_contents($defFile);
        if ($contents && preg_match("/define\s*\(\s*['\"]WN_CHILD_APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $contents, $matches)) {
            $apps[$dir] = $matches[1];
        }
    }

    return $apps;
}

/**
 * Detect a single child app version (convenience wrapper).
 * Returns the first found version, or '0.0.0' if none.
 *
 * @return string
 */
function detect_child_app_version() {
    if (defined('WN_CHILD_APP_VERSION')) {
        return WN_CHILD_APP_VERSION;
    }
    $apps = detect_child_app_versions();
    return $apps ? reset($apps) : '0.0.0';
}

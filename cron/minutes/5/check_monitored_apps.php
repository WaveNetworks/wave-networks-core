<?php
/**
 * check_monitored_apps.php
 * Polls all active registered apps for errors every 5 minutes.
 * Runs via admin cron but bootstraps the nokemo child app for monitoring functions.
 */

// Bootstrap the child app (includes admin + child DB connections + monitoring functions)
$childCommon = __DIR__ . '/../../../../nokemo/include/common_api.php';
if (!file_exists($childCommon)) {
    echo "    Child app common_api.php not found, skipping.\n";
    return;
}
require_once $childCommon;

// Get all active registered apps
try {
    $stmt = child_db_query_prepared(
        "SELECT app_id, app_name FROM registered_app WHERE status = 'active'",
        []
    );
    if (!$stmt) {
        echo "    Failed to query registered apps.\n";
        return;
    }
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "    DB error: " . $e->getMessage() . "\n";
    return;
}

if (empty($apps)) {
    echo "    No active registered apps.\n";
    return;
}

echo "    Checking " . count($apps) . " registered app(s)...\n";

foreach ($apps as $app) {
    $appId   = (int) $app['app_id'];
    $appName = $app['app_name'];

    echo "    Checking: $appName (ID $appId)... ";

    try {
        $result = check_app_errors($appId);

        if (!$result['success']) {
            echo "ERROR: " . ($result['error'] ?? 'unknown') . "\n";
            // Record a failed check event
            record_monitoring_event($appId, 'check_failed', 'Check failed: ' . ($result['error'] ?? 'unknown'), [
                'error' => $result['error'] ?? 'unknown',
            ]);
            continue;
        }

        $errors = $result['errors'] ?? [];
        $diff = get_app_error_diff($appId, $errors);

        // Record the check event
        $newCount = count($diff['new']);
        $totalOpen = count($errors);
        record_monitoring_event($appId, 'check', "{$newCount} new, {$totalOpen} total open", [
            'total_open'   => $totalOpen,
            'error_count'  => $totalOpen,
            'errors_new'   => $newCount,
            'new'          => count($diff['new']),
            'resolved'     => count($diff['resolved']),
            'persisting'   => count($diff['persisting']),
        ]);

        // Update last_checked
        child_db_query_prepared(
            "UPDATE registered_app SET last_checked = NOW(), last_error_count = ? WHERE app_id = ?",
            [count($errors), $appId]
        );

        echo "OK — {$newCount} new, {$totalOpen} total open\n";

        // If new errors, record alert event
        if ($newCount > 0) {
            record_monitoring_event($appId, 'alert', "{$newCount} new errors detected", [
                'error_count' => $totalOpen,
                'errors_new'  => $newCount,
                'new_errors'  => $diff['new'],
            ]);
        }

    } catch (Exception $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
}

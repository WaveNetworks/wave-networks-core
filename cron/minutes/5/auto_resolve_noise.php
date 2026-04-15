<?php
/**
 * auto_resolve_noise.php — Auto-resolve known noise errors every 5 minutes.
 * Bot scanners, misrouted admin paths, favicon 404s, etc.
 * Runs via admin cron on all servers — no child app bootstrap needed.
 */

if (!function_exists('db_query_prepared')) {
    echo "    Skipped — admin DB functions not available.\n";
    return;
}

global $db;
if (!$db) {
    echo "    Skipped — admin \$db not available.\n";
    return;
}

$noisePatterns = [
    '%/wp-admin%',
    '%/wp-login%',
    '%/xmlrpc%',
    '%/.env%',
    '%/admin/upload%',
    '%/admin/function%',
    '%/admin/uploads%',
    '%/admin/config%',
    '%favicon.ico%',
    '%favicon.svg%',
    '%/admin/api/index.php%',
];

$conditions = [];
$params = [];
foreach ($noisePatterns as $pattern) {
    $conditions[] = "message LIKE ?";
    $params[] = $pattern;
}

if (empty($conditions)) return;

$where = implode(' OR ', $conditions);
$sql = "SELECT error_id FROM error_log WHERE status = 'open' AND ($where) LIMIT 200";

try {
    $r = db_query_prepared($sql, $params);
    if (!$r) {
        echo "    No noise errors to resolve.\n";
        return;
    }
    $errors = db_fetch_all($r);

    if (empty($errors)) {
        echo "    No noise errors to resolve.\n";
        return;
    }

    $resolved = 0;
    foreach ($errors as $error) {
        db_query_prepared(
            "UPDATE error_log SET status = 'resolved', resolved_at = NOW(), resolved_by = 'auto-noise-filter' WHERE error_id = ?",
            [intval($error['error_id'])]
        );
        $resolved++;
    }

    echo "    Auto-resolved $resolved noise error(s).\n";
} catch (Exception $e) {
    echo "    Error: " . $e->getMessage() . "\n";
}

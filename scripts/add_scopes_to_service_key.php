<?php
/**
 * add_scopes_to_service_key.php
 * One-shot CLI helper to add scopes to an existing service_api_key row.
 *
 * Usage (on a host with admin core deployed):
 *   php admin/scripts/add_scopes_to_service_key.php <key_prefix> <scope> [scope...]
 *
 * Example:
 *   php admin/scripts/add_scopes_to_service_key.php wn_sk_fYiOEv actions:read tests:write
 *
 * Idempotent — adding a scope that's already there is a no-op.
 * Reads admin/config/config.php; no session, no actions glob.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php add_scopes_to_service_key.php <key_prefix> <scope> [scope...]\n");
    exit(2);
}

$prefix = $argv[1];
$new_scopes = array_slice($argv, 2);

require_once __DIR__ . '/../include/common_readonly.php';

global $db;

$valid = array_keys(get_available_scopes());
foreach ($new_scopes as $s) {
    if (!in_array($s, $valid, true)) {
        fwrite(STDERR, "ERROR: '$s' is not in get_available_scopes(): "
            . implode(', ', $valid) . "\n");
        exit(3);
    }
}

$row = db_query_prepared(
    "SELECT service_key_id, key_name, scopes FROM service_api_key
     WHERE key_prefix = ? AND revoked_at IS NULL LIMIT 1",
    [$prefix]
);
$key = $row ? $row->fetch(PDO::FETCH_ASSOC) : null;
if (!$key) {
    fwrite(STDERR, "No active service key with prefix '$prefix'.\n");
    exit(4);
}

$existing = json_decode($key['scopes'] ?? '[]', true);
if (!is_array($existing)) $existing = [];
$merged = array_values(array_unique(array_merge($existing, $new_scopes)));

db_query_prepared(
    "UPDATE service_api_key SET scopes = ? WHERE service_key_id = ?",
    [json_encode($merged), (int)$key['service_key_id']]
);

echo "Updated key #{$key['service_key_id']} ({$key['key_name']}):\n";
echo "  before: " . json_encode($existing) . "\n";
echo "  after:  " . json_encode($merged) . "\n";

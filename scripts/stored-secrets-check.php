<?php
/**
 * scripts/stored-secrets-check.php — LIVE check of this host's main DB (task #2397).
 * Exit 1 and name the column if any WN_SEALED_COLUMNS value is still unmarked plain text.
 * --fix seals them first (same idempotent sweep as cron/days/1/seal_stored_secrets.php).
 * Usage (on the host): php scripts/stored-secrets-check.php [--fix]
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
include __DIR__ . '/../include/common_readonly.php';
global $db;
if (in_array('--fix', $argv, true)) {
    echo 'sealed: ' . json_encode(wn_secret_seal_sweep($db)) . "\n";
}
$left = wn_secret_unsealed_counts($db);
if ($left) { fwrite(STDERR, 'UNSEALED: ' . json_encode($left) . "\n"); exit(1); }
echo "stored-secrets-check: every known secret column is sealed\n";

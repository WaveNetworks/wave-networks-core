<?php
/**
 * cron/days/1/seal_stored_secrets.php
 * Seals any plain-text credential still sitting in a WN_SEALED_COLUMNS column
 * (include/common/secretFunctions.php, task #2397). Idempotent — a sealed value is never
 * touched again, so this is the in-place migration for every host and also catches a row
 * written by older child-app code.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!isset($db)) {
    include(__DIR__ . '/../../../include/common_readonly.php');
}

global $db;

$sealed = wn_secret_seal_sweep($db);
$total  = array_sum($sealed);
if ($total > 0) {
    echo "seal_stored_secrets: sealed $total value(s): " . json_encode($sealed) . "\n";
}

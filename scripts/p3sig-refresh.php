<?php
/**
 * scripts/p3sig-refresh.php — pull this app's sealed bundle from p3sig into the local
 * cache now (the cron does it every minute). Needs no DB. Prints counts, never values.
 * Usage: php scripts/p3sig-refresh.php [--check]   (--check: also list which names resolve)
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../include/common/runtimeSecretFunctions.php';
$r = wn_p3sig_refresh();
echo $r['ok'] ? "OK: {$r['count']} secret(s) cached\n" : "FAILED: {$r['error']}\n";
if (in_array('--check', $argv, true)) {
    foreach (wn_p3sig_cache_values() as $name => $_) {
        $mapped = array_search($name, WN_RUNTIME_SECRET_GLOBALS, true);
        echo "  $name" . ($mapped ? " -> \$$mapped" : ' (read with wn_secret())') . "\n";
    }
}
exit($r['ok'] ? 0 : 1);

<?php
// Pull this app's sealed bundle from p3sig into the local cache (runtimeSecretFunctions.php).
// No-op unless config/p3sig.php exists. A failed pull keeps the old cache.
if (function_exists('wn_p3sig_config') && wn_p3sig_config()) {
    $r = wn_p3sig_refresh();
    echo '    p3sig: ' . ($r['ok'] ? "{$r['count']} secret(s) cached" : 'refresh failed: ' . $r['error']) . "\n";
    if (!$r['ok']) { error_log('p3sig refresh failed: ' . $r['error']); }
}

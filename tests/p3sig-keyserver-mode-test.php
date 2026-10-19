<?php
/**
 * Key-server mode of runtimeSecretFunctions.php against a live key server
 * (p3sig doc §4.4). Run on the key-server machine by tests/p3sig-keyserver-mode-test.sh,
 * which seals a bundle, allowlists a temporary app and passes:
 *   php tests/p3sig-keyserver-mode-test.php <dir> <app_id> <ks_id> <url> <phase>
 * Phases: cold | warm | stale-down | cold-down. Prints PASS/FAIL lines.
 */
[, $dir, $app, $ks_id, $url, $phase] = $argv;
define('WN_P3SIG_CONFIG', "$dir/p3sig.php");
define('WN_P3SIG_WEBROOT', "$dir/public_html");
file_put_contents("$dir/p3sig.php", '<?php return ' . var_export([
    'app_id' => $app, 'key_file' => "$dir/app.key", 'cache_file' => "$dir/cache.enc",
    'keyservers' => [['id' => $ks_id, 'url' => $url]], 'refresh_after' => 60,
], true) . ';');
require __DIR__ . '/../include/common/runtimeSecretFunctions.php';
ini_set('error_log', "$dir/php-error.log");

$check = function ($name, $ok) { echo ($ok ? 'PASS ' : 'FAIL '), $name, "\n"; };
$key = wn_p3sig_shm_key(wn_p3sig_config());
switch ($phase) {
    case 'cold':
        $check('memory starts empty', wn_p3sig_shm_read($key) === null);
        $check('cold start unlocks through the key server', wn_secret('STRIPE_SECRET_KEY') === 'sk_test_core');
        $st = wn_p3sig_shm_read($key);
        $check('values now in memory, encrypted', $st && $st['vals']['STRIPE_SECRET_KEY'] === 'sk_test_core');
        $seg = shmop_open(wn_p3sig_shm_id($key), 'a', 0, 0);
        $check('plaintext not in the segment', strpos(shmop_read($seg, 0, shmop_size($seg)), 'sk_test_core') === false);
        break;
    case 'warm':
        $check('warm read from memory (key server is down)', wn_secret('STRIPE_SECRET_KEY') === 'sk_test_core');
        break;
    case 'stale-down':
        $st = wn_p3sig_shm_read($key);
        wn_p3sig_shm_write($key, ['vals' => $st['vals'], 'refresh_after' => time() - 1]);
        $check('stale + key server down: memory values still served', wn_secret('STRIPE_SECRET_KEY') === 'sk_test_core');
        $check('retry pushed out a minute', (wn_p3sig_shm_read($key)['refresh_after'] ?? 0) > time() + 30);
        break;
    case 'cold-down':
        shmop_delete(shmop_open(wn_p3sig_shm_id($key), 'w', 0, 0));
        $check('cold + key server down: no values, config.php stands', wn_secret('STRIPE_SECRET_KEY', 'from-config') === 'from-config');
        break;
}

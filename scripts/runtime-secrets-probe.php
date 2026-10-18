<?php
/**
 * scripts/runtime-secrets-probe.php — service keys from the environment / p3sig cache
 * (include/common/runtimeSecretFunctions.php) behave as documented.
 *
 *   1. static: bootstrap.php and common_readonly.php apply them after config and before
 *      the PDO connect, and the scope list never names a DB credential, $hiddenhash or
 *      $app_secret;
 *   2. RUN, each case in a fresh PHP process with the real functions:
 *      - neither source configured → config.php values untouched (opt-in);
 *      - env values without WN_SECRETS_FROM_ENV=1 → untouched;
 *      - WN_SECRETS_FROM_ENV=1 → service keys from env, DB/pepper/app secret untouched;
 *      - p3sig cache → values open with the machine key; an entry sealed to another key
 *        is skipped; env beats cache; a machine key inside the web root is refused;
 *      - refresh against a local mock p3sig (php -S): the request signature verifies with
 *        the machine's public key, the cache is written 0600 and opens; a failing server
 *        keeps the previous cache.
 *
 * Usage: php scripts/runtime-secrets-probe.php [--root=/path/to/admin]
 * Exit 0 clean, 1 on a problem. DB-free (needs sodium + curl).
 */

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$root = rtrim((string) ($opts['root'] ?? dirname(__DIR__)), '/');
$fn   = $root . '/include/common/runtimeSecretFunctions.php';

// ─── child mode: one case, prints JSON ──────────────────────────────────────
if (!empty($opts['case'])) {
    $dir = $opts['dir'];
    if (is_file("$dir/p3sig.php")) { define('WN_P3SIG_CONFIG', "$dir/p3sig.php"); }
    else { define('WN_P3SIG_CONFIG', "$dir/none.php"); }
    define('WN_P3SIG_WEBROOT', "$dir/public_html");
    // what config.php would have set
    $dbPassword = 'db-from-file'; $hiddenhash = 'pepper-from-file'; $app_secret = 'app-from-file';
    $stripe_secret_key = 'sk-from-file'; $smtp_pass = 'smtp-from-file'; $smtp_port = 587;
    require $fn;
    if ($opts['case'] === 'refresh') {
        echo json_encode(wn_p3sig_refresh());
        exit;
    }
    wn_apply_runtime_secrets();
    echo json_encode(compact('dbPassword', 'hiddenhash', 'app_secret', 'stripe_secret_key', 'smtp_pass', 'smtp_port')
        + ['extra' => function_exists('wn_secret') ? wn_secret('CHILD_ONLY_KEY') : null]);
    exit;
}

$fail = [];
$ok   = function ($cond, $msg) use (&$fail) { if (!$cond) $fail[] = $msg; };

// ─── 1. static ──────────────────────────────────────────────────────────────
foreach (['include/bootstrap.php', 'include/common_readonly.php'] as $rel) {
    $src = (string) @file_get_contents("$root/$rel");
    $cfg = strpos($src, "config/config.php");
    $app = strpos($src, 'wn_apply_runtime_secrets();');
    $pdo = strpos($src, 'new PDO(');
    $ok($cfg !== false && $app !== false && $pdo !== false && $cfg < $app && $app < $pdo,
        "$rel: wn_apply_runtime_secrets() must run after config.php is loaded and before the PDO connect");
}
require $fn;
foreach (WN_RUNTIME_SECRET_GLOBALS as $var => $name) {
    $ok(!preg_match('/^(db|hiddenhash|app_secret|files_location|shard)/i', $var) && !preg_match('/^(DB_|HIDDEN_HASH|APP_SECRET)/', $name),
        "WN_RUNTIME_SECRET_GLOBALS must not carry $var/$name (stays in config.php)");
}

// ─── 2. run ─────────────────────────────────────────────────────────────────
$tmp = sys_get_temp_dir() . '/wn-rt-probe-' . getmypid();
@mkdir("$tmp/public_html", 0700, true);
@mkdir("$tmp/p3sig", 0700, true);
$run = function ($case, $env = []) use ($tmp) {
    $e = '';
    foreach ($env as $k => $v) { $e .= $k . '=' . escapeshellarg($v) . ' '; }
    $cmd = 'env -u WN_SECRETS_FROM_ENV -u STRIPE_SECRET_KEY -u SMTP_PASS -u DB_PASSWORD -u APP_SECRET -u HIDDEN_HASH '
        . $e . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --case=' . escapeshellarg($case) . ' --dir=' . escapeshellarg($tmp) . ' 2>/dev/null';
    return json_decode((string) shell_exec($cmd), true);
};

$r = $run('apply');
$ok($r && $r['stripe_secret_key'] === 'sk-from-file' && $r['smtp_pass'] === 'smtp-from-file', 'nothing configured: config.php values must stand');

$r = $run('apply', ['STRIPE_SECRET_KEY' => 'sk-env']);
$ok($r && $r['stripe_secret_key'] === 'sk-from-file', 'env without WN_SECRETS_FROM_ENV=1 must be ignored');

$r = $run('apply', ['WN_SECRETS_FROM_ENV' => '1', 'STRIPE_SECRET_KEY' => 'sk-env', 'SMTP_PORT' => '2525',
    'DB_PASSWORD' => 'db-env', 'APP_SECRET' => 'app-env', 'HIDDEN_HASH' => 'pepper-env', 'CHILD_ONLY_KEY' => 'child-env']);
$ok($r && $r['stripe_secret_key'] === 'sk-env' && $r['smtp_port'] === 2525 && $r['extra'] === 'child-env', 'WN_SECRETS_FROM_ENV=1: service keys must come from env');
$ok($r && $r['dbPassword'] === 'db-from-file' && $r['app_secret'] === 'app-from-file' && $r['hiddenhash'] === 'pepper-from-file',
    'WN_SECRETS_FROM_ENV=1 must not touch DB credentials, $app_secret or $hiddenhash');

// p3sig cache: a machine key, a cache with one good entry, one sealed to another key
$machine = sodium_crypto_sign_keypair();
$sk      = sodium_crypto_sign_secretkey($machine);
$xpk     = sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey($machine));
$other   = sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
$seal    = function ($v, $pk) { return base64_encode(sodium_crypto_box_seal($v, $pk)); };
file_put_contents("$tmp/p3sig/machine.key", base64_encode($sk) . "\n");
$writeCfg = function ($keyFile, $url = 'http://127.0.0.1:1/none') use ($tmp) {
    file_put_contents("$tmp/p3sig.php", '<?php return ' . var_export([
        'url' => $url, 'machine_id' => 'm-probe', 'key_file' => $keyFile, 'cache_file' => "$tmp/p3sig/cache.json"], true) . ';');
};
$writeCfg("$tmp/p3sig/machine.key");
file_put_contents("$tmp/p3sig/cache.json", json_encode(['secrets' => [
    ['label' => 'STRIPE_SECRET_KEY', 'encrypted_data' => $seal('sk-p3sig', $xpk)],
    ['label' => 'SMTP_PASS', 'encrypted_data' => $seal('smtp-wrong-key', $other)],
    ['label' => 'CHILD_ONLY_KEY', 'encrypted_data' => $seal('child-p3sig', $xpk)],
]]));
$r = $run('apply');
$ok($r && $r['stripe_secret_key'] === 'sk-p3sig' && $r['extra'] === 'child-p3sig', 'p3sig cache values must override config.php');
$ok($r && $r['smtp_pass'] === 'smtp-from-file', 'a cache entry sealed to another key must be skipped');
$ok($r && $r['dbPassword'] === 'db-from-file', 'p3sig cache must not touch DB credentials');

$r = $run('apply', ['WN_SECRETS_FROM_ENV' => '1', 'STRIPE_SECRET_KEY' => 'sk-env']);
$ok($r && $r['stripe_secret_key'] === 'sk-env' && $r['extra'] === 'child-p3sig', 'env must beat the p3sig cache, which still fills the rest');

copy("$tmp/p3sig/machine.key", "$tmp/public_html/machine.key");
$writeCfg("$tmp/public_html/machine.key");
$r = $run('apply');
$ok($r && $r['stripe_secret_key'] === 'sk-from-file', 'a machine key inside the web root must be refused');

// refresh against a mock p3sig that checks the signature like p3sig_verify_machine_request()
$pub = base64_encode(sodium_crypto_sign_publickey($machine));
file_put_contents("$tmp/mock.php", '<?php
$pub = base64_decode(' . var_export($pub, true) . ');
if (is_file(__DIR__ . "/down")) { http_response_code(503); exit; }
$ok = ($_POST["action"] ?? "") === "getSealedSecrets" && abs(time() - (int) $_POST["ts"]) < 300
   && sodium_crypto_sign_verify_detached(base64_decode($_POST["signature"]), $_POST["machine_id"] . "|" . $_POST["ts"], $pub);
header("Content-Type: application/json");
if (!$ok) { echo json_encode(["error" => "Signature verification failed.", "results" => []]); exit; }
echo json_encode(["error" => "", "results" => ["secrets" => [
    ["label" => "STRIPE_SECRET_KEY", "bundle" => "b", "encrypted_data" => ' . var_export($seal('sk-refreshed', $xpk), true) . '],
    ["label" => "SMTP_PASS", "bundle" => "b", "encrypted_data" => ' . var_export($seal('junk', $other), true) . '],
]]]);');
$port = 0;
for ($try = 0; $try < 5 && !$port; $try++) {
    $p = random_int(20000, 60000);
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$p", "$tmp/mock.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 30; $i++) { if (@fsockopen('127.0.0.1', $p)) { $port = $p; break; } usleep(100000); }
    if (!$port) { proc_terminate($srv); }
}
$ok($port > 0, 'could not start the mock p3sig server');
if ($port) {
    $writeCfg("$tmp/p3sig/machine.key", "http://127.0.0.1:$port/");
    @unlink("$tmp/p3sig/cache.json");
    $r = $run('refresh');
    $ok($r && $r['ok'] === true && $r['count'] === 1, 'refresh must succeed and keep only entries that open: ' . json_encode($r));
    $ok(is_file("$tmp/p3sig/cache.json") && (fileperms("$tmp/p3sig/cache.json") & 0777) === 0600, 'cache must be written mode 0600');
    $r = $run('apply');
    $ok($r && $r['stripe_secret_key'] === 'sk-refreshed', 'refreshed cache must open and apply');

    $before = file_get_contents("$tmp/p3sig/cache.json");
    touch("$tmp/down");
    $r = $run('refresh');
    $ok($r && $r['ok'] === false && file_get_contents("$tmp/p3sig/cache.json") === $before, 'a failed pull must keep the previous cache');
    proc_terminate($srv);
}

shell_exec('rm -rf ' . escapeshellarg($tmp));

if ($fail) {
    fwrite(STDERR, "runtime-secrets-probe: FAIL\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "runtime-secrets-probe: OK\n";
exit(0);

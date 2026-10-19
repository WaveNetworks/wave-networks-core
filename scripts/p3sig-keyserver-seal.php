<?php
/**
 * scripts/p3sig-keyserver-seal.php — move this deployment's secrets into a key-server
 * bundle, ON the host, so the values never leave it (p3sig doc §4.4, migration step 1).
 *
 *   php admin/scripts/p3sig-keyserver-seal.php --pub <b64 P-256 point> [--pub …]
 *       --out ~/p3sig-ks/cache.enc [--child public_html/<app>/config/config.php …] [--dry-run]
 *
 * Reads admin/config/config.php (and notifications_config.php) and each --child config,
 * collects every non-empty service key (WN_RUNTIME_SECRET_GLOBALS), the roots
 * (WN_ROOT_SECRET_GLOBALS, the key rings, seed-shard passwords) and the child roots, and
 * writes cache.enc: values under a random bundle key (XChaCha20-Poly1305), the bundle key
 * wrapped to each key server (p3sig-keyserver wrap.go scheme 1: P-256 ECDH → HKDF-SHA256 →
 * AES-256-GCM). Prints names only, never values. Leaves config.php untouched: removing
 * the values from it is a separate, later step, after the app runs from the bundle.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../include/common/runtimeSecretFunctions.php';

function seal_fail($msg) { fwrite(STDERR, "error: $msg\n"); exit(1); }

$pubs = []; $children = []; $out = null; $dry = false;
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--pub':     $pubs[] = $argv[++$i] ?? ''; break;
        case '--child':   $children[] = $argv[++$i] ?? ''; break;
        case '--out':     $out = $argv[++$i] ?? null; break;
        case '--dry-run': $dry = true; break;
        default: seal_fail("unknown argument {$argv[$i]}");
    }
}
if (!$pubs && !$dry) { seal_fail('give at least one --pub (the key server\'s /etc/p3sig/keyserver.pub)'); }
if (!$out && !$dry) { seal_fail('give --out (outside public_html)'); }

/** Variables a config file defines, read in an isolated scope. */
function seal_read_config($file) {
    if (!is_file($file)) { seal_fail("no config at $file"); }
    return (function () use ($file) {
        include $file;
        $n = dirname($file) . '/notifications_config.php';
        if (is_file($n)) { include $n; }
        unset($file, $n);
        return get_defined_vars();
    })();
}

function seal_name_suffix($k) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', (string) $k)); }

$vals = [];
$add = function ($name, $v) use (&$vals) {
    if (is_int($v)) { $v = (string) $v; }
    if (is_string($v) && $v !== '') { $vals[$name] = $v; }
};

$admin = seal_read_config(__DIR__ . '/../config/config.php');
foreach (WN_RUNTIME_SECRET_GLOBALS as $var => $name) { $add($name, $admin[$var] ?? null); }
foreach (WN_ROOT_SECRET_GLOBALS as $var => $name) { $add($name, $admin[$var] ?? null); }
foreach (WN_ROOT_SECRET_RINGS as $var => $name) {
    if (!empty($admin[$var]) && is_array($admin[$var])) { $vals[$name] = json_encode($admin[$var]); }
}
foreach (($admin['shardConfigs'] ?? []) as $k => $sc) {
    $add(WN_ROOT_SHARD_PREFIX . seal_name_suffix($k), $sc['pass'] ?? null);
}
foreach ($children as $file) {
    $c = seal_read_config($file);
    $add('CHILD_DB_USER', $c['child_db_user'] ?? null);
    $add('CHILD_DB_PASS', $c['child_db_pass'] ?? null);
    foreach (($c['childShardConfigs'] ?? []) as $k => $sc) {
        $add('CHILD_' . WN_ROOT_SHARD_PREFIX . seal_name_suffix($k), $sc['pass'] ?? null);
    }
}
ksort($vals);
echo count($vals) . " secret(s): " . implode(', ', array_keys($vals)) . "\n";
if ($dry) { exit(0); }

$webroot = realpath(__DIR__ . '/../..');
$outDir  = realpath(dirname($out));
if ($outDir === false) { seal_fail('the --out directory does not exist'); }
if ($webroot && strpos($outDir . '/', rtrim($webroot, '/') . '/') === 0) { seal_fail("refusing to write inside the web root ($webroot)"); }

/** wrap.go scheme 1 to a P-256 point (65-byte uncompressed SEC1). */
function seal_wrap_ecdh($secret, $point) {
    if (strlen($point) !== 65 || $point[0] !== "\x04") { seal_fail('a --pub is not a 65-byte uncompressed P-256 point'); }
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n");
    if ($peer === false) { seal_fail('a --pub is not on P-256'); }
    $eph = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d   = openssl_pkey_get_details($eph)['ec'];
    $ephPub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    $shared = openssl_pkey_derive($peer, $eph, 32);
    if ($shared === false || strlen($shared) !== 32) { seal_fail('ECDH failed'); }
    $key   = hash_hkdf('sha256', $shared, 32, 'p3sig/device-wrap/v1/ecdh-p256-aesgcm', '');
    $nonce = random_bytes(12);
    $aad   = "\x01\x01" . $ephPub . $nonce;
    $tag   = '';
    $ct    = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
    sodium_memzero($shared);
    sodium_memzero($key);
    return $aad . $ct . $tag;
}

$bundleKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$wraps = [];
foreach ($pubs as $b64) {
    $point = base64_decode(trim($b64), true);
    if ($point === false) { seal_fail('a --pub is not base64'); }
    $id = substr(rtrim(strtr(base64_encode(hash('sha256', $point, true)), '+/', '-_'), '='), 0, 16);
    $wraps[$id] = base64_encode(seal_wrap_ecdh($bundleKey, $point));
}
$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(json_encode($vals), 'p3sig-bundle-v1', $nonce, $bundleKey);
sodium_memzero($bundleKey);
foreach ($vals as $k => $v) { sodium_memzero($vals[$k]); }

$json = json_encode(['v' => 1, 'wraps' => $wraps, 'nonce' => base64_encode($nonce), 'ct' => base64_encode($ct)], JSON_PRETTY_PRINT) . "\n";
$tmp  = $out . '.tmp' . getmypid();
if (@file_put_contents($tmp, $json) === false || !@chmod($tmp, 0600) || !@rename($tmp, $out)) {
    @unlink($tmp);
    seal_fail("could not write $out");
}
echo "wrote $out for key server(s): " . implode(', ', array_keys($wraps)) . "\n";

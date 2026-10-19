<?php
/**
 * runtimeSecretFunctions.php
 * Service keys from outside config.php. bootstrap.php loads this at step 2d, before the
 * DB, so it may use no other helper and no DB.
 *
 * Two sources, both opt-in, both overriding config.php (and notifications_config.php):
 *   1. The process environment, when WN_SECRETS_FROM_ENV=1. Our own machines:
 *      `p3sig run -- php-fpm` puts the values there (pool clear_env = no).
 *   2. A p3sig sealed cache, when config/p3sig.php exists. Shared hosting: the
 *      every-minute cron (cron/minutes/1/p3sig_refresh.php) pulls the machine's bundle
 *      from p3sig and replaces the cache; requests only open the local file, so a p3sig
 *      outage never takes the site down, it only delays rotation.
 * Precedence: environment > p3sig cache > config.php.
 *
 * Scope: SERVICE keys (WN_RUNTIME_SECRET_GLOBALS). DB credentials, $hiddenhash and
 * $app_secret stay in config.php (docs/core-secret-rotation.md), EXCEPT in key-server mode
 * below, which also sets them (WN_ROOT_SECRET_GLOBALS). Child apps read their own names
 * with wn_secret('NAME') and their DB password with wn_apply_child_root_secrets().
 *
 * config/p3sig.php (gitignored) returns:
 *   ['url' => 'https://p3sig.com/p3sig/api/index.php', 'machine_id' => '…',
 *    'key_file' => '/home/…/p3sig/machine.key', 'cache_file' => '/home/…/p3sig/cache.json',
 *    'bundle' => '']   // bundle optional; cache_file defaults to <key_file dir>/p3sig-cache.json
 * The key file is the base64 Ed25519 private key `p3sig init` writes. It and the cache
 * must live outside public_html; a key inside it is refused.
 *
 * Key-server mode (p3sig doc §4.4, shared hosting with no key of its own that opens
 * anything): config/p3sig.php returns instead
 *   ['app_id' => 'pilot', 'key_file' => '/home/…/p3sig/app.key',
 *    'cache_file' => '/home/…/p3sig/cache.enc',      // `p3sig keyserver seal` output
 *    'keyservers' => [['id' => '…', 'url' => 'https://…/v1/unwrap'], …],
 *    'refresh_after' => 3600]
 * cache.enc is encrypted to the key servers' TPM keys, so the files on this host open
 * nothing alone. On a cold start one worker asks a key server for the bundle key, then
 * keeps the values in a shmop segment (mode 0600) encrypted with p3sig-shm.key from the
 * key_file directory. When no key server answers, the last values in memory stay in use;
 * only a web-node reboot during a key-server outage leaves the app without its secrets.
 *
 * Where the p3sig config lives: app deploys rsync with --delete and exclude only
 * config/config.php, so a config/p3sig.php inside the deployed tree is deleted by the next
 * deploy. Put it next to the key, outside public_html, and point at it from config.php:
 *   define('WN_P3SIG_CONFIG', '/home/…/p3sig/p3sig.php');
 */

/** global variable => secret name (same names as the Docker env fallback in bootstrap.php). */
const WN_RUNTIME_SECRET_GLOBALS = [
    'smtp_host'            => 'SMTP_HOST',
    'smtp_port'            => 'SMTP_PORT',
    'smtp_user'            => 'SMTP_USER',
    'smtp_pass'            => 'SMTP_PASS',
    'mail_from'            => 'MAIL_FROM',
    'mail_from_name'       => 'MAIL_FROM_NAME',
    'google_client_id'     => 'GOOGLE_CLIENT_ID',
    'google_client_secret' => 'GOOGLE_CLIENT_SECRET',
    'github_client_id'     => 'GITHUB_CLIENT_ID',
    'github_client_secret' => 'GITHUB_CLIENT_SECRET',
    'facebook_app_id'      => 'FACEBOOK_APP_ID',
    'facebook_app_secret'  => 'FACEBOOK_APP_SECRET',
    'grecaptcha_key'       => 'RECAPTCHA_SITE_KEY',
    'grecaptcha_secret'    => 'RECAPTCHA_SECRET_KEY',
    'stripe_secret_key'    => 'STRIPE_SECRET_KEY',
    'stripe_public_key'    => 'STRIPE_PUBLIC_KEY',
    'vapid_subject'        => 'VAPID_SUBJECT',
    'vapid_public_key'     => 'VAPID_PUBLIC_KEY',
    'vapid_private_key'    => 'VAPID_PRIVATE_KEY',
];

/**
 * config/p3sig.php as an array, or null when this host doesn't use p3sig.
 * $reset is for tests and the refresh script.
 */
function wn_p3sig_config($reset = false) {
    static $cfg = false;
    if ($reset) { $cfg = false; }
    if ($cfg !== false) { return $cfg; }
    $cfg = null;
    $file = defined('WN_P3SIG_CONFIG') ? WN_P3SIG_CONFIG : __DIR__ . '/../../config/p3sig.php';
    if (!is_file($file)) { return $cfg; }
    $c = include $file;
    $ks = is_array($c) && !empty($c['keyservers']);
    if (!is_array($c) || empty($c['key_file']) || ($ks ? empty($c['app_id']) : empty($c['machine_id']))) {
        error_log('p3sig: config/p3sig.php needs key_file and ' . ($ks ? 'app_id' : 'machine_id') . '; ignoring it');
        return $cfg;
    }
    if ($ks) {
        foreach ($c['keyservers'] as $k) {
            $loopback = preg_match('#^http://(127\.0\.0\.1|\[::1\]|localhost)[:/]#', (string) ($k['url'] ?? ''));
            if (empty($k['id']) || empty($k['url']) || (strpos($k['url'], 'https://') !== 0 && !$loopback)) {
                error_log('p3sig: every keyservers entry needs an id and an https url (http only on loopback); ignoring config/p3sig.php');
                return $cfg;
            }
        }
        $c['refresh_after'] = max(60, (int) ($c['refresh_after'] ?? 3600));
    }
    $c['keyserver_mode'] = $ks;
    $c['url']        = $c['url'] ?? 'https://p3sig.com/p3sig/api/index.php';
    $c['bundle']     = $c['bundle'] ?? '';
    $c['cache_file'] = $c['cache_file'] ?? (dirname($c['key_file']) . '/p3sig-cache.json');
    $cfg = $c;
    return $cfg;
}

/**
 * The machine's Ed25519 secret key (64 raw bytes), or null. Refuses a key under the
 * web root: whatever the web server can serve, anyone can download.
 */
function wn_p3sig_machine_key($cfg) {
    $path = realpath($cfg['key_file']);
    if ($path === false || !is_readable($path)) {
        error_log('p3sig: machine key not readable: ' . $cfg['key_file']);
        return null;
    }
    $webroot = defined('WN_P3SIG_WEBROOT') ? WN_P3SIG_WEBROOT : realpath(__DIR__ . '/../../..');
    if ($webroot && strpos($path, rtrim($webroot, '/') . '/') === 0) {
        error_log('p3sig: refusing a machine key inside the web root (' . $path . '); move it outside public_html');
        return null;
    }
    $raw = base64_decode(trim((string) file_get_contents($path)), true);
    if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        error_log('p3sig: machine key is not a base64 Ed25519 private key');
        return null;
    }
    return $raw;
}

/** Open one sealed box (base64) addressed to the machine key; false if it doesn't open. */
function wn_p3sig_open($encrypted_b64, $ed_sk) {
    $ct = base64_decode((string) $encrypted_b64, true);
    if ($ct === false) { return false; }
    $x_sk = sodium_crypto_sign_ed25519_sk_to_curve25519($ed_sk);
    $x_pk = sodium_crypto_sign_ed25519_pk_to_curve25519(substr($ed_sk, 32));
    $kp   = sodium_crypto_box_keypair_from_secretkey_and_publickey($x_sk, $x_pk);
    $out  = sodium_crypto_box_seal_open($ct, $kp);
    sodium_memzero($x_sk);
    sodium_memzero($kp);
    return $out;
}

/**
 * name => value from the sealed cache, opened once per request. [] when p3sig isn't
 * configured or the cache can't be read (config.php values then stand).
 */
function wn_p3sig_cache_values($reset = false) {
    static $vals = null;
    if ($reset) { $vals = null; }
    if ($vals !== null) { return $vals; }
    $vals = [];
    $cfg = wn_p3sig_config();
    if ($cfg && $cfg['keyserver_mode']) {
        $vals = wn_p3sig_ks_values($cfg);
        return $vals;
    }
    if (!$cfg || !is_file($cfg['cache_file'])) { return $vals; }
    $cache = json_decode((string) file_get_contents($cfg['cache_file']), true);
    if (!is_array($cache) || !isset($cache['secrets']) || !is_array($cache['secrets'])) {
        error_log('p3sig: cache file is unreadable; using config.php values');
        return $vals;
    }
    $sk = wn_p3sig_machine_key($cfg);
    if ($sk === null) { return $vals; }
    foreach ($cache['secrets'] as $s) {
        $plain = wn_p3sig_open($s['encrypted_data'] ?? '', $sk);
        if ($plain === false) {
            error_log('p3sig: cached secret ' . ($s['label'] ?? '?') . ' does not open with this machine key');
            continue;
        }
        $vals[(string) $s['label']] = $plain;
    }
    sodium_memzero($sk);
    return $vals;
}

/**
 * A secret by name: environment (when WN_SECRETS_FROM_ENV=1) > p3sig cache > $default.
 */
function wn_secret($name, $default = null) {
    if (getenv('WN_SECRETS_FROM_ENV') === '1') {
        $v = getenv($name);
        if ($v !== false) { return $v; }
    }
    $cache = wn_p3sig_cache_values();
    if (array_key_exists($name, $cache)) { return $cache[$name]; }
    return $default;
}

/** bootstrap.php step 2d: override the config.php service-key globals. */
function wn_apply_runtime_secrets() {
    if (getenv('WN_SECRETS_FROM_ENV') !== '1' && !wn_p3sig_config()) { return; }
    foreach (WN_RUNTIME_SECRET_GLOBALS as $var => $name) {
        $v = wn_secret($name);
        if ($v === null) { continue; }
        $GLOBALS[$var] = ($var === 'smtp_port' && ctype_digit($v)) ? (int) $v : $v;
    }
    wn_apply_root_secrets();
}

/**
 * Root secrets: the DB password, the $app_secret and $hiddenhash rings, and seed-shard
 * passwords. Key-server mode ONLY (p3sig doc §4.4, owner 2026-10-19): there the files on
 * the host open nothing alone, so taking these out of config.php gains something. The
 * environment and the plain sealed cache never set them. Shards registered in the
 * shard_config table need nothing here: their passwords are sealed with the $app_secret
 * ring, which this provides.
 */
const WN_ROOT_SECRET_GLOBALS = [
    'dbUserName'         => 'DB_USER',
    'dbPassword'         => 'DB_PASSWORD',
    'app_secret'         => 'APP_SECRET',
    'app_secret_current' => 'APP_SECRET_CURRENT',
    'hiddenhash'         => 'HIDDEN_HASH',
    'pepper_current'     => 'PEPPER_CURRENT',
];
/** JSON-encoded {id: key} rings. */
const WN_ROOT_SECRET_RINGS = ['app_secrets' => 'APP_SECRETS', 'peppers' => 'PEPPERS'];
/** Seed shards in config.php's $shardConfigs: DB_SHARD_PASS_<shard key>. */
const WN_ROOT_SHARD_PREFIX = 'DB_SHARD_PASS_';

function wn_p3sig_keyserver_mode() {
    $cfg = wn_p3sig_config();
    return $cfg && $cfg['keyserver_mode'];
}

function wn_apply_root_secrets() {
    if (!wn_p3sig_keyserver_mode()) { return; }
    $vals = wn_p3sig_cache_values();
    foreach (WN_ROOT_SECRET_GLOBALS as $var => $name) {
        if (array_key_exists($name, $vals)) { $GLOBALS[$var] = $vals[$name]; }
    }
    foreach (WN_ROOT_SECRET_RINGS as $var => $name) {
        if (!array_key_exists($name, $vals)) { continue; }
        $ring = json_decode($vals[$name], true);
        if (is_array($ring) && $ring) { $GLOBALS[$var] = array_map('strval', $ring); }
        else { error_log("p3sig: $name is not a JSON object of keys; config.php value stands"); }
    }
    if (!empty($GLOBALS['shardConfigs']) && is_array($GLOBALS['shardConfigs'])) {
        foreach ($GLOBALS['shardConfigs'] as $k => $sc) {
            $name = WN_ROOT_SHARD_PREFIX . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', (string) $k));
            if (array_key_exists($name, $vals)) { $GLOBALS['shardConfigs'][$k]['pass'] = $vals[$name]; }
        }
    }
}

/**
 * Child apps: call after loading their own config/config.php, before connecting.
 * CHILD_DB_USER / CHILD_DB_PASS, and CHILD_DB_SHARD_PASS_<shard key> for each seed shard
 * in $childShardConfigs. Key-server mode only, like the core roots.
 */
function wn_apply_child_root_secrets(&$child_db_user, &$child_db_pass, &$childShardConfigs) {
    if (!wn_p3sig_keyserver_mode()) { return; }
    $vals = wn_p3sig_cache_values();
    if (array_key_exists('CHILD_DB_USER', $vals)) { $child_db_user = $vals['CHILD_DB_USER']; }
    if (array_key_exists('CHILD_DB_PASS', $vals)) { $child_db_pass = $vals['CHILD_DB_PASS']; }
    if (is_array($childShardConfigs)) {
        foreach ($childShardConfigs as $k => $sc) {
            $name = 'CHILD_' . WN_ROOT_SHARD_PREFIX . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', (string) $k));
            if (array_key_exists($name, $vals)) { $childShardConfigs[$k]['pass'] = $vals[$name]; }
        }
    }
}

/**
 * Pull the machine's sealed bundle from p3sig and atomically replace the cache.
 * Only secrets that open with this machine's key are kept. On any failure the old
 * cache stays. Returns ['ok' => bool, 'count' => int, 'error' => string]; never values.
 */
function wn_p3sig_refresh() {
    $cfg = wn_p3sig_config();
    if (!$cfg) { return ['ok' => false, 'count' => 0, 'error' => 'p3sig is not configured (config/p3sig.php)']; }
    if ($cfg['keyserver_mode']) {
        // cache.enc is placed by `p3sig keyserver seal` until p3sig serves app bundles.
        return ['ok' => true, 'count' => 0, 'error' => ''];
    }
    $sk = wn_p3sig_machine_key($cfg);
    if ($sk === null) { return ['ok' => false, 'count' => 0, 'error' => 'machine key unusable (see error log)']; }

    $ts   = (string) time();
    $sig  = base64_encode(sodium_crypto_sign_detached($cfg['machine_id'] . '|' . $ts, $sk));
    $form = ['action' => 'getSealedSecrets', 'machine_id' => $cfg['machine_id'], 'ts' => $ts, 'signature' => $sig];
    if ($cfg['bundle'] !== '') { $form['bundle'] = $cfg['bundle']; }

    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'wn-core-p3sig/1',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        sodium_memzero($sk);
        return ['ok' => false, 'count' => 0, 'error' => "p3sig pull failed (HTTP $code $cerr)"];
    }
    $j = json_decode($body, true);
    if (!is_array($j) || !empty($j['error']) || !isset($j['results']['secrets']) || !is_array($j['results']['secrets'])) {
        sodium_memzero($sk);
        $e = is_array($j) && !empty($j['error']) ? (is_array($j['error']) ? implode('; ', $j['error']) : $j['error']) : 'unexpected response';
        return ['ok' => false, 'count' => 0, 'error' => 'p3sig: ' . $e];
    }

    $keep = [];
    foreach ($j['results']['secrets'] as $s) {
        $label = (string) ($s['label'] ?? '');
        $enc   = (string) ($s['encrypted_data'] ?? '');
        if ($label === '' || wn_p3sig_open($enc, $sk) === false) { continue; }
        $keep[] = ['label' => $label, 'bundle' => $s['bundle'] ?? null, 'encrypted_data' => $enc];
    }
    sodium_memzero($sk);

    $json = json_encode(['fetched_at' => gmdate('c'), 'machine_id' => $cfg['machine_id'], 'secrets' => $keep], JSON_PRETTY_PRINT);
    $tmp  = $cfg['cache_file'] . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json) === false || !@chmod($tmp, 0600) || !@rename($tmp, $cfg['cache_file'])) {
        @unlink($tmp);
        return ['ok' => false, 'count' => 0, 'error' => 'could not write ' . $cfg['cache_file']];
    }
    wn_p3sig_cache_values(true);
    return ['ok' => true, 'count' => count($keep), 'error' => ''];
}

// ─── key-server mode ─────────────────────────────────────────────────────────

/** The 32-byte key that encrypts the memory copy; made on first use next to key_file. */
function wn_p3sig_shm_key($cfg) {
    $path = dirname($cfg['key_file']) . '/p3sig-shm.key';
    if (!is_file($path)) {
        $tmp = $path . '.tmp' . getmypid();
        if (@file_put_contents($tmp, base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES))) === false
            || !@chmod($tmp, 0600) || !@rename($tmp, $path)) {
            @unlink($tmp);
            if (!is_file($path)) { error_log('p3sig: cannot create ' . $path); return null; }
        }
    }
    $k = base64_decode(trim((string) @file_get_contents($path)), true);
    return ($k !== false && strlen($k) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) ? $k : null;
}

/** System V segment id for this app: derived from the shm key, so stable and not guessable. */
function wn_p3sig_shm_id($shm_key) {
    return hexdec(substr(hash('sha256', 'p3sig-shm-id|' . $shm_key), 0, 7)) + 1;
}

/** ['vals' => [...], 'refresh_after' => ts] from memory, or null. */
function wn_p3sig_shm_read($shm_key) {
    if (!function_exists('shmop_open')) { return null; }
    $seg = @shmop_open(wn_p3sig_shm_id($shm_key), 'a', 0, 0);
    if ($seg === false) { return null; }
    $raw = shmop_read($seg, 0, shmop_size($seg));
    $len = unpack('N', substr($raw, 0, 4))[1] ?? 0;
    if ($len < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + 16 || $len > strlen($raw) - 4) { return null; }
    $blob  = substr($raw, 4, $len);
    $nonce = substr($blob, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        substr($blob, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), 'p3sig-shm-v1', $nonce, $shm_key);
    if ($plain === false) { return null; }
    $st = json_decode($plain, true);
    return (is_array($st) && isset($st['vals']) && is_array($st['vals'])) ? $st : null;
}

function wn_p3sig_shm_write($shm_key, array $state) {
    if (!function_exists('shmop_open')) { return false; }
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $blob  = $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(json_encode($state), 'p3sig-shm-v1', $nonce, $shm_key);
    $data  = pack('N', strlen($blob)) . $blob;
    $size  = (int) (ceil(strlen($data) / 4096) * 4096);
    $id    = wn_p3sig_shm_id($shm_key);
    $seg   = @shmop_open($id, 'w', 0, 0);
    if ($seg !== false && shmop_size($seg) < strlen($data)) {
        shmop_delete($seg);
        $seg = false;
    }
    if ($seg === false) { $seg = @shmop_open($id, 'c', 0600, $size); }
    if ($seg === false) { error_log('p3sig: cannot write the shared memory cache'); return false; }
    shmop_write($seg, str_pad($data, shmop_size($seg), "\0"), 0);
    return true;
}

/**
 * Ask one key server for cache.enc's bundle key and decrypt the bundle.
 * Returns name => value, or throws. The request is signed with the app key; the
 * reply is sealed to a one-time key, so only this worker can open it.
 */
function wn_p3sig_ks_unlock($cfg, array $ks, array $bundle, $sk, $why = 'cold') {
    $wrap = $bundle['wraps'][$ks['id']] ?? null;
    if (!is_string($wrap)) { throw new RuntimeException('cache.enc has no wrap for key server ' . $ks['id']); }
    $kp    = sodium_crypto_box_keypair();
    $reply = base64_encode(sodium_crypto_box_publickey($kp));
    $ts    = time();
    $msg   = 'p3sig-ks-v1|' . $cfg['app_id'] . '|' . $ts . '|' . hash('sha256', (string) base64_decode($wrap)) . '|' . $reply;
    $ch = curl_init($ks['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['app' => (string) $cfg['app_id'], 'ts' => $ts, 'wrap' => $wrap, 'reply' => $reply,
                                               'sig' => base64_encode(sodium_crypto_sign_detached($msg, $sk)),
                                               'why' => $why]),   // unsigned telemetry: `keyserver stats`
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'wn-core-p3sig/1',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        sodium_memzero($kp);
        throw new RuntimeException("key server {$ks['id']} refused or unreachable (HTTP $code)");
    }
    $sealed = base64_decode((string) (json_decode($body, true)['sealed'] ?? ''), true);
    $key = $sealed === false ? false : sodium_crypto_box_seal_open($sealed, $kp);
    sodium_memzero($kp);
    if ($key === false) { throw new RuntimeException("key server {$ks['id']}: reply did not open"); }
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        (string) base64_decode($bundle['ct'] ?? ''), 'p3sig-bundle-v1', (string) base64_decode($bundle['nonce'] ?? ''), $key);
    sodium_memzero($key);
    $vals = $plain === false ? null : json_decode($plain, true);
    if (!is_array($vals)) { throw new RuntimeException('cache.enc did not decrypt with the key server\'s key'); }
    return array_map('strval', $vals);
}

/**
 * Key-server mode: values from memory; on a cold or stale cache, one worker (under a
 * lock) asks the key servers in order. Stale values are kept when none answers.
 */
function wn_p3sig_ks_values($cfg) {
    $shm_key = wn_p3sig_shm_key($cfg);
    if ($shm_key === null) { return []; }
    $st = wn_p3sig_shm_read($shm_key);
    if ($st && time() < (int) ($st['refresh_after'] ?? 0)) { return $st['vals']; }

    $lock = @fopen(dirname($cfg['key_file']) . '/p3sig-shm.lock', 'c');
    if ($lock === false) { return $st['vals'] ?? []; }
    // With stale values in hand, never wait: someone else is already refreshing.
    // With none, wait (bounded by the key server timeouts) for that refresh.
    if (!flock($lock, $st ? (LOCK_EX | LOCK_NB) : LOCK_EX)) { fclose($lock); return $st['vals']; }
    try {
        $again = wn_p3sig_shm_read($shm_key);
        if ($again && time() < (int) ($again['refresh_after'] ?? 0)) { return $again['vals']; }
        $st = $again ?: $st;

        $bundle = is_file($cfg['cache_file']) ? json_decode((string) file_get_contents($cfg['cache_file']), true) : null;
        $sk = is_array($bundle) ? wn_p3sig_machine_key($cfg) : null;
        if (!is_array($bundle)) { error_log('p3sig: ' . $cfg['cache_file'] . ' is missing or unreadable'); }
        $vals = null;
        // Why we ask, for the key server's stats: cold = memory was gone (we could not run
        // without a key server), refresh = routine re-check with values still in memory.
        $why = ($st ? 'refresh' : 'cold') . (php_sapi_name() === 'cli' ? '-cli' : '');
        foreach ($sk !== null ? $cfg['keyservers'] : [] as $ks) {
            try {
                $vals = wn_p3sig_ks_unlock($cfg, $ks, $bundle, $sk, $why);
                break;
            } catch (Throwable $e) {
                error_log('p3sig: ' . $e->getMessage());
            }
        }
        if ($sk !== null) { sodium_memzero($sk); }
        if ($vals !== null) {
            wn_p3sig_shm_write($shm_key, ['vals' => $vals, 'refresh_after' => time() + $cfg['refresh_after']]);
            return $vals;
        }
        if ($st) {
            // Keep serving what memory holds; try again in a minute.
            wn_p3sig_shm_write($shm_key, ['vals' => $st['vals'], 'refresh_after' => time() + 60]);
            error_log('p3sig: no key server answered; using the values already in memory');
            return $st['vals'];
        }
        error_log('p3sig: no key server answered and memory is empty; config.php values stand');
        return [];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

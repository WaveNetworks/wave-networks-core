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
 * Scope: SERVICE keys only (WN_RUNTIME_SECRET_GLOBALS). DB credentials, $hiddenhash and
 * $app_secret stay in config.php (docs/core-secret-rotation.md). Child apps read their
 * own names with wn_secret('NAME').
 *
 * config/p3sig.php (gitignored) returns:
 *   ['url' => 'https://p3sig.com/p3sig/api/index.php', 'machine_id' => '…',
 *    'key_file' => '/home/…/p3sig/machine.key', 'cache_file' => '/home/…/p3sig/cache.json',
 *    'bundle' => '']   // bundle optional; cache_file defaults to <key_file dir>/p3sig-cache.json
 * The key file is the base64 Ed25519 private key `p3sig init` writes. It and the cache
 * must live outside public_html; a key inside it is refused.
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
    if (!is_array($c) || empty($c['machine_id']) || empty($c['key_file'])) {
        error_log('p3sig: config/p3sig.php needs machine_id and key_file; ignoring it');
        return $cfg;
    }
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
}

/**
 * Pull the machine's sealed bundle from p3sig and atomically replace the cache.
 * Only secrets that open with this machine's key are kept. On any failure the old
 * cache stays. Returns ['ok' => bool, 'count' => int, 'error' => string]; never values.
 */
function wn_p3sig_refresh() {
    $cfg = wn_p3sig_config();
    if (!$cfg) { return ['ok' => false, 'count' => 0, 'error' => 'p3sig is not configured (config/p3sig.php)']; }
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

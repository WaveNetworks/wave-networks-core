<?php
/**
 * keyRingFunctions.php
 * Versioned keys for $app_secret (sealing + JWT) and $hiddenhash (password pepper), so
 * either can be rotated with no downtime. Design: docs/core-secret-rotation.md.
 * bootstrap.php loads this at step 2e, before the DB; it may use no other helper.
 *
 * config/config.php — nothing changes until a second key is added:
 *   $app_secrets        = ['k2' => '<64 random chars>', 'k1' => '<the old $app_secret>'];
 *   $app_secret_current = 'k2';
 *   $peppers            = ['p2' => '<64 random chars>', 'p1' => '<the old $hiddenhash>'];
 *   $pepper_current     = 'p2';
 * A host with only $app_secret / $hiddenhash is the ring ['k1' => …] / ['p1' => …].
 * Values written before the ring (enc:v1:, bare bcrypt hashes, JWTs with no kid) belong
 * to k1 / p1; keep those ids for the old values until the sweep / logins have moved
 * everything off them (scripts/rotate-secret-key.php status).
 *
 * $app_secret / $hiddenhash stay defined as the CURRENT raw values for code that reads
 * them directly. Child code with its own crypto off $app_secret (brand-compiler's
 * bc_encrypt) must move to wn_secret_encrypt() before a new key is made current.
 */

const WN_KEY_ID_PATTERN = '/^[a-z0-9]{1,16}$/';

/** Normalise one ring from config globals. Returns ['keys' => [id => raw], 'current' => id, 'legacy' => id|null]. */
function wn_ring_from($ring, $current, $single, $legacy_id) {
    $keys = [];
    if (is_array($ring) && $ring) {
        foreach ($ring as $id => $raw) {
            $id = (string) $id;
            if (!preg_match(WN_KEY_ID_PATTERN, $id) || !is_string($raw) || $raw === '') {
                error_log("key ring: ignoring malformed key id '$id'");
                continue;
            }
            $keys[$id] = $raw;
        }
    }
    if (!$keys) {
        $keys = [$legacy_id => (string) $single];
        $current = $legacy_id;
    }
    if (!is_string($current) || !isset($keys[$current])) {
        error_log('key ring: current key id is not in the ring; using the first key');
        $current = array_key_first($keys);
    }
    // Pre-ring values belong to the legacy id only; once it is retired they must fail,
    // never be tried with another key (CBC has no MAC: a wrong key can yield garbage).
    $legacy = isset($keys[$legacy_id]) ? $legacy_id : null;
    return ['keys' => $keys, 'current' => $current, 'legacy' => $legacy];
}

/** The $app_secret ring. */
function wn_app_key_ring() {
    return wn_ring_from($GLOBALS['app_secrets'] ?? null, $GLOBALS['app_secret_current'] ?? null,
                        $GLOBALS['app_secret'] ?? '', 'k1');
}

/** The $hiddenhash (pepper) ring. */
function wn_pepper_ring() {
    return wn_ring_from($GLOBALS['peppers'] ?? null, $GLOBALS['pepper_current'] ?? null,
                        $GLOBALS['hiddenhash'] ?? '', 'p1');
}

/**
 * A 32-byte purpose key ('seal', 'jwt') derived from one ring entry, or null if the id
 * isn't in the ring. Separate purposes never share key bytes.
 */
function wn_key($purpose, $kid = null) {
    $ring = wn_app_key_ring();
    $kid  = $kid ?? $ring['current'];
    if (!isset($ring['keys'][$kid])) { return null; }
    return hash_hkdf('sha256', $ring['keys'][$kid], 32, 'wn/' . $purpose . '/v2');
}

/** The AES-256-CBC key every pre-ring encrypt used (32 hex chars of sha256 of k1), or null once k1 is retired. */
function wn_legacy_cbc_key() {
    $ring = wn_app_key_ring();
    if ($ring['legacy'] === null) return null;
    return substr(hash('sha256', (string) $ring['keys'][$ring['legacy']]), 0, 32);
}

/**
 * Bootstrap step 2e: refuse to serve web requests on the publicly known dev defaults, and point
 * $app_secret / $hiddenhash at the current ring entries.
 */
function wn_key_ring_init($have_config_file) {
    // Web requests only: that is where a lost config.php would silently serve users on
    // publicly known secrets. CLI tools (tests, local scripts) keep the dev fallback.
    if (!$have_config_file && php_sapi_name() !== 'cli' && getenv('WN_ENV') !== 'dev'
        && (getenv('APP_SECRET') === false || getenv('HIDDEN_HASH') === false)) {
        error_log('FATAL: config/config.php is missing and APP_SECRET / HIDDEN_HASH are not set; refusing to run on the dev defaults (set WN_ENV=dev for local use)');
        if (!headers_sent()) { http_response_code(500); }
        echo 'Server configuration error.';
        exit(1);
    }
    if (!empty($GLOBALS['app_secrets'])) {
        $r = wn_app_key_ring();
        $GLOBALS['app_secret'] = $r['keys'][$r['current']];
    }
    if (!empty($GLOBALS['peppers'])) {
        $r = wn_pepper_ring();
        $GLOBALS['hiddenhash'] = $r['keys'][$r['current']];
    }
}

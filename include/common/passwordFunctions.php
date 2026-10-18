<?php
/**
 * passwordFunctions.php
 * Password hashing and verification: bcrypt + a server-side pepper from the
 * $hiddenhash ring (keyRingFunctions.php, docs/core-secret-rotation.md).
 *
 * v2 (written now): '$wn2$<pepper id>$' + bcrypt(base64(HMAC-SHA256(pepper, password))).
 *   The HMAC is 44 bytes, under bcrypt's 72-byte input limit, so the whole password and
 *   the whole pepper always count.
 * v1 (read only): a bare bcrypt hash of password . pepper, under pepper p1 (the original
 *   $hiddenhash). bcrypt reads only 72 bytes, so a long password pushed the pepper out.
 * password_needs_upgrade() is true for v1 and for a pepper that is no longer current, so
 * the login paths' existing rehash moves every user over as they sign in.
 */

require_once __DIR__ . '/keyRingFunctions.php';

define('WN_PASSWORD_V2_PREFIX', '$wn2$');

/** base64(HMAC-SHA256(pepper, password)): what bcrypt sees in v2. */
function wn_password_prehash($password, $pepper) {
    return base64_encode(hash_hmac('sha256', (string) $password, (string) $pepper, true));
}

/** ['pid' => id, 'inner' => bcrypt] for a v2 hash, or null. */
function wn_password_v2_parts($hash) {
    if (!is_string($hash) || strncmp($hash, WN_PASSWORD_V2_PREFIX, 5) !== 0) return null;
    $rest = substr($hash, 5);
    $p = strpos($rest, '$');
    if ($p === false || $p === 0) return null;
    return ['pid' => substr($rest, 0, $p), 'inner' => substr($rest, $p + 1)];
}

/**
 * Hash a password with the current pepper.
 *
 * @param string $password  Plain-text password
 * @return string           '$wn2$<pid>$' + bcrypt hash
 */
function hash_password($password) {
    $ring = wn_pepper_ring();
    $pid  = $ring['current'];
    return WN_PASSWORD_V2_PREFIX . $pid . '$'
        . password_hash(wn_password_prehash($password, $ring['keys'][$pid]), PASSWORD_BCRYPT);
}

/**
 * Verify a password against a stored hash (v2, or v1 under pepper p1).
 *
 * @param string $password  Plain-text password
 * @param string $hash      Stored hash
 * @return bool
 */
function verify_password($password, $hash) {
    $ring = wn_pepper_ring();
    $v2 = wn_password_v2_parts($hash);
    if ($v2) {
        if (!isset($ring['keys'][$v2['pid']])) return false;
        return password_verify(wn_password_prehash($password, $ring['keys'][$v2['pid']]), $v2['inner']);
    }
    if (!isset($ring['keys']['p1'])) return false;   // p1 retired: v1 users reset their password
    return password_verify($password . $ring['keys']['p1'], (string) $hash);
}

/**
 * Check if a stored hash needs rehashing (v1 format, an old pepper, or bcrypt cost).
 *
 * Call after a successful verify_password(). If true, rehash with
 * hash_password() and update the stored hash. This keeps hashes on the current
 * format and pepper transparently on each login.
 *
 * @param string $hash  Stored password hash
 * @return bool         True if the hash should be regenerated
 */
function password_needs_upgrade($hash) {
    $v2 = wn_password_v2_parts($hash);
    if (!$v2) return true;
    if ($v2['pid'] !== wn_pepper_ring()['current']) return true;
    return password_needs_rehash($v2['inner'], PASSWORD_BCRYPT);
}

/**
 * Users per pepper version on one DB (main or a shard): ['p2' => 120, 'v1' => 4, 'none' => 9].
 * 'v1' = still on the original format (pepper p1).
 */
function wn_password_pepper_counts($pdo) {
    $out = [];
    $rows = $pdo->query("SELECT password FROM user")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $h) {
        if ($h === null || $h === '') { $k = 'none'; }
        else { $v2 = wn_password_v2_parts($h); $k = $v2 ? $v2['pid'] : 'v1'; }
        $out[$k] = ($out[$k] ?? 0) + 1;
    }
    return $out;
}

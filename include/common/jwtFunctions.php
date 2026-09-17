<?php
/**
 * jwtFunctions.php
 * JWT token issuing and verification using firebase/php-jwt.
 * Supports access tokens (short-lived) and refresh tokens (long-lived).
 */

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * The HMAC key access tokens are signed with.
 *
 * firebase/php-jwt 7 refuses an HS256 key shorter than 256 bits (32 bytes). The
 * installer has always generated 64 hex chars, but a hand-written or older
 * config can be shorter, and upgrading the library must not lock that app's
 * users out. A short secret is stretched with SHA-256 (32 bytes); a secret that
 * is already long enough is used unchanged, so its existing tokens stay valid.
 * On a stretched deployment, access tokens issued before the upgrade stop
 * verifying and clients renew them with their refresh token (random, stored
 * hashed — not a JWT, so unaffected).
 *
 * @return string|null  null when no secret is configured at all
 */
function jwt_signing_key() {
    global $app_secret;
    $secret = (string)($app_secret ?? '');
    if ($secret === '') {
        error_log('JWT: $app_secret is not configured');
        return null;
    }
    return strlen($secret) >= 32 ? $secret : hash('sha256', 'wn-jwt:' . $secret, true);
}

/**
 * Issue a JWT access token for a user.
 *
 * @param int         $user_id
 * @param int         $ttl       Time-to-live in seconds (default 1 hour)
 * @param string|null $shard_id  Shard identifier (e.g. 'shard1')
 * @return string                Encoded JWT
 */
function jwt_issue($user_id, $ttl = 3600, $shard_id = null) {
    $key = jwt_signing_key();
    if ($key === null) {
        return '';
    }

    $now = time();
    $payload = [
        'iss' => 'wave-networks-core',
        'sub' => $user_id,
        'iat' => $now,
        'exp' => $now + $ttl,
    ];

    if ($shard_id !== null) {
        $payload['shard_id'] = $shard_id;
    }

    return JWT::encode($payload, $key, 'HS256');
}

/**
 * Verify and decode a JWT token.
 *
 * @param string $token
 * @return object|false  Decoded payload or false on failure
 */
function jwt_verify($token) {
    $key = jwt_signing_key();
    if ($key === null) {
        return false;
    }

    try {
        return JWT::decode($token, new Key($key, 'HS256'));
    } catch (\Exception $e) {
        error_log('JWT verify failed: ' . $e->getMessage());
        return false;
    }
}

// ─── Refresh Token Functions ────────────────────────────────────────────────

/**
 * Issue a refresh token and store its hash in the database.
 * Refresh tokens are single-use: each use rotates to a new token.
 *
 * @param int    $user_id
 * @param string $device_id  Device identifier from the client
 * @param string $platform   'ios', 'android', or 'web'
 * @param int    $ttl        Time-to-live in seconds (default 30 days)
 * @return string             The raw refresh token (only returned once)
 */
function jwt_issue_refresh($user_id, $device_id = '', $platform = 'web', $ttl = 2592000) {
    global $db;

    $token = bin2hex(random_bytes(32));
    $hash  = password_hash($token, PASSWORD_BCRYPT);
    $expires = date('Y-m-d H:i:s', time() + $ttl);

    // Revoke any existing token for this user+device combo
    $stmt = $db->prepare(
        "UPDATE refresh_tokens SET revoked_at = NOW()
         WHERE user_id = ? AND device_id = ? AND revoked_at IS NULL"
    );
    $stmt->execute([$user_id, $device_id]);

    // Insert new token
    $stmt = $db->prepare(
        "INSERT INTO refresh_tokens (user_id, token_hash, device_id, platform, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([$user_id, $hash, $device_id, $platform, $expires]);

    return $token;
}

/**
 * Validate and consume a refresh token (single-use rotation).
 * Returns user data if valid, false otherwise.
 *
 * @param string $token      The raw refresh token
 * @param string $device_id  Device identifier
 * @return array|false       ['user_id' => int, 'shard_id' => string] or false
 */
function jwt_verify_refresh($token, $device_id = '') {
    global $db;

    // Get all non-revoked, non-expired tokens for this device
    $stmt = $db->prepare(
        "SELECT rt.id, rt.user_id, rt.token_hash, u.shard_id
         FROM refresh_tokens rt
         JOIN user u ON u.user_id = rt.user_id
         WHERE rt.device_id = ? AND rt.revoked_at IS NULL AND rt.expires_at > NOW()
         ORDER BY rt.created_at DESC"
    );
    $stmt->execute([$device_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (password_verify($token, $row['token_hash'])) {
            // Revoke this token (single-use)
            $stmt2 = $db->prepare("UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?");
            $stmt2->execute([$row['id']]);

            return [
                'user_id'  => (int) $row['user_id'],
                'shard_id' => $row['shard_id'] ?: 'shard1',
            ];
        }
    }

    return false;
}

/**
 * Revoke all refresh tokens for a user (e.g. on logout or password change).
 *
 * @param int         $user_id
 * @param string|null $device_id  If set, only revoke for this device
 */
function jwt_revoke_refresh($user_id, $device_id = null) {
    global $db;

    if ($device_id !== null) {
        $stmt = $db->prepare(
            "UPDATE refresh_tokens SET revoked_at = NOW()
             WHERE user_id = ? AND device_id = ? AND revoked_at IS NULL"
        );
        $stmt->execute([$user_id, $device_id]);
    } else {
        $stmt = $db->prepare(
            "UPDATE refresh_tokens SET revoked_at = NOW()
             WHERE user_id = ? AND revoked_at IS NULL"
        );
        $stmt->execute([$user_id]);
    }
}

<?php
/**
 * jwtFunctions.php
 * JWT token issuing and verification using firebase/php-jwt.
 */

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Issue a JWT token for a user.
 *
 * @param int   $user_id
 * @param int   $ttl      Time-to-live in seconds (default 1 hour)
 * @return string          Encoded JWT
 */
function jwt_issue($user_id, $ttl = 3600) {
    global $app_secret;

    $now = time();
    $payload = [
        'iss' => 'wave-networks-core',
        'sub' => $user_id,
        'iat' => $now,
        'exp' => $now + $ttl,
    ];

    return JWT::encode($payload, $app_secret, 'HS256');
}

/**
 * Verify and decode a JWT token.
 *
 * @param string $token
 * @return object|false  Decoded payload or false on failure
 */
function jwt_verify($token) {
    global $app_secret;

    try {
        return JWT::decode($token, new Key($app_secret, 'HS256'));
    } catch (\Exception $e) {
        error_log('JWT verify failed: ' . $e->getMessage());
        return false;
    }
}

<?php
/**
 * passwordFunctions.php
 * Password hashing and verification using bcrypt + hidden salt.
 */

/**
 * Hash a password with the application salt.
 *
 * @param string $password  Plain-text password
 * @return string           Bcrypt hash
 */
function hash_password($password) {
    global $hiddenhash;
    return password_hash($password . $hiddenhash, PASSWORD_BCRYPT);
}

/**
 * Verify a password against a stored hash.
 *
 * @param string $password  Plain-text password
 * @param string $hash      Stored bcrypt hash
 * @return bool
 */
function verify_password($password, $hash) {
    global $hiddenhash;
    return password_verify($password . $hiddenhash, $hash);
}

/**
 * Check if a stored hash needs rehashing (cost or algorithm upgrade).
 *
 * Call after a successful verify_password(). If true, rehash with
 * hash_password() and update the stored hash. This keeps hashes at
 * current PHP defaults transparently on each login.
 *
 * @param string $hash  Stored password hash
 * @return bool         True if the hash should be regenerated
 */
function password_needs_upgrade($hash) {
    return password_needs_rehash($hash, PASSWORD_BCRYPT);
}

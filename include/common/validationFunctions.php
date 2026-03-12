<?php
/**
 * validationFunctions.php
 * Input validation helpers.
 */

/**
 * Validate an email address.
 *
 * @param string $email
 * @return bool
 */
function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate minimum password length.
 *
 * @param string $password
 * @param int    $min
 * @return bool
 */
function valid_password($password, $min = 8) {
    return strlen($password) >= $min;
}

/**
 * Check if a string is not empty after trimming.
 *
 * @param mixed $val
 * @return bool
 */
function not_empty($val) {
    return isset($val) && trim((string)$val) !== '';
}

/**
 * Validate a numeric ID.
 *
 * @param mixed $val
 * @return bool
 */
function valid_id($val) {
    return is_numeric($val) && (int)$val > 0;
}

/**
 * Validate that a value is within an allowed set.
 *
 * @param mixed $val
 * @param array $allowed
 * @return bool
 */
function in_set($val, array $allowed) {
    return in_array($val, $allowed, true);
}

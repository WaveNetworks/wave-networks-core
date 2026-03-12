<?php
/**
 * hashCodeFunctions.php
 * Java-style hash bucketing for user homedirs + random string generation.
 */

/**
 * Simulate Java 32-bit integer overflow.
 *
 * @param int $v
 * @return int
 */
function overflow32($v) {
    $v = $v & 0xFFFFFFFF;
    if ($v & 0x80000000) {
        return $v - 0x100000000;
    }
    return $v;
}

/**
 * Java String.hashCode() port.
 *
 * @param string $s
 * @return int
 */
function hashCode($s) {
    $h = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $h = overflow32(31 * $h + ord($s[$i]));
    }
    return $h;
}

/**
 * Create a hash array from a string for directory bucketing.
 * SHA-256 → hashCode → binary → 8-bit chunks → deduplicate.
 *
 * @param string $string
 * @return array|false  Array of integer folder names, or false
 */
function createHashArray($string) {
    $sha = hash('sha256', $string);
    $h   = hashCode($sha);
    $bin = decbin(abs($h));
    $bin = str_pad($bin, 32, '0', STR_PAD_LEFT);

    $chunks = str_split($bin, 8);
    $vals = [];
    foreach ($chunks as $chunk) {
        $v = bindec($chunk);
        if (!in_array($v, $vals)) {
            $vals[] = $v;
        }
    }

    return count($vals) > 0 ? $vals : false;
}

/**
 * Create the bucketed directory tree under $files_location.'home/'
 * for the given string.
 *
 * @param string $string  Typically the user_id as a string
 * @return string|false   Full path to the leaf folder, or false
 */
function create_home_dir($string) {
    global $files_location;

    $vals = createHashArray($string);
    if ($vals === false) return false;

    $path = $files_location . 'home/';
    foreach ($vals as $v) {
        $path .= $v . '/';
    }
    $path .= $string . '/';

    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    return $path;
}

/**
 * Create a homedir for a user by ID, and update user_profile.homedir on the shard.
 *
 * @param int $id  user_id
 * @return string  The created path
 */
function create_home_dir_id($id) {
    $path = create_home_dir((string)$id);

    if ($path && isset($_SESSION['shard_id'])) {
        $shard_id = $_SESSION['shard_id'];
        $safe_path = sanitize($path, SQL);
        $safe_id = (int)$id;
        db_query_shard($shard_id, "UPDATE user_profile SET homedir = '$safe_path' WHERE user_id = '$safe_id'");
    }

    return $path;
}

/**
 * Create a bucketed directory under a custom namespace.
 * Used by child apps to create parallel trees (e.g. 'media', 'uploads').
 *
 * @param string $string     Typically the user_id
 * @param string $namespace  e.g. 'media', 'uploads'
 * @return string|false
 */
function create_namespaced_dir($string, $namespace) {
    global $files_location;

    $vals = createHashArray($string);
    if ($vals === false) return false;

    $path = $files_location . $namespace . '/';
    foreach ($vals as $v) {
        $path .= $v . '/';
    }
    $path .= $string . '/';

    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    return $path;
}

/**
 * Generate a cryptographically random alphanumeric string.
 * Used for confirm_hash, forgot tokens, API keys, invite tokens.
 *
 * @param int $length
 * @return string
 */
function generateHashCode($length = 150) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $str = '';
    for ($i = 0; $i < $length; $i++) {
        $str .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $str;
}

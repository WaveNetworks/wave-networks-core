<?php
/**
 * sanitize.inc.php
 * Input sanitization helpers.
 */

define('SQL', 'sql');
define('HTML', 'html');
define('TEXT', 'text');

/**
 * Sanitize a value for a given context.
 *
 * @param mixed  $val  The value to sanitize
 * @param string $type SQL | HTML | TEXT
 * @return string
 */
function sanitize($val, $type = SQL) {
    if ($val === null) return '';

    $val = trim($val);

    switch ($type) {
        case SQL:
            return addslashes($val);
        case HTML:
            return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
        case TEXT:
            return strip_tags($val);
        default:
            return addslashes($val);
    }
}

/**
 * Sanitize an entire array (e.g. $_POST).
 *
 * @param array  $arr
 * @param string $type
 * @return array
 */
function sanitize_array($arr, $type = SQL) {
    $out = [];
    foreach ($arr as $k => $v) {
        if (is_array($v)) {
            $out[$k] = sanitize_array($v, $type);
        } else {
            $out[$k] = sanitize($v, $type);
        }
    }
    return $out;
}

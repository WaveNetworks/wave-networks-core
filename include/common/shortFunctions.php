<?php
/**
 * shortFunctions.php
 * Small utility functions used everywhere.
 */

/**
 * HTML-escape a string for safe output. Use this for ALL user-supplied data.
 *
 * @param mixed $str
 * @return string
 */
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Trim a string to a maximum length, appending "..." if truncated.
 *
 * @param string $str
 * @param int    $len
 * @return string
 */
function nicetrim($str, $len = 50) {
    if (mb_strlen($str) <= $len) return $str;
    return mb_substr($str, 0, $len) . '...';
}

/**
 * Format a number as currency.
 *
 * @param float  $amount
 * @param string $symbol
 * @return string
 */
function formatCurrency($amount, $symbol = '$') {
    return $symbol . number_format((float)$amount, 2);
}

/**
 * Generate a URL-safe slug from a string.
 *
 * @param string $str
 * @return string
 */
function slugify($str) {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9-]/', '-', $str);
    $str = preg_replace('/-+/', '-', $str);
    return trim($str, '-');
}

/**
 * Get the current URL path.
 *
 * @return string
 */
function current_url() {
    return $_SERVER['REQUEST_URI'] ?? '/';
}

/**
 * Redirect and exit.
 *
 * @param string $url
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

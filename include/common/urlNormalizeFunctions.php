<?php
/**
 * URL / contact normalization helpers.
 *
 * Auto-prefix bare emails with "mailto:" and bare URLs/domains with
 * "https://" so admin forms don't reject input that's just missing a
 * scheme. Idempotent — values that already carry a scheme are returned
 * untouched.
 */

if (!function_exists('normalize_url_or_mailto')) {
    /**
     * @param string $val         Raw user input.
     * @param bool   $allow_mailto When true, bare emails become mailto: links.
     *                             When false, everything schemeless becomes https://.
     * @return string Normalized value ('' for empty input).
     */
    function normalize_url_or_mailto($val, $allow_mailto = true) {
        $val = trim((string)$val);
        if ($val === '') { return ''; }

        // Already has a recognised scheme — leave it alone.
        if (preg_match('#^(mailto:|tel:|https?://)#i', $val)) { return $val; }

        // Looks like a bare email address → mailto:
        if ($allow_mailto && preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $val)) {
            return 'mailto:' . $val;
        }

        // Otherwise assume it's a bare URL / domain → https://
        return 'https://' . $val;
    }
}

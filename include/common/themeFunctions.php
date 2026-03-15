<?php
/**
 * themeFunctions.php
 * Bootswatch + registered theme management.
 * Theme is stored in a cookie (wn_theme) set by theme.js so PHP can
 * render the correct stylesheet on first paint — no FOUC.
 */

$GLOBALS['_bootswatch_allowed'] = [
    'cerulean', 'cosmo', 'cyborg', 'darkly', 'flatly', 'journal',
    'litera', 'lumen', 'lux', 'materia', 'minty', 'morph', 'pulse',
    'quartz', 'sandstone', 'simplex', 'sketchy', 'slate', 'solar',
    'spacelab', 'superhero', 'united', 'vapor', 'yeti', 'zephyr'
];

/**
 * Get the active theme name.
 * Reads from cookie (set by theme.js), validated against Bootswatch
 * allowed list and registered custom themes.
 *
 * @return string
 */
function get_active_theme() {
    $theme = $_COOKIE['wn_theme'] ?? 'sandstone';
    if (in_array($theme, $GLOBALS['_bootswatch_allowed'])) {
        return $theme;
    }
    if (function_exists('get_registered_theme') && get_registered_theme($theme)) {
        return $theme;
    }
    return 'sandstone';
}

/**
 * Get the CSS URL for the active theme.
 * Returns a Bootswatch CDN URL, local bootstrap path, or registered theme CSS.
 *
 * @param string $prefix         Path prefix to admin assets (e.g. '../' from admin views)
 * @param string $webroot_prefix Path prefix from current app to webroot (e.g. '../' from admin, '../../' from child)
 * @return string
 */
function get_theme_css_url($prefix = '../', $webroot_prefix = '../../') {
    $theme = get_active_theme();

    // Check registered custom themes
    if (function_exists('get_registered_theme')) {
        $registered = get_registered_theme($theme);
        if ($registered) {
            return $webroot_prefix . $registered['css_path'];
        }
    }

    if ($theme === 'sandstone') {
        return $prefix . 'assets/bootstrap/css/bootstrap.min.css';
    }
    return 'https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/' . $theme . '/bootstrap.min.css';
}

/**
 * Set the theme for the current user (session only).
 *
 * @param string $theme
 */
function set_user_theme($theme) {
    if (in_array($theme, $GLOBALS['_bootswatch_allowed'])) {
        $_SESSION['theme'] = $theme;
    }
}

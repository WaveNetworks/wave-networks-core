<?php
/**
 * themeFunctions.php
 * Bootswatch theme management.
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
 * Get the active Bootswatch theme name.
 * Reads from cookie (set by theme.js), validated against allowed list.
 *
 * @return string
 */
function get_active_theme() {
    $theme = $_COOKIE['wn_theme'] ?? 'sandstone';
    if (in_array($theme, $GLOBALS['_bootswatch_allowed'])) {
        return $theme;
    }
    return 'sandstone';
}

/**
 * Get the CSS URL for the active theme.
 * Returns a Bootswatch CDN URL or the local bootstrap path.
 *
 * @param string $prefix Path prefix to local assets (e.g. '../' from views/)
 * @return string
 */
function get_theme_css_url($prefix = '../') {
    $theme = get_active_theme();
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

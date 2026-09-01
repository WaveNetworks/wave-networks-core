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
/**
 * The compiled theme belonging to the child app deployed alongside this admin.
 *
 * ONE THEME PER APP. Each deployment builds a single Bootstrap theme from its own
 * SCSS, and every surface of that deployment should wear it: the public site, the
 * child app console, and this admin. Previously only the first two did — admin fell
 * through to a generic Bootswatch build off a CDN, so a supporter who signed in
 * crossed from a branded campaign site into something that looked like a different
 * product. That seam is the most jarring one in the whole experience, because it is
 * exactly where you ask someone to trust you with an account.
 *
 * Deployments are one-admin-per-app (each child app's deploy.yml rsyncs core into
 * its own public_html/admin/), so the sibling scan resolves to exactly one app —
 * the same assumption admin/cron/cron.php already makes when it globs
 * ../../{*}/cron/cron.php to dispatch child crons.
 *
 * Returns the slug, or false when no sibling app ships a compiled theme, in which
 * case behaviour is unchanged.
 */
function get_deployment_app_theme() {
    static $cached = null;
    if ($cached !== null) return $cached;

    // themeFunctions.php lives at admin/include/common/, so three levels up is the
    // web root that holds admin/ and the child app side by side.
    $webroot = dirname(__DIR__, 3);
    foreach (glob($webroot . '/*/assets/css/custom.css') ?: [] as $file) {
        $slug = basename(dirname($file, 3));
        if ($slug === 'admin' || $slug === '') continue;
        return $cached = ['slug' => $slug, 'file' => $file];
    }
    return $cached = false;
}

function get_theme_css_url($prefix = '../', $webroot_prefix = '../../') {
    $theme = get_active_theme();

    // Check registered custom themes
    if (function_exists('get_registered_theme')) {
        $registered = get_registered_theme($theme);
        if ($registered) {
            return $webroot_prefix . $registered['css_path'];
        }
    }

    // The deployment's own app theme is the DEFAULT, ahead of any Bootswatch build.
    // Only an explicitly chosen theme (a registered one above, or a non-default
    // wn_theme cookie handled by the child app) overrides it. Cache-busted on mtime
    // so a rebuilt theme is picked up immediately rather than after a CDN TTL.
    $app = get_deployment_app_theme();
    if ($app) {
        $v = @filemtime($app['file']);
        return $webroot_prefix . $app['slug'] . '/assets/css/custom.css' . ($v ? '?v=' . $v : '');
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

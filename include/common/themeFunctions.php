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

function get_deployment_registered_theme() {
    /**
     * The theme this deployment's child app registered for itself.
     *
     * Deployments are one-admin-per-app, so at most one sibling app registers a
     * theme here — the same assumption get_deployment_app_theme() and
     * admin/cron/cron.php already make. Prefer a row whose created_by_app names
     * a real sibling; fall back to the single active row when only one exists.
     *
     * Returns the theme row, or false. A false here means the deployment has no
     * declared identity at all, which is worth SAYING rather than silently
     * dressing admin in a stock theme — that is how vivajee, pwt and elevateher
     * drifted for months without anyone being told.
     */
    static $cached = null;
    if ($cached !== null) return $cached;

    if (!function_exists('get_registered_themes')) { return $cached = false; }
    $themes = get_registered_themes();
    if (!$themes) {
        error_log('theme: this deployment has no registered app theme — admin will '
                . 'fall back to a stock build. The child app should call register_theme().');
        return $cached = false;
    }

    $webroot = dirname(__DIR__, 3);
    foreach ($themes as $t) {
        $owner = $t['created_by_app'] ?? '';
        if ($owner !== '' && $owner !== 'admin' && is_dir($webroot . '/' . $owner)) {
            return $cached = $t;
        }
    }
    return $cached = (count($themes) === 1 ? $themes[0] : false);
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

    // The theme the sibling app REGISTERED is the default, ahead of anything on
    // disk or any Bootswatch build.
    //
    // This used to jump straight to the file scan below, which looks for
    // assets/css/custom.css — and that file is a 253KB STOCK BOOTSTRAP build
    // committed once by the child-app scaffold, not the app's theme. So admin
    // wore generic Bootstrap on apps that had it, and Bootswatch sandstone on
    // the older apps that never got it. Neither is the app's own theme, which
    // is the whole point of ONE THEME PER APP.
    //
    // register_theme() is authoritative: the child app declares its compiled
    // theme's real path on every request, so it is right even for an app that
    // ships several themes, and it cannot go stale against a rebuild.
    $registered_default = get_deployment_registered_theme();
    if ($registered_default) {
        return $webroot_prefix . $registered_default['css_path'];
    }

    // Fallback: a compiled theme sitting next to us on disk. Cache-busted on
    // mtime so a rebuild is picked up immediately rather than after a CDN TTL.
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

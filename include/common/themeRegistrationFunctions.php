<?php
/**
 * themeRegistrationFunctions.php
 * Child apps register custom themes here so they appear in every app's theme switcher.
 * Follows the same idempotent pattern as register_notification_category().
 */

/**
 * Register a custom theme (idempotent). Child apps call this at bootstrap.
 *
 * @param string $slug      Unique theme identifier (used in wn_theme cookie)
 * @param string $name      Display name for dropdown
 * @param string $css_path  CSS path relative to webroot
 * @param array  $opts      sidebar_mode (dark|glass), created_by_app, is_active
 */
function register_theme($slug, $name, $css_path, $opts = []) {
    global $db;
    $s_slug  = sanitize($slug, SQL);
    $s_name  = sanitize($name, SQL);
    $s_css   = sanitize($css_path, SQL);
    $s_mode  = sanitize($opts['sidebar_mode'] ?? 'dark', SQL);
    $s_app   = isset($opts['created_by_app'])
        ? "'" . sanitize($opts['created_by_app'], SQL) . "'"
        : 'NULL';
    $active  = (int)($opts['is_active'] ?? 1);

    try {
        $db->exec("INSERT INTO registered_theme
            (slug, name, css_path, sidebar_mode, created_by_app, is_active)
            VALUES ('$s_slug', '$s_name', '$s_css', '$s_mode', $s_app, $active)
            ON DUPLICATE KEY UPDATE
                name = '$s_name',
                css_path = '$s_css',
                sidebar_mode = '$s_mode',
                is_active = $active");
    } catch (PDOException $e) {
        // Table may not exist yet (pre-migration). Silently ignore.
    }
}

/**
 * Get all active registered themes. Cached per request.
 *
 * @return array
 */
function get_registered_themes() {
    static $cache = null;
    if ($cache !== null) return $cache;

    global $db;
    try {
        $r = $db->query("SELECT slug, name, css_path, sidebar_mode, created_by_app
                         FROM registered_theme
                         WHERE is_active = 1
                         ORDER BY name ASC");
        $cache = $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Get registered themes as JSON for embedding in HTML data attributes.
 *
 * @return string
 */
function get_registered_themes_json() {
    return json_encode(get_registered_themes(), JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Look up a single registered theme by slug.
 *
 * @param string $slug
 * @return array|null
 */
function get_registered_theme($slug) {
    foreach (get_registered_themes() as $t) {
        if ($t['slug'] === $slug) return $t;
    }
    return null;
}

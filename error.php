<?php
/**
 * error.php — Standalone error page for 403, 404, 500 responses.
 * Used as Apache ErrorDocument target. Also logs HTTP errors to the
 * admin error_log DB table for security monitoring (scan detection).
 *
 * Works without authentication — uses common_auth.php for branding only.
 */

// Determine HTTP status code
$status = http_response_code();
if (!in_array($status, [403, 404, 500])) {
    $status = 404; // fallback
}

// Error metadata
$errors = [
    403 => ['title' => 'Forbidden',        'icon' => 'bi-shield-lock',          'message' => 'You don\'t have permission to access this resource.'],
    404 => ['title' => 'Not Found',         'icon' => 'bi-exclamation-triangle', 'message' => 'The page you\'re looking for doesn\'t exist or has been moved.'],
    500 => ['title' => 'Server Error',      'icon' => 'bi-bug',                 'message' => 'Something went wrong on our end. Please try again later.'],
];
$err = $errors[$status];

// Try to bootstrap branding (graceful fallback if DB is down)
$branding = null;
try {
    include_once(__DIR__ . '/include/common_auth.php');
    $branding = get_branding();

    // Log to error_log DB table for security monitoring (skip known noise patterns)
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    // Browser DevTools auto-fetch source maps for vendored CSS/JS; these 404s
    // are not real errors and just spam the security monitor.
    // Media proxy 404s (media/branding/tour_media) are also noise — they happen
    // when stored content references a deleted or never-existing asset.
    // Probes for opcache reset/flush scripts (opcache_flush.php, opcache_reset.php)
    // are vulnerability-scanner noise — we deliberately don't expose such an
    // endpoint, so these 404s are expected scanner traffic, not a real bug.
    // Bare GETs of the deny-protected internal directories (vendor, db_migrations,
    // cron, tests, include, config, views, snippets) are scanner attempts to list
    // server-side source — they're never legitimately web-fetched (they're PHP
    // includes), so the .htaccess deny + this 404 is expected probe traffic.
    $is_noise = ($status === 404 && (
        preg_match('#\.(css|js)\.map($|\?)#', $uri)
        || preg_match('#/admin/(media|branding|tour_media)/#', $uri)
        || preg_match('#/opcache_(flush|reset)\.php($|\?)#', $uri)
        || preg_match('#/admin/(vendor|db_migrations|cron|tests|include|config|views|snippets)/#', $uri)
    ));
    if (function_exists('log_error_to_db') && !$is_noise) {
        $msg = "HTTP $status: $method $uri";
        log_error_to_db('WARNING', $msg, __FILE__, 0, null);
    }
} catch (Exception $e) {
    // Branding unavailable — use fallback
}

// Fallback branding
if (!$branding) {
    $branding = [
        'site_name'   => 'Admin',
        'theme_color' => '#212529',
        'favicon_path' => null,
        'logo_path'    => null,
        'logo_dark_path' => null,
    ];
    if (!function_exists('h')) {
        function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
    }
}
if (!function_exists('get_theme_css_url')) {
    function get_theme_css_url() { return 'https://cdn.jsdelivr.net/npm/bootswatch@5/dist/sandstone/bootstrap.min.css'; }
}

$page_title = $status . ' — ' . $err['title'];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function(){var s=localStorage.getItem('wn_color_mode');
    var m=s||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');
    document.documentElement.setAttribute('data-bs-theme',m);})();
    </script>
    <title><?= h($page_title) ?></title>
    <meta name="theme-color" content="<?= h($branding['theme_color']) ?>">
    <?php if (!empty($branding['favicon_path'])) { ?>
    <link rel="icon" href="/admin/branding/<?= h($branding['favicon_path']) ?>">
    <?php } ?>
    <link rel="stylesheet" href="<?= h(get_theme_css_url()) ?>" id="themeStylesheet">
    <link rel="stylesheet" href="/admin/assets/css/style.css">
    <link rel="stylesheet" href="/admin/assets/css/bs-theme-overrides.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: var(--bs-tertiary-bg); }
        .error-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .error-card { width: 100%; max-width: 480px; text-align: center; }
    </style>
</head>
<body>
<div class="error-wrapper">
    <div class="error-card">
        <?php if (!empty($branding['logo_path'])) { ?>
        <div class="mb-3">
            <img src="/admin/branding/<?= h($branding['logo_path']) ?>" alt="<?= h($branding['site_name']) ?>" style="max-height: 48px;"<?php if (!empty($branding['logo_dark_path'])) { ?> data-logo-dark="/admin/branding/<?= h($branding['logo_dark_path']) ?>" data-logo-light="/admin/branding/<?= h($branding['logo_path']) ?>"<?php } ?>>
        </div>
        <?php } ?>
        <div class="card shadow-sm">
            <div class="card-body p-5">
                <div class="mb-3">
                    <i class="bi <?= $err['icon'] ?> text-warning" style="font-size: 3rem;"></i>
                </div>
                <h1 class="display-4 fw-bold text-body-emphasis mb-2"><?= $status ?></h1>
                <h4 class="text-body-secondary mb-3"><?= h($err['title']) ?></h4>
                <p class="text-body-tertiary mb-4"><?= h($err['message']) ?></p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="/admin/auth/login.php" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </a>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Go Back
                    </a>
                </div>
            </div>
        </div>
        <p class="text-center text-body-tertiary mt-3 small">
            &copy; <?= date('Y') ?> <?= h($branding['site_name']) ?>
        </p>
    </div>
</div>
</body>
</html>

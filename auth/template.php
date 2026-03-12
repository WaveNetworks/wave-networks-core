<?php
/**
 * auth/template.php
 * Minimal HTML shell for auth pages (centered card layout).
 * $page_title and $page_content must be set before including this.
 */
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
    <?php $b = get_branding(); ?>
    <title><?= h($page_title ?? $b['site_name']) ?></title>
    <meta name="theme-color" content="<?= h($b['theme_color']) ?>">
    <?php if (!empty($b['favicon_path'])) { ?>
    <link rel="icon" href="../uploads/<?= h($b['favicon_path']) ?>">
    <link rel="apple-touch-icon" href="../uploads/<?= h($b['favicon_path']) ?>">
    <?php } ?>
    <link rel="manifest" href="../manifest.php">
    <link rel="stylesheet" href="<?= h(get_theme_css_url()) ?>">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/bs-theme-overrides.css">
    <style>
        body { background-color: var(--bs-tertiary-bg); }
        .auth-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .auth-card { width: 100%; max-width: 420px; }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center mb-4">
            <?php if (!empty($b['logo_path'])) { ?>
            <img src="../uploads/<?= h($b['logo_path']) ?>" alt="<?= h($b['site_name']) ?>" style="max-height: 64px;" class="mb-2">
            <?php } ?>
            <h2><?= h($b['site_name']) ?></h2>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                <?php include(__DIR__ . '/../snippets/feedback.php'); ?>
                <?php if (isset($page_content_html)) { echo $page_content_html; }
                elseif (isset($page_content) && file_exists($page_content)) { include($page_content); } ?>
            </div>
        </div>

        <p class="text-center text-muted mt-3 small">
            &copy; <?= date('Y') ?> <?= h($b['site_name']) ?>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
/**
 * views/template.php
 * Full HTML shell with sidebar + topnav + theme switcher.
 * $current_page_file is set by app/index.php.
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
    <title><?= h($page_title ?? 'Admin') ?> — <?= h($b['site_name']) ?></title>
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="<?= h($b['theme_color_light'] ?? $b['theme_color']) ?>">
    <meta name="theme-color" media="(prefers-color-scheme: dark)"  content="<?= h($b['theme_color_dark']  ?? $b['theme_color']) ?>">
    <?php if (!empty($b['favicon_path'])) { ?>
    <link rel="icon" href="../branding/<?= h($b['favicon_path']) ?>">
    <link rel="apple-touch-icon" href="../branding/<?= h($b['favicon_path']) ?>">
    <?php } ?>
    <link rel="manifest" href="../manifest.php">
    <link rel="stylesheet" href="<?= h(get_theme_css_url()) ?>" id="themeStylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260316">
    <link rel="stylesheet" href="../assets/css/bs-theme-overrides.css?v=2">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>

<div class="d-flex" id="wrapper">

    <!-- REGION: sidebar -->
    <div class="bg-dark text-white d-flex flex-column" id="sidebar" data-bs-theme="dark">
        <a href="../../" class="sidebar-brand px-3 py-2 text-white text-decoration-none">
            <?php if (!empty($b['logo_path'])) { ?>
            <span class="sidebar-brand-icon"><img src="../branding/<?= h($b['logo_path']) ?>" alt=""<?php if (!empty($b['logo_dark_path'])) { ?> data-logo-dark="../branding/<?= h($b['logo_dark_path']) ?>" data-logo-light="../branding/<?= h($b['logo_path']) ?>"<?php } ?>></span>
            <?php } else { ?>
            <span class="sidebar-brand-icon"><i class="bi bi-broadcast"></i></span>
            <?php } ?>
            <span class="sidebar-brand-text"><?= h($b['site_name']) ?></span>
        </a>
        <hr class="text-secondary my-0">
        <nav class="nav flex-column p-2 flex-grow-1">
            <a class="nav-link text-white <?= ($page ?? '') === 'dashboard' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=dashboard">
                <i class="bi bi-speedometer2 sidebar-icon"></i>
                <span class="sidebar-text">Dashboard</span>
            </a>
            <?php $notifActive = in_array($page ?? '', ['notifications', 'notification_preferences']); ?>
            <a class="nav-link text-white <?= $notifActive ? 'active bg-primary rounded' : '' ?>" href="index.php?page=notifications">
                <i class="bi bi-bell sidebar-icon"></i>
                <span class="sidebar-text">Notifications</span>
            </a>
            <?php if (has_role('admin')) { ?>
            <a class="nav-link text-white <?= ($page ?? '') === 'users' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=users">
                <i class="bi bi-people sidebar-icon"></i>
                <span class="sidebar-text">Users</span>
            </a>
            <a class="nav-link text-white <?= ($page ?? '') === 'media' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=media">
                <i class="bi bi-images sidebar-icon"></i>
                <span class="sidebar-text">Media</span>
            </a>
            <?php $settingsOpen = in_array($page ?? '', ['settings', 'email', 'oauth_providers', 'saml_providers', 'migration', 'error_log', 'api_keys', 'onboarding_tours']); ?>
            <a class="nav-link text-white sidebar-parent"
               data-bs-toggle="collapse" href="#settingsMenu" role="button"
               aria-expanded="<?= $settingsOpen ? 'true' : 'false' ?>" aria-controls="settingsMenu">
                <i class="bi bi-gear sidebar-icon"></i>
                <span class="sidebar-text">Settings</span>
                <i class="bi bi-chevron-down sidebar-caret sidebar-text ms-auto"></i>
            </a>
            <div class="collapse <?= $settingsOpen ? 'show' : '' ?>" id="settingsMenu">
                <nav class="nav flex-column sidebar-submenu">
                    <a class="nav-link text-white <?= ($page ?? '') === 'settings' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=settings">
                        <span class="sidebar-text">Basic Settings</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'email' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=email">
                        <span class="sidebar-text">Email</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'oauth_providers' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=oauth_providers">
                        <span class="sidebar-text">OAuth Providers</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'saml_providers' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=saml_providers">
                        <span class="sidebar-text">SAML Providers</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'migration' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=migration">
                        <span class="sidebar-text">Migration</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'error_log' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=error_log">
                        <span class="sidebar-text">Error Log</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'api_keys' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=api_keys">
                        <span class="sidebar-text">API Keys</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'onboarding_tours' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=onboarding_tours">
                        <span class="sidebar-text">Onboarding Tours</span>
                    </a>
                </nav>
            </div>
            <a class="nav-link text-white <?= ($page ?? '') === 'notification_admin' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=notification_admin">
                <i class="bi bi-bell-fill sidebar-icon"></i>
                <span class="sidebar-text">Notification Admin</span>
            </a>

            <a class="nav-link text-white <?= ($page ?? '') === 'feedback_admin' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=feedback_admin">
                <i class="bi bi-chat-dots sidebar-icon"></i>
                <span class="sidebar-text">Feedback</span>
            </a>

            <a class="nav-link text-white <?= ($page ?? '') === 'use_cases' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=use_cases">
                <i class="bi bi-check2-square sidebar-icon"></i>
                <span class="sidebar-text">Use Cases</span>
            </a>

            <a class="nav-link text-white <?= ($page ?? '') === 'mobile_parity' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=mobile_parity">
                <i class="bi bi-phone sidebar-icon"></i>
                <span class="sidebar-text">Mobile Parity</span>
            </a>

            <a class="nav-link text-white <?= ($page ?? '') === 'costs' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=costs">
                <i class="bi bi-cash-stack sidebar-icon"></i>
                <span class="sidebar-text">Costs</span>
            </a>

            <a class="nav-link text-white <?= ($page ?? '') === 'stripe' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=stripe">
                <i class="bi bi-credit-card sidebar-icon"></i>
                <span class="sidebar-text">Stripe</span>
            </a>

            <?php $reportsOpen = str_starts_with($page ?? '', 'reports'); ?>
            <a class="nav-link text-white sidebar-parent"
               data-bs-toggle="collapse" href="#reportsMenu" role="button"
               aria-expanded="<?= $reportsOpen ? 'true' : 'false' ?>" aria-controls="reportsMenu">
                <i class="bi bi-graph-up sidebar-icon"></i>
                <span class="sidebar-text">Reports</span>
                <i class="bi bi-chevron-down sidebar-caret sidebar-text ms-auto"></i>
            </a>
            <div class="collapse <?= $reportsOpen ? 'show' : '' ?>" id="reportsMenu">
                <nav class="nav flex-column sidebar-submenu">
                    <a class="nav-link text-white <?= ($page ?? '') === 'reports' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=reports">
                        <span class="sidebar-text">Overview</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'reports_acquisition' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=reports_acquisition">
                        <span class="sidebar-text">Acquisition</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'reports_retention' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=reports_retention">
                        <span class="sidebar-text">Retention</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'reports_forecast' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=reports_forecast">
                        <span class="sidebar-text">Forecast</span>
                    </a>
                </nav>
            </div>
            <?php } ?>

            <?php
            // Analytics — visible to anyone whose scope is broader than 'self'/'none'.
            // Sits OUTSIDE the has_role('admin') gate so coaches/producers can reach it.
            $analyticsScope = function_exists('get_visible_user_scope')
                ? get_visible_user_scope($_SESSION['user_id'] ?? 0)
                : ['type' => 'none'];
            $analyticsVisible = in_array($analyticsScope['type'], ['all', 'company', 'coached'], true);
            $analyticsOpen    = in_array($page ?? '', ['analytics_overview', 'analytics_activity', 'analytics_cohorts']);
            if ($analyticsVisible) { ?>
            <a class="nav-link text-white sidebar-parent"
               data-bs-toggle="collapse" href="#analyticsMenu" role="button"
               aria-expanded="<?= $analyticsOpen ? 'true' : 'false' ?>" aria-controls="analyticsMenu">
                <i class="bi bi-bar-chart sidebar-icon"></i>
                <span class="sidebar-text">Analytics</span>
                <i class="bi bi-chevron-down sidebar-caret sidebar-text ms-auto"></i>
            </a>
            <div class="collapse <?= $analyticsOpen ? 'show' : '' ?>" id="analyticsMenu">
                <nav class="nav flex-column sidebar-submenu">
                    <a class="nav-link text-white <?= ($page ?? '') === 'analytics_overview' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=analytics_overview">
                        <span class="sidebar-text">Overview</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'analytics_activity' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=analytics_activity">
                        <span class="sidebar-text">Activity</span>
                    </a>
                    <a class="nav-link text-white <?= ($page ?? '') === 'analytics_cohorts' ? 'active bg-primary rounded' : '' ?>" href="index.php?page=analytics_cohorts">
                        <span class="sidebar-text">Cohorts</span>
                    </a>
                </nav>
            </div>
            <?php } ?>
        </nav>
        <div class="sidebar-footer text-center d-none d-md-block p-2 border-top border-secondary border-opacity-25">
            <button class="btn btn-sm btn-outline-light rounded-circle" id="sidebarToggle" type="button">
                <i class="bi bi-chevron-left" id="sidebarToggleIcon"></i>
            </button>
        </div>
    </div>

    <!-- Main content -->
    <div class="flex-grow-1">

        <!-- REGION: topnav -->
        <nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom px-3 py-2" id="topNav">
            <button class="btn btn-sm btn-outline-secondary me-2 d-md-none" id="sidebarToggleTop" type="button">
                <i class="bi bi-list"></i>
            </button>
            <span class="navbar-brand mb-0 h6">&nbsp;</span>
            <div class="ms-auto d-flex align-items-center">
                <!-- Notification bell -->
                <div class="dropdown me-2" id="notificationBell">
                    <button class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="Notifications">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="notifBadge">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow" style="width: 360px; max-height: 420px; overflow-y: auto;" id="notifDropdown">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <strong class="small">Notifications</strong>
                            <a href="#" id="markAllReadBtn" class="text-muted small text-decoration-none">Mark all read</a>
                        </div>
                        <div id="notifList"><div class="text-center py-3 text-muted small">Loading...</div></div>
                        <div class="border-top text-center py-2">
                            <a href="index.php?page=notifications" class="text-decoration-none small">View all notifications</a>
                        </div>
                    </div>
                </div>

                <!-- Color mode toggle -->
                <button class="btn btn-sm btn-outline-secondary me-2" id="colorModeToggle" type="button" title="Toggle dark mode">
                    <i class="bi bi-moon-fill"></i>
                </button>

                <!-- Theme switcher -->
                <select class="form-select form-select-sm me-3" id="themeSelector" style="width: auto;"
                        data-registered-themes='<?= get_registered_themes_json() ?>'>
                    <option value="sandstone">Sandstone</option>
                </select>

                <!-- User dropdown -->
                <div class="dropdown">
                    <a class="btn btn-sm btn-outline-secondary dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <?= h($_SESSION['first_name'] ?? '') ?><span class="d-none d-md-inline"> <?= h($_SESSION['last_name'] ?? '') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted"><?= h($_SESSION['email'] ?? '') ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="index.php?page=account_security"><i class="bi bi-shield-lock me-2"></i>Account & Security</a></li>
                        <li>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="tourRestart">
                                <button type="submit" class="dropdown-item"><i class="bi bi-compass me-2"></i>Restart tour</button>
                            </form>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="logout">
                                <button type="submit" class="dropdown-item">Logout</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="container-fluid p-4">

            <!-- Restrict install/ directory permissions after setup -->
            <?php
            $installDir = __DIR__ . '/../install';
            if (is_dir($installDir) && is_readable($installDir)) {
                // Remove read/execute for group+others so Apache cannot serve install pages
                @chmod($installDir, 0700);
                $it = new RecursiveDirectoryIterator($installDir, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    @chmod($file->getRealPath(), $file->isDir() ? 0700 : 0600);
                }
            }
            ?>

            <!-- REGION: feedback -->
            <?php include(__DIR__ . '/../snippets/feedback.php'); ?>

            <!-- REGION: content -->
            <?php if (isset($current_page_file) && file_exists($current_page_file)) {
                include($current_page_file);
            } ?>
        </div>

        <!-- REGION: footer -->
        <?php
        $childApps = [];
        $publicHtml = __DIR__ . '/../../';
        if (is_dir($publicHtml)) {
            foreach (scandir($publicHtml) as $dir) {
                if ($dir === '.' || $dir === '..' || $dir === 'admin') continue;
                if (is_dir($publicHtml . $dir) && file_exists($publicHtml . $dir . '/app/index.php')) {
                    $childApps[] = $dir;
                }
            }
        }
        ?>
        <?php if (!empty($childApps)) { ?>
        <footer class="app-footer px-4 py-2 d-flex align-items-center border-top border-secondary border-opacity-25">
            <nav class="small">
                <?php foreach ($childApps as $appDir) { ?>
                <a href="../../<?= h($appDir) ?>/app/" class="badge bg-primary bg-opacity-75 text-decoration-none me-1"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(ucwords(str_replace('-', ' ', $appDir))) ?></a>
                <?php } ?>
            </nav>
        </footer>
        <?php } ?>
    </div>
</div>

<!-- REGION: footer-scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/bs-init.js"></script>
<script src="../assets/js/error-reporter.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/color-mode.js"></script>
<script src="../assets/js/theme.js"></script>
<script src="../assets/js/notifications.js"></script>
<?php
// REGION: onboarding tour bootstrap
if (function_exists('get_active_tour_for_user') && !empty($_SESSION['user_id'])) {
    $__previewSlug = $_GET['preview_tour'] ?? '';
    $__onb = null;
    if ($__previewSlug !== '' && function_exists('has_role') && has_role('admin')) {
        $__s = sanitize($__previewSlug, SQL);
        $__t = db_fetch(db_query("SELECT * FROM onboarding_tour WHERE slug = '$__s'"));
        if ($__t) {
            $__tid = (int)$__t['tour_id'];
            $__steps = db_fetch_all(db_query(
                "SELECT * FROM onboarding_tour_step WHERE tour_id = '$__tid' ORDER BY step_order ASC, step_id ASC"
            )) ?: [];
            $__onb = ['tour' => $__t, 'steps' => $__steps, 'state' => ['status'=>'not_started','current_step'=>0], 'preview' => true];
        }
    } else {
        $__onb = get_active_tour_for_user($_SESSION['user_id'], $_SESSION['shard_id'] ?? '');
    }
    if ($__onb) {
        $__payload = [
            'tour'         => [
                'slug'                  => $__onb['tour']['slug'],
                'name'                  => $__onb['tour']['name'],
                'welcome_title'         => $__onb['tour']['welcome_title'],
                'welcome_body_md'       => $__onb['tour']['welcome_body_md'],
                'welcome_video_url'     => $__onb['tour']['welcome_video_url'] ?? '',
                'welcome_cta_primary'   => $__onb['tour']['welcome_cta_primary'],
                'welcome_cta_secondary' => $__onb['tour']['welcome_cta_secondary'],
            ],
            'steps'        => array_map(function ($s) {
                return [
                    'selector' => $s['selector'],
                    'title'    => $s['title'],
                    'body_md'  => $s['body_md'],
                    'position' => $s['position'],
                    'action'   => $s['action'],
                ];
            }, $__onb['steps']),
            'status'       => $__onb['state']['status'] ?? 'not_started',
            'current_step' => (int)($__onb['state']['current_step'] ?? 0),
            'preview'      => !empty($__onb['preview']),
        ];
        // JSON_HEX_TAG/AMP/APOS/QUOT keep tour strings (welcome_body_md, step body_md, etc.)
        // from breaking out of the inline <script> — a literal "</script>" or stray quote
        // in admin-edited markdown was triggering "Unexpected token }" SyntaxErrors here.
        echo "\n<script>window.WN_ONBOARDING = " . json_encode(
            $__payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        ) . ";</script>\n";
        echo '<script src="../assets/js/onboarding.js?v=20260522"></script>' . "\n";
    }
}
?>
<?php include(__DIR__ . '/../snippets/feedback_tab.php'); ?>
</body>
</html>

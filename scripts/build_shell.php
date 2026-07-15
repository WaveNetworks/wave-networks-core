<?php
/**
 * build_shell.php — generate the mobile shell FROM template.php (spec 05).
 *
 *   php scripts/build_shell.php
 *
 * The point: stop hand-approximating the chrome. The desktop shell — sidebar, topnav,
 * notification bell, user menu, colour-mode toggle, settings panel, footer, feedback tab,
 * modals, background canvas — already exists in views/template.php, is already responsive,
 * and already uses the app's real CSS. So the mobile shell IS template.php, rendered once
 * and adapted for a bundled Bearer-auth client. Anything added to template.php later (the
 * next "you missed X") flows into the app on the next build, for free.
 *
 * What this does to the rendered template:
 *   1. renders it with a stubbed logged-in context (no DB) so ALL chrome renders —
 *      feedback_tab.php and the right panel bail when $_SESSION['user_id'] is empty.
 *   2. runs it through wn_split_view() — the SAME splitter the views use — so the shell's
 *      inline handlers become data-act and its inline scripts are hoisted out. The bundle
 *      runs under CSP script-src 'self'; nothing inline may execute, chrome included.
 *   3. rewrites every asset URL to a vendored, relative path (no CDN, no cross-repo
 *      absolutes) — the bundle must be self-contained to run offline / from file://.
 *   4. swaps the desktop nav/apiPost/spa-nav layer for the mobile one: my router renders
 *      wet fragments into #content-dynamic (template's own mount), interceptLinks routes
 *      the sidebar/topnav page links, api.js provides a Bearer apiPost.
 *
 * Branding and the signed-in user are stubbed here and HYDRATED at runtime by shell.js.
 */

// Lives in core, builds a CHILD APP. $coreRoot = the splitter; $appRoot = the app whose
// views/template.php is rendered. --app-root from the child's release script; default cwd.
$coreRoot = dirname(__DIR__);
require_once($coreRoot . '/include/mobile/split_view.php');
$appRoot = getcwd();
$appOrigin = '';   // the app's API/media origin, e.g. https://vivajee.com — for the CSP
foreach ($argv as $a) {
    if (strpos($a, '--app-root=')   === 0) $appRoot   = rtrim(substr($a, 11), '/');
    if (strpos($a, '--app-origin=') === 0) $appOrigin = rtrim(substr($a, 13), '/');
}
if (!is_file($appRoot . '/views/template.php')) {
    fwrite(STDERR, "build_shell: --app-root=$appRoot has no views/template.php\n");
    exit(1);
}

// ── 1. Stub the runtime template.php expects, then render it ──────────────────
// build_shell renders template.php WITHOUT a DB, so it stubs whatever the template calls.
// CORE stubs (below) cover the platform functions every template uses, with neutral
// defaults — shell.js hydrates the real branding/user at runtime. An app whose template
// references its OWN functions (e.g. an unread-count helper) provides them in an optional
// include/mobile/shell_stubs.php; we load that FIRST so its stubs win.
if (is_file($appRoot . '/include/mobile/shell_stubs.php')) {
    include($appRoot . '/include/mobile/shell_stubs.php');
}
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
if (!function_exists('get_branding')) { function get_branding(){ return ['site_name'=>'App','theme_color'=>'#666666','logo_path'=>'','logo_dark_path'=>'','favicon_path'=>'']; } }
if (!function_exists('get_app_theme_css_url')) { function get_app_theme_css_url(){ return '__THEME_CSS__'; } }
if (!function_exists('has_role')) { function has_role($r){ return false; } }
if (!function_exists('child_prime_shard')) { function child_prime_shard($s){} }
if (!function_exists('child_db_query_shard_prepared')) { function child_db_query_shard_prepared(){ return null; } }
if (!function_exists('db_fetch')) { function db_fetch($r){ return false; } }
if (!defined('CHILD_APP_NAME')) define('CHILD_APP_NAME', get_branding()['site_name'] ?? 'App');
if (!defined('WN_CHILD_APP_VERSION')) define('WN_CHILD_APP_VERSION', '1.0.0');

$_SESSION = ['user_id' => 1, 'shard_id' => 'shard1', 'first_name' => '', 'email' => ''];
$page = '';                 // no nav item forced active — the router sets it live
$page_title = get_branding()['site_name'] ?? 'App';
$current_page_file = null;   // #content-dynamic ships empty; the router fills it

ob_start();
include($appRoot . '/views/template.php');
$html = ob_get_clean();

// ── 2. Rewrite asset URLs to vendored, relative, self-contained paths ─────────
// Left side = what template.php emits; right side = where release-mobile.sh puts it in m/.
$assetMap = [
    '../../admin/assets/css/style.css'            => 'assets/vendor/style.css',
    '../../admin/assets/css/bs-theme-overrides.css'=> 'assets/vendor/bs-theme-overrides.css',
    '__THEME_CSS__'                                => 'assets/vendor/theme.css',
    '../../admin/assets/js/bs-init.js'            => '__DROP__',   // replaced by api.js (Bearer apiPost)
    '../../admin/assets/js/error-reporter.js'     => 'js/vendor/error-reporter.js',
    '../../admin/assets/js/sidebar.js'            => 'js/vendor/sidebar.js',
    '../../admin/assets/js/color-mode.js'         => 'js/vendor/color-mode.js',
    '../../admin/assets/js/notifications.js'      => 'js/vendor/notifications.js',
    '../assets/js/modal.js'                       => 'js/vendor/modal.js',
    '../assets/js/theme.js'                       => '__DROP__',   // desktop theme picker; mobile is fixed brand
    '../assets/js/toast.js'                       => 'js/vendor/toast.js',
    '../assets/js/page-nav.js'                    => 'js/vendor/page-nav.js',
    '../assets/js/bg-canvas.js'                   => 'js/vendor/bg-canvas.js',
    '../assets/js/celebrate.js'                   => 'js/vendor/celebrate.js',
    '../assets/js/spa-nav.js'                     => '__DROP__',   // replaced by router.js (CSP-safe fragments)
];

// Strip the query-string cache-busters template.php adds (e.g. style.css?v=2026…).
$html = preg_replace('/(\.(?:css|js))\?[^"\'\s>]*/', '$1', $html);

foreach ($assetMap as $from => $to) {
    if ($to === '__DROP__') {
        // Remove the whole <script src="…"> / <link href="…"> tag.
        $html = preg_replace('#<script[^>]*\bsrc\s*=\s*["\']' . preg_quote($from, '#') . '["\'][^>]*>\s*</script>#i', '', $html);
        $html = preg_replace('#<link[^>]*\bhref\s*=\s*["\']' . preg_quote($from, '#') . '["\'][^>]*>#i', '', $html);
        continue;
    }
    $html = str_replace('"' . $from . '"', '"' . $to . '"', $html);
    $html = str_replace("'" . $from . "'", "'" . $to . "'", $html);
}

// CDN → vendored.
$html = str_replace('https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js', 'js/vendor/bootstrap.bundle.min.js', $html);
$html = preg_replace('#<link[^>]*bootstrap-icons[^>]*>#i', '<link rel="stylesheet" href="assets/icons/bootstrap-icons.css">', $html);

// Google Fonts → self-hosted (CSP font-src 'self' + offline). Drop preconnects + the CSS.
$html = preg_replace('#<link[^>]*fonts\.(googleapis|gstatic)\.com[^>]*>#i', '', $html);
$html = str_replace('</title>',
    "</title>\n    <link rel=\"stylesheet\" href=\"assets/app-fonts.css\">"
  . "\n    <link rel=\"stylesheet\" href=\"assets/mobile-shell.css\">", $html);

// Mobile-only chrome the desktop template has no reason to carry: the boot cover (shown
// with no network until the first screen is up) and the offline banner.
$html = preg_replace('#(<body[^>]*>)#i',
    "$1\n<div id=\"wn-boot\"><div class=\"spinner-border text-primary\" role=\"status\"><span class=\"visually-hidden\">Loading…</span></div></div>\n"
  . "<div id=\"wn-offline\">You're offline — showing what we last saw.</div>", $html, 1);

// The app's own images (brand tile/marks the template references as ../assets/img/X)
// resolve to the bundle's assets/img/. Generic — any app, any tile filename.
$html = preg_replace('#(["\'])\.\./assets/img/#', '$1assets/img/', $html);

// Any leftover cross-repo branding path (real logos are hydrated at runtime anyway).
$html = str_replace('../../admin/branding/', 'assets/img/', $html);

// ── 3. Make the shell CSP-safe with the same splitter the views use ───────────
// Inline handlers → data-act; inline <script> hoisted out; and every <script src>
// collected into deps. The chrome now contains no executable INLINE code (CSP), but the
// vendored chrome scripts (bootstrap, sidebar, color-mode, notifications, …) must be
// RE-EMITTED below — they are the code that runs the dropdowns, the offcanvas, the
// colour-mode toggle, the sidebar collapse and the notification bell. Dropping them (the
// first version of this script did) leaves the whole topnav dead.
$split = wn_split_view($html);
$shellHtml = $split['markup'];
$shellJs   = $split['js'];
$shellDeps = $split['deps'];   // vendored js/vendor/*.js in document order (bootstrap first)

foreach ($split['unmapped'] as $u) {
    fwrite(STDERR, "build_shell: unmapped handler in template.php — $u\n");
}
if ($split['unmapped']) {
    fwrite(STDERR, "Fix these in views/template.php (or its snippets) — a chrome control that can't ship.\n");
    exit(1);
}

// The logout form posts action=logout to the page; on a device that goes nowhere.
// Turn it into a dispatchable button handled by shell.js → WnApi.logout().
$shellHtml = preg_replace(
    '#<form[^>]*>\s*<input[^>]*name=["\']action["\'][^>]*value=["\']logout["\'][^>]*>\s*<button([^>]*)>(.*?)</button>\s*</form>#is',
    '<button$1 data-on="click" data-act="wnLogout" data-args="[]">$2</button>',
    $shellHtml
);

// ── 4. Inject CSP + the mobile boot/nav layer ─────────────────────────────────
// script-src 'self' is the load-bearing rule (no inline/remote code — Apple 2.5.2). The
// app's own origin is allowed for connect/img/media so the bundle can reach its API and
// render user media; it comes from --app-origin (the same domain env.js uses).
$o = $appOrigin !== '' ? ' ' . $appOrigin : '';
$csp = '<meta http-equiv="Content-Security-Policy" content="'
     . "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
     . "img-src 'self'$o data: blob:; media-src 'self'$o blob:; "
     . "font-src 'self'; connect-src 'self'$o; form-action 'none'; base-uri 'none'"
     . '">';
$shellHtml = preg_replace('/<head>/i', "<head>\n    " . $csp, $shellHtml, 1);

// The chrome's inline handlers were rewritten to data-act (e.g. the feedback tab's
// submitFeedbackTab). Those functions are declared in the hoisted JS but live inside the
// _shell IIFE, so they must be REGISTERED with the dispatcher or a click finds no handler
// ("no handler submitFeedbackTab in this build"). Register every top-level function the
// shell declares, plus wnLogout (which we synthesize for the rewritten logout form).
if (preg_match_all('/^\s*(?:function\s+|(?:window|var|let|const)\s*\.?\s*)([A-Za-z_$][\w$]*)\s*(?:=\s*(?:async\s+)?function|\()/m', $shellJs, $fm)) {
    $shellFns = array_values(array_unique($fm[1]));
} else {
    $shellFns = [];
}
$reg = ["    wnLogout: function(){ if (window.WnApi) WnApi.logout(); }"];
foreach ($shellFns as $fn) {
    if ($fn === 'wnLogout') continue;
    $reg[] = "    $fn: typeof $fn === 'function' ? $fn : null";
}

// The hoisted chrome JS. Runs IMMEDIATELY as an IIFE (not WnScreens.define, which defers
// until a screen renders) — the chrome DOM is present from the start and its scripts, like
// the feedback tab's setup, are meant to run once at load. It registers its handlers with
// the dispatcher under the reserved key "_shell" so a click on data-act="submitFeedbackTab"
// resolves.
file_put_contents($appRoot . '/m/js/shell-inline.js',
    "/* GENERATED from views/template.php by scripts/build_shell.php — do not edit.\n"
  . "   The chrome's own behavior, hoisted so nothing executes inline (CSP). */\n"
  . "(function () {\n\n" . trim($shellJs) . "\n\n"
  . "  if (window.WnDispatch) WnDispatch.register('_shell', {\n"
  . implode(",\n", $reg) . "\n"
  . "  });\n})();\n");

// The per-screen behavior files (generated by build_mobile.php), loaded from the manifest
// so the list tracks view_map. Each registers itself with WnScreens/WnDispatch.
$screenTags = '';
$manifestFile = $appRoot . '/m/manifest.json';
if (is_readable($manifestFile)) {
    $manifest = json_decode(file_get_contents($manifestFile), true) ?: [];
    foreach (array_keys($manifest['screens'] ?? []) as $page) {
        if (is_readable($appRoot . '/m/js/screens/' . $page . '.js')) {
            $screenTags .= "<script src=\"js/screens/$page.js\"></script>\n";
        }
    }
} else {
    fwrite(STDERR, "build_shell: m/manifest.json missing — run build_mobile.php first.\n");
    exit(1);
}

// The vendored chrome scripts template.php loaded (bootstrap FIRST, then sidebar,
// color-mode, notifications, modal, toast, page-nav, bg-canvas, celebrate, error-reporter),
// re-emitted in the order the template had them. Skipped: cordova.js (ghost) and anything
// already in our boot list, so nothing loads twice.
$mine = ['js/cordova-boot.js','js/env.js','js/platform.js','js/api.js','js/report.js','js/dispatch.js',
         'js/store.js','js/screens.js','js/shell-inline.js','js/login.js','js/router.js','js/shell.js'];
$chromeTags = '';
foreach ($shellDeps as $src) {
    if ($src === 'cordova.js' || in_array($src, $mine, true)) continue;
    $chromeTags .= "<script src=\"" . htmlspecialchars($src, ENT_QUOTES) . "\"></script>\n";
}

// Order matters. env/platform/api first (Platform + Bearer apiPost that the chrome and
// router need) → the vendored chrome scripts (bootstrap before the widgets that use it) →
// the nav layer + per-screen files + hoisted chrome inline → shell.js (hydrate + boot).
$boot = <<<HTML
<script src="js/cordova-boot.js"></script>
<script src="js/env.js"></script>
<script src="js/platform.js"></script>
<script src="js/api.js"></script>
<script src="js/report.js"></script>
$chromeTags<script src="js/dispatch.js"></script>
<script src="js/store.js"></script>
<script src="js/screens.js"></script>
$screenTags<script src="js/shell-inline.js"></script>
<script src="js/login.js"></script>
<script src="js/router.js"></script>
<script src="js/shell.js"></script>
</body>
HTML;
$shellHtml = preg_replace('#</body>#i', $boot, $shellHtml, 1);

file_put_contents($appRoot . '/m/index.html', $shellHtml);
echo "built m/index.html from views/template.php (" . strlen($shellHtml) . " bytes; "
   . substr_count($shellHtml, '<script') . " script tags, "
   . substr_count($shellJs, "\n") . " lines of chrome JS hoisted)\n";

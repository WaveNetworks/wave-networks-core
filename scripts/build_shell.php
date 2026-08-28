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
// The app's definition.php declares CHILD_APP_NAME (+ version) with no DB or bootstrap — load
// it so the chrome's brand name, <title> and sidebar brand render the REAL app name, not the
// neutral 'App' placeholder. (Conventionally just define()s; guarded by is_file.)
if (is_file($appRoot . '/include/definition.php')) {
    include_once($appRoot . '/include/definition.php');
}
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
// Branding for the shell. This CANNOT be stubbed empty: template.php gates its brand
// markup on these values, so empty ones silently take every fallback branch — a generic
// bi-app-indicator glyph instead of the logo, and no dashboard watermark at all — and the
// app ships chrome the web app does not have. A shell that does not match the web app is
// broken, not degraded, so the app declares its bundle art in app-model.json and this
// build FAILS without it rather than papering over the gap.
//
// The filenames resolve against the app's assets/img/. template.php emits
// ../../admin/branding/<file>, the rewrite below turns that prefix into assets/img/, and
// release-mobile copies assets/img/* into the bundle — so the SAME branch renders in both
// shells, from one source of markup.
$wnBrand = ['logo_path' => '', 'logo_dark_path' => '', 'favicon_path' => ''];
$wnModelFile = $appRoot . '/app-model.json';
if (is_readable($wnModelFile)) {
    $wnModel = json_decode(file_get_contents($wnModelFile), true);
    foreach (array_keys($wnBrand) as $k) {
        if (!empty($wnModel['brand'][$k])) $wnBrand[$k] = $wnModel['brand'][$k];
    }
}
$wnMissing = [];
foreach ($wnBrand as $k => $v) {
    if ($v === '') { $wnMissing[] = "brand.$k not declared"; continue; }
    if (!is_readable($appRoot . '/assets/img/' . $v)) $wnMissing[] = "assets/img/$v missing (brand.$k)";
}
if ($wnMissing) {
    // Unconditional. There is no useful "partial" outcome here: a bundle that cannot
    // render the web app's brand markup is not a degraded build, it is a wrong one, and
    // shipping it is how the app came to show a generic glyph next to a web app showing
    // the real mark. Fail and say exactly what is missing.
    fwrite(STDERR, "build_shell FAILED — the shell cannot render the same brand markup as the web app.\n");
    foreach ($wnMissing as $m) fwrite(STDERR, "  - $m\n");
    fwrite(STDERR, "  Declare them in " . basename($wnModelFile) . " at the app root:\n");
    fwrite(STDERR, "    \"brand\": { \"logo_path\": \"<file>.png\", \"logo_dark_path\": \"<file>.png\", \"favicon_path\": \"<file>.png\" }\n");
    fwrite(STDERR, "  and put the files in assets/img/. Without them template.php takes its\n");
    fwrite(STDERR, "  fallback branches and the app silently diverges from the web app —\n");
    fwrite(STDERR, "  a generic glyph for the brand, and any branding-gated element missing.\n");
    exit(1);
}
if (!function_exists('get_branding')) { function get_branding(){ global $wnBrand; $n = defined('CHILD_APP_NAME') ? CHILD_APP_NAME : 'App'; return array_merge(['site_name'=>$n,'theme_color'=>'#666666'], $wnBrand); } }
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

// Edge-to-edge: Android 15 (API 35+) and iOS notches draw content UNDER the system bars.
// The device shell MUST declare viewport-fit=cover, or env(safe-area-inset-*) stays 0 and the
// safe-area padding in mobile-shell.scss never applies — the top nav gets clipped by the status
// bar. Only the built shell is rewritten; the desktop template keeps its own viewport.
$html = preg_replace_callback(
    '#(<meta\s+name=["\']viewport["\']\s+content=["\'])([^"\']*)(["\'])#i',
    function ($m) {
        return stripos($m[2], 'viewport-fit') !== false
            ? $m[0] : $m[1] . rtrim($m[2]) . ', viewport-fit=cover' . $m[3];
    },
    $html
);

// Google Fonts → self-hosted (CSP font-src 'self' + offline). Drop preconnects + the CSS.
$html = preg_replace('#<link[^>]*fonts\.(googleapis|gstatic)\.com[^>]*>#i', '', $html);
// app-fonts.css is only produced when the app ships self-hosted brand fonts:
// release-mobile.sh copies assets/fonts/app-fonts.css → m/assets/app-fonts.css inside a
// `compgen -G assets/fonts/*.woff2` guard. Apps without brand fonts (e.g. pwt) never get
// the file, so referencing it unconditionally is a guaranteed 404 on every shell load.
// Link it only when the app ships woff2s — the exact condition under which the CSS lands.
$fontLink = glob($appRoot . '/assets/fonts/*.woff2')
    ? "\n    <link rel=\"stylesheet\" href=\"assets/app-fonts.css\">" : '';
$html = str_replace('</title>',
    "</title>$fontLink"
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

// ── Bundle-local web app manifest ────────────────────────────────────────────
// The template points at the server's ../../admin/manifest.php. Two problems in a
// bundle: it is a cross-repo server path that does not exist offline, and its icons
// are the branding pwa_icon_*.png, which are generated server-side and can be wrong
// (pwt's were a blank white square — a valid PNG, HTTP 200, one unique colour).
//
// That matters more than a favicon: for install and home-screen purposes the MANIFEST
// icons win over <link rel="icon">, so a good tile in the head is still overridden by a
// blank icon in the manifest. Emit a manifest built from the tile the shell already
// declares, so the bundle is self-contained and the two agree.
if (preg_match('#<link\s+rel="icon"\s+href="([^"]+)"#i', $html, $iconM)) {
    $iconHref = $iconM[1];
    $ext      = strtolower(pathinfo(parse_url($iconHref, PHP_URL_PATH), PATHINFO_EXTENSION));
    $mime     = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'webp' ? 'image/webp' : 'image/png');

    // Declare the real pixel size when we can read it — a wrong "sizes" is worse than
    // none, since the installer picks by it, and "any" on a raster icon tells it
    // nothing. NOTE this script runs BEFORE release-mobile copies art into m/, so the
    // bundle copy usually does not exist yet; fall back to the source the copy comes
    // from ($appRoot/assets/img/...), which is the same bytes.
    $relPath  = preg_replace('/\?.*$/', '', $iconHref);
    $sizes    = 'any';
    if ($mime !== 'image/svg+xml') {
        foreach ([$appRoot . '/m/' . $relPath, $appRoot . '/' . $relPath] as $cand) {
            if (is_readable($cand) && ($d = @getimagesize($cand))) { $sizes = $d[0] . 'x' . $d[1]; break; }
        }
        if ($sizes === 'any') {
            fwrite(STDERR, "build_shell: WARNING could not size $relPath — manifest will say \"any\"\n");
        }
    }

    $b = function_exists('get_branding') ? get_branding() : [];
    $appName = $b['site_name'] ?? (defined('CHILD_APP_NAME') ? CHILD_APP_NAME : 'App');
    $manifest = [
        'name'             => $appName,
        'short_name'       => $appName,
        'start_url'        => './index.html',
        'scope'            => './',
        'display'          => 'standalone',
        'icons'            => [[
            'src'     => $iconHref,
            'sizes'   => $sizes,
            'type'    => $mime,
            'purpose' => 'any',
        ]],
    ];
    if (!empty($b['theme_color']))      { $manifest['theme_color'] = $b['theme_color']; }
    if (!empty($b['background_color'])) { $manifest['background_color'] = $b['background_color']; }

    // NOT manifest.json — that name is already the bundle's per-screen manifest.
    file_put_contents($appRoot . '/m/manifest.webmanifest',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $html = preg_replace('#<link\s+rel="manifest"\s+href="[^"]*"\s*/?>#i',
        '<link rel="manifest" href="manifest.webmanifest">', $html);
    fwrite(STDERR, "build_shell: manifest.webmanifest -> $iconHref ($sizes)\n");
} else {
    // No icon in the head means the shell would fall back to the site root favicon —
    // on a domain shared with a marketing site that is the wrong logo entirely.
    fwrite(STDERR, "build_shell: WARNING no <link rel=\"icon\"> in the shell; "
                 . "add one to views/template.php (bundle-local, e.g. ../assets/img/<app>-tile.png)\n");
}

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
$mine = ['js/cordova-boot.js','js/env.js','js/platform.js','js/api.js','js/report.js','js/live-update.js',
         'js/dispatch.js','js/store.js','js/screens.js','js/shell-inline.js','js/login.js','js/router.js',
         'js/shell.js'];
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
<script src="js/live-update.js"></script>
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

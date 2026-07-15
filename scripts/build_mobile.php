<?php
/**
 * build_mobile.php — hoist the views' behavior into the mobile bundle (spec 05).
 *
 *   php scripts/build_mobile.php [--version=1.0.3]
 *
 * For every mobile screen in include/mobile/view_map.php it runs the SAME splitter
 * the fragment endpoint runs, and writes the JS half to m/js/screens/<page>.js.
 * That JS is the code the app ships with. The markup half is never written here —
 * it arrives at runtime, from the server, rendered for the actual user.
 *
 * The build FAILS on any handler the splitter cannot map. A dropped handler is a
 * button that looks fine and does nothing; that must never ship quietly.
 *
 * No database is needed: the views' inline JS is static (887 lines across 13 views,
 * exactly one PHP interpolation), so it hoists straight from source.
 *
 * SCOPING AND TIMING — the two things hoisting must preserve, and the two things
 * that are easy to get silently wrong:
 *
 *   scope. Each screen's JS gets its own function scope, then registers its
 *   top-level functions with the dispatcher. Desktop only ever has one view's
 *   script live at a time, so per-screen scope is the faithful translation — and it
 *   is load-bearing: ledger.php and gallery.php both declare esc(), which would
 *   collide if the bundle dumped every screen into one global scope.
 *
 *   timing. A view's script runs AFTER that view's markup, because that is what a
 *   page load gives it. So the generated file does not execute at load — it hands
 *   its body to WnScreens, and the router runs it the moment that screen's markup
 *   lands. Run them at load instead and ledger.js reaches for #ledgerNext, gets
 *   null, and throws before the app has drawn a pixel.
 */

// This script lives in core but operates on a CHILD APP. $coreRoot is where it lives (the
// splitter comes from here); $appRoot is the child app it builds (view_map, views, m/ come
// from there). The child's release-mobile.sh passes --app-root; default to cwd.
$coreRoot = dirname(__DIR__);
require_once($coreRoot . '/include/mobile/split_view.php');

$version = '0.1.0';
$appRoot = getcwd();
foreach ($argv as $a) {
    if (strpos($a, '--version=') === 0)  $version = substr($a, 10);
    if (strpos($a, '--app-root=') === 0) $appRoot = rtrim(substr($a, 11), '/');
}
if (!is_dir($appRoot . '/views') || !is_file($appRoot . '/include/mobile/view_map.php')) {
    fwrite(STDERR, "build_mobile: --app-root=$appRoot is not a child app (need views/ + include/mobile/view_map.php)\n");
    exit(1);
}

$views  = include($appRoot . '/include/mobile/view_map.php');
$outDir = $appRoot . '/m/js/screens';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "cannot create $outDir\n");
    exit(1);
}

// ── Pass 1: split every mobile screen ────────────────────────────────────────
$screens  = [];
$unmapped = [];
$handlers = [];   // handler name → pages whose markup calls it

foreach ($views as $page => $meta) {
    if (empty($meta['mobile'])) continue;

    $file = $appRoot . '/views/' . $meta['file'];
    if (!is_readable($file)) {
        fwrite(STDERR, "missing view: $file\n");
        exit(1);
    }

    // Lenient: the source still carries a PHP echo tag where a value will be.
    $split = wn_split_view(file_get_contents($file), true);

    foreach ($split['unmapped'] as $u) {
        $unmapped[] = ['page' => $page, 'what' => $u];
    }

    // Every function the rewritten markup will ask the dispatcher for.
    if (preg_match_all('/\bdata-act="([^"]+)"/', $split['markup'], $m)) {
        foreach (array_unique($m[1]) as $fn) $handlers[$fn][] = $page;
    }

    // External scripts the view pulls in (chat → viv-chat.js, gallery → viv-upload.js,
    // circle → qrcode, profile → tabdrop). The splitter strips them from the fragment, so
    // the router must load them from the vendored copy when this screen renders — else the
    // screen's markup is there but dead. We keep only the basename → js/vendor/<name>.
    $deps = [];
    foreach ($split['deps'] as $src) {
        $base = basename($src);
        if ($base !== '' && $base !== 'cordova.js') $deps[] = 'js/vendor/' . $base;
    }

    $screens[$page] = [
        'meta' => $meta,
        'file' => $file,
        'js'   => trim($split['js']),
        'deps' => array_values(array_unique($deps)),
        'fns'  => [],
    ];
    // A PHP tag inside a <script> cannot survive hoisting: the bundle is built ONCE,
    // not rendered per user, so there is nothing to interpolate — the tag would ship
    // as a JavaScript syntax error and take the whole screen down. The view must pass
    // server values through data-* attributes instead. This is the lint spec 05 promised.
    if (preg_match('/<\?(=|php)/', $screens[$page]['js'], $m)) {
        fwrite(STDERR, "BUILD FAILED — views/{$meta['file']} has PHP inside a <script> block.\n");
        fwrite(STDERR, "  The bundle is built once, not rendered per user, so `{$m[0]} … ?" . ">` would\n");
        fwrite(STDERR, "  ship verbatim and break the screen. Put the value in a data-* attribute\n");
        fwrite(STDERR, "  and read it from the DOM (see views/ledger.php's #vivLedgerData).\n");
        exit(1);
    }

    $screens[$page]['fns'] = wn_declared_functions($screens[$page]['js']);
}

/**
 * Every function a screen's JS declares, in any of the three forms the views use:
 * a declaration, a window assignment (dashboard's handlers), or a var binding.
 * Missing a form here would let a real collision through unnoticed.
 */
function wn_declared_functions($js)
{
    if (trim($js) === '') return [];
    $names = [];
    $patterns = [
        '/^\s*function\s+([A-Za-z_$][\w$]*)\s*\(/m',
        '/^\s*window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?function\b/m',
        '/^\s*(?:var|let|const)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?function\b/m',
    ];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $js, $m)) $names = array_merge($names, $m[1]);
    }
    return array_values(array_unique($names));
}

// ── Fail loud on handlers the splitter cannot map ────────────────────────────
// These are controls that would render but never fire. Every fix goes in the
// view and stays desktop-compatible, because it is the same file.
if ($unmapped) {
    fwrite(STDERR, "\nBUILD FAILED — " . count($unmapped) . " handler(s) the splitter cannot map.\n");
    fwrite(STDERR, "Each is a control that would render but never fire.\n");
    fwrite(STDERR, "  onclick=\"fn('a', 1)\" is mappable. An expression is not — move it into a\n");
    fwrite(STDERR, "  named function in that view's <script>, then call the function.\n\n");
    foreach ($unmapped as $u) {
        fwrite(STDERR, sprintf("  %-14s %s\n", $u['page'], $u['what']));
    }
    fwrite(STDERR, "\n");
    exit(1);
}

// ── Fail loud on an AMBIGUOUS handler ────────────────────────────────────────
// Two screens declaring the same *handler* name is a genuine bug: the dispatcher
// could not know which one a button meant. (Two screens declaring the same private
// helper — esc() — is fine, and stays fine, because each screen has its own scope.)
foreach ($handlers as $fn => $callers) {
    $definers = [];
    foreach ($screens as $page => $s) {
        if (in_array($fn, $s['fns'], true)) $definers[] = $page;
    }
    if (count($definers) > 1) {
        fwrite(STDERR, "BUILD FAILED — handler $fn() is declared by more than one screen: "
            . implode(', ', $definers) . "\n  A button calling it would be ambiguous. Give one of them a distinct name.\n");
        exit(1);
    }
}

// ── Fail loud on a tab icon that does not exist ──────────────────────────────
// A made-up Bootstrap Icons name renders as nothing at all — the tab just has no icon,
// and the app looks broken in a way no error reports. `bi-scale` shipped exactly like
// that. Check the names against the icon font we vendor, when it is installed.
$iconJson = $appRoot . '/node_modules/bootstrap-icons/font/bootstrap-icons.json';
if (is_readable($iconJson)) {
    $known = json_decode(file_get_contents($iconJson), true) ?: [];
    foreach ($views as $page => $meta) {
        if (empty($meta['mobile']) || empty($meta['icon'])) continue;
        if (!array_key_exists($meta['icon'], $known)) {
            fwrite(STDERR, "BUILD FAILED — tab icon 'bi-{$meta['icon']}' ($page) is not a Bootstrap Icon.\n"
                . "  It would render blank. Pick a real name from node_modules/bootstrap-icons.\n");
            exit(1);
        }
    }
}

// ── Pass 2: write the bundle ─────────────────────────────────────────────────
$manifest  = ['version' => $version, 'screens' => []];
$loadOrder = [];

foreach ($screens as $page => $s) {
    $jsHash = '';

    if ($s['js'] !== '') {
        $exports = [];
        foreach ($s['fns'] as $fn) {
            $exports[] = "    $fn: typeof $fn === 'function' ? $fn : null";
        }

        // The body does NOT run at load. It runs when this screen's markup lands —
        // which is when the view's script expects to run, because that is what a page
        // load gives it. Hoisting has to preserve the moment, not just the code.
        $out  = "/* GENERATED by scripts/build_mobile.php from views/{$s['meta']['file']} — do not edit.\n"
              . "   The BEHAVIOR half of the screen: it ships inside the binary, so changing it\n"
              . "   requires a store release. The markup half arrives at runtime, from the server. */\n"
              . "WnScreens.define(" . json_encode($page) . ", function () {\n\n"
              . $s['js'] . "\n\n"
              . "  WnDispatch.register(" . json_encode($page) . ", {\n"
              . implode(",\n", $exports) . "\n"
              . "  });\n"
              . "});\n";

        file_put_contents("$outDir/$page.js", $out);
        $jsHash = wn_screen_js_hash($s['file']);
        $loadOrder[] = $page;
    }

    $manifest['screens'][$page] = [
        'view_hash' => wn_view_hash($s['file']),
        'js_hash'   => $jsHash,
        'deps'      => $s['deps'],
        'tab'       => $s['meta']['tab'] ?? null,
        'menu'      => $s['meta']['menu'] ?? null,
        'icon'      => $s['meta']['icon'] ?? null,
    ];
}

// Screens with no behavior of their own still need a file, so the shell can load
// one script per screen without special-casing.
foreach ($manifest['screens'] as $page => $m) {
    if ($m['js_hash'] === '' && !file_exists("$outDir/$page.js")) {
        file_put_contents("$outDir/$page.js",
            "/* GENERATED — views/" . $screens[$page]['meta']['file'] . " has no behavior of its own. */\n"
            . "WnScreens.define(" . json_encode($page) . ", function () {});\n");
    }
}

$manifest['load_order'] = array_keys($manifest['screens']);
file_put_contents(
    $appRoot . '/m/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

// The registry the dispatcher will be asked for, and where each name comes from.
printf("built %d screens (%d carry behavior) → m/js/screens/\n", count($manifest['screens']), count($loadOrder));
foreach ($handlers as $fn => $callers) {
    $from = 'chrome/global';
    foreach ($screens as $page => $s) {
        if (in_array($fn, $s['fns'], true)) { $from = "screens/$page.js"; break; }
    }
    printf("  handler %-18s ← %-22s used by: %s\n", $fn . '()', $from, implode(', ', array_unique($callers)));
}
printf("manifest v%s\n", $version);

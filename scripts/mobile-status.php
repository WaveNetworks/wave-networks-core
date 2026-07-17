<?php
/**
 * mobile-status.php — did my UI changes reach the phone yet? (child-app spec 05)
 *
 *   php scripts/mobile-status.php [--app-root=.] [--shipped=<manifest.json>] [--json]
 *
 * The wet/dry split means a UI change reaches mobile in one of two ways, and which one
 * decides whether you have to cut a store build:
 *
 *   MARKUP  (view HTML)  travels WET — fetched at runtime. It is live on the phone the
 *                        moment the web deploy lands. No build. No wait.
 *   BEHAVIOR (view JS)   travels DRY — hoisted into the installed binary at build time.
 *                        A change to it is NOT on any installed app until you release.
 *
 * The runtime deviation gauge already enforces this (an old binary refuses a fragment whose
 * dry hash it does not carry), but only reactively — after a user opens the drifted screen.
 * This script answers the same question up front, from source, before anyone hits it:
 * for every mobile screen it compares the CURRENT view against the SHIPPED bundle's manifest
 * (the committed record of what the store binary was built from) and sorts each screen into:
 *
 *   BUILD DUE   dry JS changed  → not on installed apps until you run release-mobile.sh
 *   live (wet)  only markup changed → already on mobile once the web deploy is live
 *   new         screen not in the shipped bundle at all → needs a build
 *   removed     in the bundle, gone from the app → needs a build
 *   unchanged   identical to what shipped
 *
 * Hashes are computed with wn_view_hash()/wn_screen_js_hash() — the SAME functions the build
 * and the fragment endpoint use — so this can never disagree with the live gauge.
 *
 * Exit status: 0 = nothing pending a build; 10 = at least one screen is BUILD DUE / new /
 * removed. (10, not 1, so CI can branch on "store build due" without confusing it with a
 * script error.) --json prints a machine-readable report instead of the table.
 */

$coreRoot = dirname(__DIR__);
require_once($coreRoot . '/include/mobile/split_view.php');

$appRoot  = getcwd();
$shipped  = null;
$asJson   = false;
foreach ($argv as $a) {
    if (strpos($a, '--app-root=') === 0) $appRoot = rtrim(substr($a, 11), '/');
    if (strpos($a, '--shipped=')  === 0) $shipped = substr($a, 10);
    if ($a === '--json')                 $asJson  = true;
}

$viewMap = $appRoot . '/include/mobile/view_map.php';
if (!is_dir($appRoot . '/views') || !is_file($viewMap)) {
    fwrite(STDERR, "mobile-status: --app-root=$appRoot is not a child app (need views/ + include/mobile/view_map.php)\n");
    exit(1);
}

// The shipped bundle's manifest is the record of what the store binary was built from. By
// convention the release pipeline syncs m/ into ../<slug>-cordova/www/ and commits it, so
// that manifest is the durable baseline. Let --shipped override for other layouts.
if ($shipped === null) {
    $slug = basename($appRoot);
    $shipped = $appRoot . '/../' . $slug . '-cordova/www/manifest.json';
}
if (!is_readable($shipped)) {
    fwrite(STDERR, "mobile-status: no shipped manifest at $shipped\n");
    fwrite(STDERR, "  (pass --shipped=<path>, or cut a first release with release-mobile.sh — nothing has shipped yet)\n");
    exit(1);
}
$shippedManifest = json_decode(file_get_contents($shipped), true);
$shippedScreens  = $shippedManifest['screens'] ?? [];
$shippedVersion  = $shippedManifest['version']  ?? '?';

// Current state, straight from source — mirror build_mobile.php's screen selection exactly.
$views   = include($viewMap);
$current = [];
foreach ($views as $page => $meta) {
    if (empty($meta['mobile'])) continue;
    $file = $appRoot . '/views/' . $meta['file'];
    if (!is_readable($file)) { fwrite(STDERR, "missing view: $file\n"); exit(1); }
    $current[$page] = [
        'view_hash' => wn_view_hash($file),
        'js_hash'   => wn_screen_js_hash($file),
    ];
}

// Sort every screen into exactly one bucket.
$buildDue = $wet = $same = $new = [];
foreach ($current as $page => $c) {
    if (!isset($shippedScreens[$page])) { $new[] = $page; continue; }
    $s = $shippedScreens[$page];
    if (($c['js_hash'] ?? '') !== ($s['js_hash'] ?? ''))          $buildDue[] = $page;
    elseif (($c['view_hash'] ?? '') !== ($s['view_hash'] ?? ''))  $wet[]      = $page;
    else                                                          $same[]     = $page;
}
$removed = [];
foreach ($shippedScreens as $page => $_) {
    if (!isset($current[$page])) $removed[] = $page;
}
sort($buildDue); sort($wet); sort($same); sort($new); sort($removed);

$needsBuild = $buildDue || $new || $removed;

if ($asJson) {
    echo json_encode([
        'shipped_version' => $shippedVersion,
        'needs_build'     => $needsBuild,
        'build_due'       => $buildDue,
        'live_wet'        => $wet,
        'new'             => $new,
        'removed'         => $removed,
        'unchanged'       => $same,
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
    exit($needsBuild ? 10 : 0);
}

$row = function ($label, $list, $note) {
    printf("  %-11s %s\n", $label, $list ? implode(', ', $list) : '(none)');
    if ($list && $note) printf("  %-11s   ↳ %s\n", '', $note);
};

echo "Shipped store bundle: v$shippedVersion  ($shipped)\n\n";
$row('BUILD DUE', $buildDue, 'dry JS changed — run release-mobile.sh to reach installed apps');
$row('new',       $new,      'screen not in the shipped bundle — needs a build');
$row('removed',   $removed,  'in the bundle, gone from the app — needs a build');
$row('live (wet)', $wet,     'markup-only change — already on mobile once the web deploy is live');
printf("  %-11s %d screen%s\n", 'unchanged', count($same), count($same) === 1 ? '' : 's');
echo "\n";
echo $needsBuild
    ? "→ STORE BUILD DUE. Run: ADMIN_ROOT=$coreRoot bash scripts/release-mobile.sh <version> --push\n"
    : "→ Current code is fully reflected in the shipped mobile bundle. No build needed.\n";

exit($needsBuild ? 10 : 0);

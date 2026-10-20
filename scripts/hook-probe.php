<?php
/**
 * scripts/hook-probe.php — the app ↔ sub-admin hook contract (include/common/hookFunctions.php).
 *
 * Builds a throwaway webroot with two fake sub-admins and checks: a manifest's hooks file is
 * loaded once and its providers answer; a missing hook answers available=false; a provider's
 * 'error' or exception becomes ok=false without throwing; a hooks path that leaves its mount is
 * refused. DB-free.
 *
 * Usage: php scripts/hook-probe.php    Exit 0 clean, 1 on a finding.
 */
require __DIR__ . '/../include/common/hookFunctions.php';

$fail = 0;
function hp_check($label, $got, $want) {
    global $fail;
    if ($got === $want) { echo "ok   $label\n"; return; }
    $fail++;
    echo "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

$root = sys_get_temp_dir() . '/hook-probe-' . getmypid();
@mkdir("$root/demo/include", 0700, true);
@mkdir("$root/evil", 0700, true);
file_put_contents("$root/demo/include/hooks.php", '<?php
$GLOBALS["hp_loads"] = ($GLOBALS["hp_loads"] ?? 0) + 1;
wn_hook_provide("demo.echo", fn($p) => ["echo" => $p["x"] ?? null], "demo");
wn_hook_provide("demo.refuse", fn($p) => ["error" => "no thanks"], "demo");
wn_hook_provide("demo.boom", function ($p) { throw new RuntimeException("boom"); }, "demo");
');
file_put_contents("$root/outside.php", '<?php $GLOBALS["hp_escaped"] = true;');
$registry = [
    ['mount' => 'demo', 'hooks' => 'include/hooks.php'],
    ['mount' => 'evil', 'hooks' => '../outside.php'],
    ['mount' => 'plain'],
];

hp_check('loads the mounted hooks file', wn_hooks_load($registry, $root), ['demo']);
hp_check('loads it only once', wn_hooks_load($registry, $root), []);
hp_check('hooks file ran once', $GLOBALS['hp_loads'] ?? 0, 1);
hp_check('path leaving its mount refused', $GLOBALS['hp_escaped'] ?? false, false);
hp_check('available', wn_hook_available('demo.echo'), true);
hp_check('names', array_keys(wn_hook_names()), ['demo.boom', 'demo.echo', 'demo.refuse']);

$r = wn_hook('demo.echo', ['x' => 7]);
hp_check('call ok', [$r['ok'], $r['available'], $r['result'], $r['error']], [true, true, ['echo' => 7], null]);
$r = wn_hook('demo.refuse');
hp_check('provider error = not ok', [$r['ok'], $r['available'], $r['error']], [false, true, 'no thanks']);
$r = wn_hook('demo.boom');
hp_check('exception = not ok, no throw', [$r['ok'], $r['available'], $r['error']], [false, true, 'boom']);
$r = wn_hook('nobody.home');
hp_check('missing hook', [$r['ok'], $r['available'], $r['result']], [false, false, null]);

array_map('unlink', ["$root/demo/include/hooks.php", "$root/outside.php"]);
@rmdir("$root/demo/include"); @rmdir("$root/demo"); @rmdir("$root/evil"); @rmdir($root);
echo $fail ? "$fail finding(s)\n" : "hook contract holds\n";
exit($fail ? 1 : 0);

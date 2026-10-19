<?php
/**
 * scripts/p3sig-keyserver-strip.php — migration step 3 of p3sig key-server mode (doc §4.4):
 * empty, in config.php, every value this deployment's bundle now carries; or put them back.
 *
 *   php admin/scripts/p3sig-keyserver-strip.php [--child public_html/<app>/config/config.php …]
 *   php admin/scripts/p3sig-keyserver-strip.php --restore [--child …]      (rollback)
 *
 * Strip only proceeds when the bundle opens HERE (through the key server) and holds, for
 * every value it empties, exactly what config.php holds now. Each file is edited at the
 * token level (only the literal after `$var =`, or after 'pass' => in shard arrays),
 * linted, re-read in an isolated scope and checked: targets empty, every other variable
 * unchanged. Any failure leaves the file as it was. No plaintext copy is kept: --restore
 * writes the values back from the bundle. Prints counts, never values (names with --names).
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$restore = false; $names = false; $children = [];
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--restore') { $restore = true; }
    elseif ($argv[$i] === '--names') { $names = true; }
    elseif ($argv[$i] === '--child') { $children[] = $argv[++$i] ?? ''; }
    else { fwrite(STDERR, "unknown argument {$argv[$i]}\n"); exit(1); }
}
function strip_fail($m) { fwrite(STDERR, "error: $m (nothing changed)\n"); exit(1); }

$adminCfg = realpath(__DIR__ . '/../config/config.php');
if (!$adminCfg) { strip_fail('no admin config/config.php'); }
// The admin config defines WN_P3SIG_CONFIG; load it the way bootstrap does, then the bundle.
(function () use ($adminCfg) { include $adminCfg; })();
require_once __DIR__ . '/../include/common/runtimeSecretFunctions.php';
if (!wn_p3sig_keyserver_mode()) { strip_fail('this deployment is not in p3sig key-server mode'); }
$bundle = wn_p3sig_cache_values();
if (!$bundle) { strip_fail('the bundle did not open here (key server unreachable, or this address is not allowed)'); }

function strip_read($file) {
    // @: re-reading repeats the config's define()s, which only warns.
    return (function () use ($file) { ob_start(); @include $file; ob_end_clean(); unset($file); return get_defined_vars(); })();
}
function strip_suffix($k) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', (string) $k)); }

/** var name => bundle name, for the admin config and for a child config. */
function strip_targets_admin(array $vars) {
    $t = [];
    foreach (WN_RUNTIME_SECRET_GLOBALS + WN_ROOT_SECRET_GLOBALS as $var => $name) { $t['$' . $var] = $name; }
    foreach (WN_ROOT_SECRET_RINGS as $var => $name) { $t['$' . $var] = $name; }   // rings: whole array
    foreach (($vars['shardConfigs'] ?? []) as $k => $sc) { $t["shardConfigs[$k].pass"] = WN_ROOT_SHARD_PREFIX . strip_suffix($k); }
    return $t;
}
function strip_targets_child(array $vars) {
    $t = ['$child_db_user' => 'CHILD_DB_USER', '$child_db_pass' => 'CHILD_DB_PASS'];
    foreach (($vars['childShardConfigs'] ?? []) as $k => $sc) { $t["childShardConfigs[$k].pass"] = 'CHILD_' . WN_ROOT_SHARD_PREFIX . strip_suffix($k); }
    return $t;
}
/** The current value of a target in a config's variables. */
function strip_value(array $vars, $target) {
    if (preg_match('/^(\w+)\[(.+)\]\.pass$/', $target, $m)) { return $vars[$m[1]][$m[2]]['pass'] ?? null; }
    $v = $vars[substr($target, 1)] ?? null;
    return is_array($v) ? json_encode($v) : ($v === null ? null : (string) $v);
}

/**
 * Rewrite literals in a config file. $set: target => new PHP literal source. Plain targets
 * replace the expression after `$var =` up to `;`; shard targets replace the literal after
 * 'pass' => inside the named array's entry. Returns the new source.
 */
function strip_rewrite($src, array $set) {
    $tok = token_get_all($src);
    $n = count($tok);
    $out = '';
    $txt = function ($t) { return is_array($t) ? $t[1] : $t; };
    $done = [];
    $arrayVar = null; $entryKey = null; $depth = 0; $entryDepth = -1;
    for ($i = 0; $i < $n; $i++) {
        $t = $tok[$i];
        // Track `$shardConfigs = [ 'shard1' => [ … 'pass' => '…' … ], … ];`
        if (is_array($t) && $t[0] === T_VARIABLE) {
            $name = substr($t[1], 1);
            $j = $i + 1; while ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) { $j++; }
            if ($j < $n && $tok[$j] === '=' && in_array($name, ['shardConfigs', 'childShardConfigs'], true)) {
                $arrayVar = $name; $depth = 0;
            } elseif ($j < $n && $tok[$j] === '=' && isset($set['$' . $name]) && !isset($done['$' . $name])) {
                // $var = <expr>;  → $var = <new>;
                $out .= $t[1];
                for ($k = $i + 1; $k <= $j; $k++) { $out .= $txt($tok[$k]); }
                $k = $j + 1; $lead = '';
                while ($k < $n && is_array($tok[$k]) && $tok[$k][0] === T_WHITESPACE) { $lead .= $tok[$k][1]; $k++; }
                $level = 0;
                while ($k < $n && !($level === 0 && $tok[$k] === ';')) {
                    if (in_array($tok[$k], ['[', '(', '{'], true)) { $level++; }
                    if (in_array($tok[$k], [']', ')', '}'], true)) { $level--; }
                    $k++;
                }
                $out .= ($lead === '' ? ' ' : $lead) . $set['$' . $name];
                $done['$' . $name] = true;
                $i = $k - 1;
                continue;
            }
        }
        if ($arrayVar !== null) {
            if ($t === '[' || $t === '(') { $depth++; }
            if ($t === ']' || $t === ')') { $depth--; if ($depth === 1) { $entryKey = null; } }
            if ($t === ';' && $depth === 0) { $arrayVar = null; }
            if ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $j = $i + 1; while ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) { $j++; }
                if ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_DOUBLE_ARROW) { $entryKey = stripcslashes(substr($t[1], 1, -1)); }
            }
            if ($depth === 2 && $entryKey !== null && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING
                && in_array($t[1], ["'pass'", '"pass"'], true)) {
                $target = $arrayVar . '[' . $entryKey . '].pass';
                $j = $i + 1; while ($j < $n && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) { $j++; }
                if (isset($set[$target]) && $j < $n && is_array($tok[$j]) && $tok[$j][0] === T_DOUBLE_ARROW) {
                    $k = $j + 1; $mid = '';
                    while ($k < $n && is_array($tok[$k]) && $tok[$k][0] === T_WHITESPACE) { $mid .= $tok[$k][1]; $k++; }
                    if ($k < $n && is_array($tok[$k]) && $tok[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $out .= $t[1];
                        for ($m = $i + 1; $m <= $j; $m++) { $out .= $txt($tok[$m]); }
                        $out .= $mid . $set[$target];
                        $done[$target] = true;
                        $i = $k;
                        continue;
                    }
                }
            }
        }
        $out .= $txt($t);
    }
    foreach ($set as $target => $_) { if (!isset($done[$target])) { strip_fail("could not find $target as a simple assignment in the file"); } }
    return $out;
}

function strip_lint($src) {
    $tmp = tempnam(sys_get_temp_dir(), 'p3s');
    file_put_contents($tmp, $src);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
    unlink($tmp);
    return $rc === 0;
}

function strip_file($file, $kind, array $bundle, $restore, &$report) {
    $before = strip_read($file);
    $targets = $kind === 'admin' ? strip_targets_admin($before) : strip_targets_child($before);
    $set = []; $expect = [];
    foreach ($targets as $target => $bname) {
        if (!array_key_exists($bname, $bundle)) { continue; }
        $cur = strip_value($before, $target);
        if ($restore) {
            if ($cur !== null && $cur !== '' && $cur !== '[]' && $cur !== '0') { continue; }       // already holds a value
            $isRing = in_array($bname, WN_ROOT_SECRET_RINGS, true);
            $val = $isRing ? json_decode($bundle[$bname], true) : $bundle[$bname];
            $set[$target] = var_export(($bname === 'SMTP_PORT' && ctype_digit($val)) ? (int) $val : $val, true);
            $expect[$target] = $isRing ? json_encode($val) : (string) $bundle[$bname];
        } else {
            if ($cur === null || $cur === '' || $cur === '[]' || ($cur === '0' && is_int($before[substr($target, 1)] ?? null))) { continue; } // already empty
            if (!hash_equals((string) $bundle[$bname], (string) $cur)) { strip_fail("$bname in the bundle differs from $file; re-run the seal step first"); }
            $isRing = in_array($bname, WN_ROOT_SECRET_RINGS, true);
            $set[$target] = $isRing ? '[]' : (is_int(($before[substr($target, 1)] ?? null)) ? '0' : "''");
            $expect[$target] = $isRing ? '[]' : (is_int(($before[substr($target, 1)] ?? null)) ? '0' : '');
        }
    }
    if (!$set) { $report[] = basename(dirname(dirname($file))) . ": nothing to " . ($restore ? 'restore' : 'empty'); return; }
    $src = file_get_contents($file);
    $new = strip_rewrite($src, $set);
    if (!strip_lint($new)) { strip_fail("the edited $file does not lint"); }
    $tmp = $file . '.p3sig-tmp' . getmypid();
    file_put_contents($tmp, $new);
    @chmod($tmp, fileperms($file) & 0777);
    $after = strip_read($tmp);
    foreach ($expect as $target => $want) {
        if ((string) strip_value($after, $target) !== $want) { unlink($tmp); strip_fail("check failed for $target in $file"); }
    }
    $touched = array_map(function ($t) { return preg_match('/^\$(\w+)$/', $t, $m) ? $m[1] : explode('[', $t)[0]; }, array_keys($set));
    foreach ($before as $k => $v) {
        if (in_array($k, $touched, true)) { continue; }
        if (!array_key_exists($k, $after) || $after[$k] !== $v) { unlink($tmp); strip_fail("\$$k would change in $file"); }
    }
    if (!rename($tmp, $file)) { @unlink($tmp); strip_fail("could not write $file"); }
    $report[] = basename(dirname(dirname($file))) . ': ' . ($restore ? 'restored ' : 'emptied ') . count($set) . ' value(s)'
        . ($GLOBALS['names'] ? ' (' . implode(', ', array_map(function ($t) use ($targets) { return $targets[$t]; }, array_keys($set))) . ')' : '');
}

$report = [];
strip_file($adminCfg, 'admin', $bundle, $restore, $report);
foreach ($children as $c) {
    $f = realpath($c);
    if (!$f) { strip_fail("no child config at $c"); }
    strip_file($f, 'child', $bundle, $restore, $report);
}
echo implode("\n", $report), "\n";

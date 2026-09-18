<?php
/**
 * scripts/clock-probe.php — one clock, UTC, on both sides of every connection.
 *
 * 2026-09-18: PHP ran in UTC while MySQL ran in the server's local zone (UTC+1 in summer),
 * so in one table an SQL-written column (NOW(), DEFAULT CURRENT_TIMESTAMP) sat an hour ahead
 * of a PHP-written one. An error's resolved_at read an hour after the app's own clock, queued
 * email compared a PHP scheduled_at against NOW(), and ContactSwipe's reminders — stored in
 * UTC on purpose — were swept with "due_at <= NOW()" and fired an hour early all summer.
 *
 * The convention is in include/common/clockFunctions.php: PHP is UTC, every connection is
 * UTC, a stored datetime is UTC whoever wrote it, and local time exists only at the edges.
 * This holds the code to it, statically:
 *   1. every `new PDO(` sets the session zone (PDO::MYSQL_ATTR_INIT_COMMAND / wn_clock_pdo_options)
 *   2. every request bootstrap puts PHP on UTC
 *   3. nothing sets a different zone behind the convention's back
 *
 * A connection that genuinely must not be touched carries `clock-exempt: <reason>` in a
 * comment on the line above it.
 *
 * Usage: php scripts/clock-probe.php [--root=/path/to/app]   (DB-free; runs in any repo here)
 * Exit 0 clean, 1 on a finding, 2 on a usage error.
 */

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
    else { fwrite(STDERR, "unknown argument: $a\n"); exit(2); }
}
$root  = rtrim((string) ($opts['root'] ?? dirname(__DIR__)), '/');
$label = basename(realpath($root) ?: $root);
$fail  = 0;
function ok($m)  { echo "  ✓ $m\n"; }
function bad($m) { global $fail; echo "  ✗ $m\n"; $fail = 1; }

if (!is_dir("$root/include")) { fwrite(STDERR, "$label: no include/ directory\n"); exit(2); }

$files = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
    if (preg_match('~^(vendor|node_modules|tests?|docker)/~', $rel)) continue;
    $files[$rel] = explode("\n", (string) file_get_contents($f->getPathname()));
}
ksort($files);

/* ---- 1. Every connection sets the session zone ---------------------------- */
$conns = 0; $unset = [];
foreach ($files as $rel => $lines) {
    foreach ($lines as $i => $line) {
        if (strpos($line, 'new PDO(') === false) continue;
        if (strpos($rel, 'scripts/') === 0) continue;                 // probes and tools, not app traffic
        $conns++;
        $before = $lines[$i - 1] ?? '';
        if (stripos($before, 'clock-exempt') !== false) continue;
        // The statement may span a few lines; look at the whole call.
        $window = implode("\n", array_slice($lines, $i, 12));
        $window = substr($window, 0, strpos($window, ');') !== false ? strpos($window, ');') + 2 : strlen($window));
        if (strpos($window, 'MYSQL_ATTR_INIT_COMMAND') !== false && preg_match('~time_zone\s*=\s*.\+00:00~', $window)) continue;
        if (strpos($window, 'wn_clock_pdo_options(') !== false) continue;
        $unset[] = "$rel:" . ($i + 1);
    }
}
if ($unset) bad('a connection is opened without putting it on UTC (add PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = \'+00:00\'", or wn_clock_pdo_options()): ' . implode(', ', $unset));
else ok("all $conns database connections are opened on UTC");

/* ---- 2. The bootstraps put PHP on UTC ------------------------------------- */
$boots = array_values(array_filter(array_keys($files), function ($rel) {
    return preg_match('~^include/(bootstrap|common|common_api|common_auth|common_readonly|common_cron)\.php$~', $rel);
}));
$missing = [];
foreach ($boots as $rel) {
    $src = implode("\n", $files[$rel]);
    // A child app inherits the setting from core's bootstrap, which it includes.
    if (preg_match("~date_default_timezone_set\(\s*'UTC'\s*\)~", $src)) continue;
    // A bootstrap that includes another one (core's bootstrap.php, or admin's from a child app)
    // inherits the setting from it.
    if (preg_match('~(require|include)(_once)?[^;\n]*(bootstrap|common|common_api|common_auth|common_readonly)\.php~', $src)) continue;
    $missing[] = $rel;
}
if ($missing) bad('a bootstrap neither sets PHP to UTC nor includes core\'s: ' . implode(', ', $missing));
else if ($boots) ok('every request bootstrap runs PHP on UTC (its own line, or core\'s through the include chain)');

/* ---- 3. Nobody sets a different zone -------------------------------------- */
$others = [];
foreach ($files as $rel => $lines) {
    foreach ($lines as $i => $line) {
        if (preg_match('~date_default_timezone_set\(\s*[\'"]([^\'"]+)~', $line, $m) && $m[1] !== 'UTC') $others[] = "$rel:" . ($i + 1) . " (PHP → {$m[1]})";
        if (preg_match('~SET\s+time_zone\s*=\s*[\'"]([^\'"]+)~i', $line, $m) && !in_array($m[1], ['+00:00', 'UTC'], true)) $others[] = "$rel:" . ($i + 1) . " (SQL → {$m[1]})";
    }
}
if ($others) bad('something sets a zone other than UTC: ' . implode(', ', $others));
else ok('nothing sets a zone other than UTC (a person\'s own zone is applied at the edges, never to the connection)');

echo $fail ? "clock-probe: FAILED — $label\n" : "clock-probe: OK — $label, one clock (UTC) on both sides.\n";
exit($fail);

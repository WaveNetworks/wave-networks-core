<?php
/**
 * scripts/api-envelope-probe.php — the API never refuses in silence.
 *
 * 2026-09-18: a key without the scope an action needs got back 200 {"error":"","results":[]},
 * and so did a call naming an action the deployment does not have. Both are byte-identical to
 * "there is nothing here", so a fleet-wide check passed on apps it had never actually run on.
 *
 * The rules this holds, statically (DB-free, runs in any app here with --root):
 *   1. every api/index.php refuses an action it does not dispatch, by name (wn_refuse_unknown_action)
 *   2. no endpoint flattens a specific status: a 401/403/404/426 an action chose survives
 *   3. require_api_scope answers with an error AND a 4xx (core only — it lives there)
 *   4. every require_api_scope() call is read: a refusal is never dropped on the floor
 *
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

/* ---- 1 + 2. The endpoint ---------------------------------------------------- */
$endpoints = array_filter([$root . '/api/index.php'], 'is_file');
if (!$endpoints) { fwrite(STDERR, "$label: no api/index.php\n"); exit(2); }
foreach ($endpoints as $ep) {
    $rel = ltrim(str_replace($root, '', $ep), '/');
    $src = (string) file_get_contents($ep);
    if (strpos($src, 'wn_refuse_unknown_action(') !== false) ok("$rel refuses an action this deployment does not dispatch");
    else bad("$rel answers an unknown action with the same empty envelope as 'nothing found' — call wn_refuse_unknown_action() before building the response");

    if (preg_match('~http_response_code\(\)\s*<\s*400~', $src)) ok("$rel keeps the specific status an action chose (401/403/404/426) instead of flattening it to 400");
    else bad("$rel overwrites any status with 400 — guard it with http_response_code() < 400");
}

/* ---- 3. The scope gate itself (core) ---------------------------------------- */
$gate = $root . '/include/common/serviceApiKeyFunctions.php';
if (is_file($gate)) {
    $src = (string) file_get_contents($gate);
    if (preg_match('~function require_api_scope.*?\n\}~s', $src, $m)) {
        $body = $m[0];
        $has_err = substr_count($body, "\$_SESSION['error']") >= 2;
        $has_401 = strpos($body, 'http_response_code(401)') !== false;
        $has_403 = strpos($body, 'http_response_code(403)') !== false;
        if ($has_err && $has_401 && $has_403) ok('require_api_scope() names the missing scope and answers 401 (no key) / 403 (wrong key)');
        else bad('require_api_scope() must set an error AND a status: ' . ($has_err ? '' : 'error missing; ') . ($has_401 ? '' : '401 missing; ') . ($has_403 ? '' : '403 missing'));
        if (preg_match('~\$_SESSION\[.error.\]\s*=\s*[^;]*\$_SERVICE_API_KEY~', $body)) bad('require_api_scope() must never put the key itself in the message');
        else ok('the refusal names the scope and nothing about the key');
    } else {
        bad('could not read require_api_scope() in include/common/serviceApiKeyFunctions.php');
    }
}

/* ---- 4. Every scope check is read ------------------------------------------- */
$loose = [];
$dir = $root . '/include/actions';
if (is_dir($dir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->getExtension() !== 'php') continue;
        $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
        foreach (explode("\n", (string) file_get_contents($f->getPathname())) as $i => $line) {
            if (strpos($line, 'require_api_scope(') === false) continue;
            if (preg_match('~(if\s*\(\s*!?\s*require_api_scope\(|=\s*require_api_scope\(|return\s+require_api_scope\(|&&\s*require_api_scope\(|\|\|\s*require_api_scope\()~', $line)) continue;
            $loose[] = "$rel:" . ($i + 1);
        }
    }
}
if ($loose) bad('a scope check whose answer is discarded (the action runs either way): ' . implode(', ', $loose));
else ok('every scope check is read before the action does anything');

echo $fail ? "api-envelope-probe: FAILED — $label\n" : "api-envelope-probe: OK — $label, a refusal is always visible to the caller.\n";
exit($fail);

<?php
/**
 * scripts/migration-ledger-probe.php — a migration version cannot be claimed twice, and a
 * migration file cannot go missing unnoticed. Core's portable copy: every app on this stack
 * keeps db_migrations/{main,shard}/<version>.sql plus a declared target version, so the guard
 * is the same everywhere and lives here rather than being re-written per app.
 *
 * ContactSwipe, 2026-09-18: two agents both took main/8.1. A rebase dropped one .sql with no
 * conflict (a file only one side has produces none), the ledger still read 8.1, and the deploy
 * failed much later and far away ("csChangeRelationWord is dispatched but has no
 * cs_api_surface row") without ever naming the migration.
 *
 * The fix is that claiming a version is a change to a SHARED file, db_migrations/CLAIMED.tsv:
 * two changes taking the same number touch the same lines and git makes someone resolve it,
 * and a .sql that disappears leaves its line behind for this probe to name.
 *
 * Usage (from the app's root, or with --root):
 *   php scripts/migration-ledger-probe.php                 check this repo
 *   php scripts/migration-ledger-probe.php --root=/path    check another checked-out app
 *   php scripts/migration-ledger-probe.php --init          write CLAIMED.tsv from what exists
 *   php scripts/migration-ledger-probe.php --report        never fail; just say what it found
 *
 * CLAIMED.tsv is <family>/<version><TAB>description, oldest first. Comment lines start with #;
 * a line "#gap main/2.3 reason" records a number history never used, so it is not a new hole.
 *
 * Exit 0 clean, 1 on a problem, 2 on a usage error. DB-free and offline.
 */

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
    else { fwrite(STDERR, "unknown argument: $a\n"); exit(2); }
}
$root = rtrim((string) ($opts['root'] ?? dirname(__DIR__)), '/');
$report_only = !empty($opts['report']);
$label = basename(realpath($root) ?: $root);

$fails = [];
$oks   = [];
function ok($m)  { global $oks;   $oks[] = $m; }
function bad($m) { global $fails; $fails[] = $m; }

$migdir = $root . '/db_migrations';
if (!is_dir($migdir)) { fwrite(STDERR, "$label: no db_migrations/ directory\n"); exit(2); }

/* ---- What is on disk ------------------------------------------------------ */
$families = [];
foreach (['main', 'shard'] as $fam) {
    if (!is_dir("$migdir/$fam")) continue;
    $vs = [];
    foreach (glob("$migdir/$fam/*.sql") ?: [] as $f) {
        $v = basename($f, '.sql');
        if (!preg_match('~^\d+\.\d+$~', $v)) { bad("db_migrations/$fam/" . basename($f) . ' is not named <version>.sql'); continue; }
        $vs[] = $v;
    }
    usort($vs, function ($a, $b) { return (float) $a <=> (float) $b; });
    $families[$fam] = $vs;
}
if (!$families) { fwrite(STDERR, "$label: db_migrations/ has no main/ or shard/ directory\n"); exit(2); }

/* ---- What is declared ----------------------------------------------------- */
// A child app declares include/migration_versions.php ($child_db_version / $child_shard_version);
// core declares $db_version / $shard_version in include/bootstrap.php.
$declared = [];
foreach ([['include/migration_versions.php', 'child_db_version', 'child_shard_version'],
          ['include/bootstrap.php', 'db_version', 'shard_version'],
          ['include/common.php', 'db_version', 'shard_version']] as $src) {
    $txt = @file_get_contents($root . '/' . $src[0]);
    if ($txt === false) continue;
    foreach (['main' => $src[1], 'shard' => $src[2]] as $fam => $var) {
        if (isset($declared[$fam])) continue;
        if (preg_match('~\$' . preg_quote($var, '~') . '\s*=\s*(?:\$[a-z_]+\s*\?\?\s*)?([0-9.]+)\s*;~', $txt, $m)) {
            $declared[$fam] = ['version' => rtrim(rtrim($m[1], '0'), '.') === '' ? $m[1] : $m[1], 'file' => $src[0]];
        }
    }
}

/* ---- The manifest --------------------------------------------------------- */
$manifest = "$migdir/CLAIMED.tsv";
if (!empty($opts['init'])) {
    $out = ["# Every migration version this app has ever claimed, oldest first.",
            "#",
            "# One line per file: <family>/<version><TAB><what it is>. Claiming a version is a change to",
            "# this SHARED file, so two changes taking the same number collide in git instead of one",
            "# silently winning, and a .sql dropped by a rebase leaves its line behind for",
            "# scripts/migration-ledger-probe.php to name.",
            "#",
            "# Adding a migration: add the .sql AND its line here, and bump the declared version.",
            "# Never renumber or edit a version that has shipped. A number history never used is",
            "# recorded as: #gap <family>/<version> <why>"];
    foreach ($families as $fam => $vs) {
        foreach ($vs as $v) {
            $head = (string) @file_get_contents("$migdir/$fam/$v.sql", false, null, 0, 400);
            $desc = '';
            foreach (explode("\n", $head) as $line) {
                $line = trim($line);
                if (strpos($line, '--') !== 0) continue;
                $line = trim(ltrim($line, '- '));
                if ($line === '' || stripos($line, 'reminder') === 0) continue;
                $desc = preg_replace('~^' . preg_quote("$fam/$v.sql", '~') . '\s*[—-]*\s*~', '', $line);
                break;
            }
            $out[] = "$fam/$v\t" . ($desc !== '' ? $desc : "migration $fam/$v");
        }
    }
    file_put_contents($manifest, implode("\n", $out) . "\n");
    echo "wrote " . count($out) . " lines to db_migrations/CLAIMED.tsv\n";
}

$claimed = [];
$gaps_allowed = [];
if (!is_file($manifest)) {
    bad('db_migrations/CLAIMED.tsv is missing — run this probe with --init once, then commit it.');
} else {
    $dupes = [];
    foreach (file($manifest, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
        if (trim($line) === '') continue;
        if ($line[0] === '#') {
            if (preg_match('~^#gap\s+((?:main|shard)/\d+\.\d+)~', $line, $m)) $gaps_allowed[$m[1]] = true;
            continue;
        }
        $parts = explode("\t", $line, 2);
        $key = trim($parts[0]);
        if (!preg_match('~^(main|shard)/\d+\.\d+$~', $key)) {
            bad('CLAIMED.tsv line ' . ($n + 1) . ' is not <main|shard>/<version><TAB><what it is>: ' . $line);
            continue;
        }
        if (isset($claimed[$key])) $dupes[] = $key;
        $claimed[$key] = trim($parts[1] ?? '');
    }
    if ($dupes) bad('claimed twice in CLAIMED.tsv (two changes took the same version): ' . implode(', ', array_unique($dupes)));
    else if ($claimed) ok('no version is claimed twice');

    $on_disk = [];
    foreach ($families as $fam => $vs) foreach ($vs as $v) $on_disk["$fam/$v"] = true;
    $missing = array_diff(array_keys($claimed), array_keys($on_disk));
    $unclaimed = array_diff(array_keys($on_disk), array_keys($claimed));
    if ($missing)  bad('claimed but the .sql is GONE (a dropped migration): ' . implode(', ', $missing));
    else if ($claimed) ok('every claimed version still has its .sql file');
    if ($unclaimed) bad('a migration file nobody claimed (add its line to CLAIMED.tsv): ' . implode(', ', $unclaimed));
    else if ($claimed) ok('every migration file is claimed in CLAIMED.tsv');
}

/* ---- Declared version vs the newest file ---------------------------------- */
foreach ($families as $fam => $vs) {
    if (!$vs) continue;
    $top = end($vs);
    if (!isset($declared[$fam])) { bad("no declared version for the $fam line (include/migration_versions.php)"); continue; }
    $d = $declared[$fam]['version'];
    if (!in_array($d, $vs, true)) {
        bad("the $fam line declares $d in {$declared[$fam]['file']} but db_migrations/$fam/$d.sql does not exist"
            . " (newest file is $top) — a migration was lost, or the version was bumped without its file");
    } elseif ((float) $d < (float) $top) {
        bad("db_migrations/$fam goes up to $top but {$declared[$fam]['file']} still declares $d — the newer migration never runs");
    } else {
        ok("the declared $fam version ($d) is the newest $fam migration");
    }
}

/* ---- Gaps ----------------------------------------------------------------- */
foreach ($families as $fam => $vs) {
    $gaps = [];
    for ($i = 1; $i < count($vs); $i++) {
        [$aM, $am] = array_map('intval', explode('.', $vs[$i - 1]));
        $next = $aM . '.' . ($am + 1);
        $roll = ($aM + 1) . '.0';
        if ($vs[$i] !== $next && $vs[$i] !== $roll && empty($gaps_allowed["$fam/$next"])) $gaps[] = "$fam/$next";
    }
    if ($gaps) bad('a version in the middle of the line has no file (record it as "#gap <version> <why>" in CLAIMED.tsv if history never used it): ' . implode(', ', $gaps));
    else ok("the $fam line runs without a new gap");
}

/* ---- Report --------------------------------------------------------------- */
foreach ($oks as $m)   echo "  ✓ $m\n";
foreach ($fails as $m) echo "  ✗ $m\n";
$n = count($oks) + count($fails);
if (!$fails) {
    echo "migration-ledger-probe: OK — $label, $n checks. A version is claimed in db_migrations/CLAIMED.tsv, so taking one twice conflicts in git and a dropped .sql is named here.\n";
    exit(0);
}
echo "migration-ledger-probe: " . ($report_only ? "findings for $label (report only)" : "FAILED — $label") . "\n";
exit($report_only ? 0 : 1);

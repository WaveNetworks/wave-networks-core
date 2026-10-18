<?php
/**
 * scripts/rotate-secret-key.php — rotate $app_secret / $hiddenhash with the key rings
 * (include/common/keyRingFunctions.php, docs/core-secret-rotation.md). Per host:
 *
 *   php scripts/rotate-secret-key.php new-key          print a fresh random key value
 *   1. config.php: add it to $app_secrets (or $peppers) under a new id, e.g. 'k2'; keep
 *      the old one (a host with only $app_secret starts the ring as 'k1' => that value).
 *   2. config.php: $app_secret_current = 'k2' (or $pepper_current = 'p2'). New writes,
 *      new JWTs and new password hashes use it; everything old still opens.
 *   php scripts/rotate-secret-key.php sweep            re-seal stored values onto the
 *                                                       current key now (the daily cron does it too)
 *   php scripts/rotate-secret-key.php status           values per key, users per pepper
 *   php scripts/rotate-secret-key.php retire k1        refuses while anything is on k1
 *   3. config.php: remove the old id. Access tokens signed with it fail and renew.
 *
 * Peppers can't be swept (there are no passwords to re-hash); users move as they sign
 * in. Retiring a pepper with users left on it means those users must reset their password:
 * `retire p1 --force` lists them, it never changes anything itself.
 *
 * Child apps: values they keep in their own DB count only if they register the column
 * (wn_register_sealed_column()). Code with its own crypto off $app_secret (brand-compiler
 * bc_encrypt) must move to wn_secret_encrypt() before a new key is made current.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

$cmd = $argv[1] ?? '';
if ($cmd === 'new-key') {
    echo bin2hex(random_bytes(32)) . "\n";
    exit(0);
}
if (!in_array($cmd, ['status', 'sweep', 'retire'], true)) {
    fwrite(STDERR, "usage: php scripts/rotate-secret-key.php new-key | status | sweep | retire <id> [--force]\n");
    exit(2);
}

include __DIR__ . '/../include/common_readonly.php';
global $db, $child_db;

/** Users per pepper (the user table lives in the main DB). */
function rotate_pepper_counts() {
    global $db;
    try { return wn_password_pepper_counts($db); } catch (Throwable $e) { return []; }
}

$ring = wn_app_key_ring();
$pep  = wn_pepper_ring();

if ($cmd === 'sweep') {
    echo 'resealed onto ' . $ring['current'] . ': ' . json_encode(wn_secret_seal_sweep($db, $child_db ?? null)) . "\n";
    $cmd = 'status';
}

$counts = wn_secret_key_counts($db, $child_db ?? null);
$users  = rotate_pepper_counts();

if ($cmd === 'status') {
    echo "app secret ring: " . implode(', ', array_keys($ring['keys'])) . " (current {$ring['current']})\n";
    foreach ($counts as $col => $c) { echo "  $col: " . ($c ? json_encode($c) : 'empty') . "\n"; }
    echo "pepper ring: " . implode(', ', array_keys($pep['keys'])) . " (current {$pep['current']})\n";
    echo "  users: " . json_encode($users) . "  ('v1' = original format, pepper p1)\n";
    exit(0);
}

// retire <id>
$id = $argv[2] ?? '';
if ($id === '') { fwrite(STDERR, "retire needs a key id\n"); exit(2); }
if (isset($ring['keys'][$id])) {
    if ($id === $ring['current']) { fwrite(STDERR, "$id is the current key; make another key current first\n"); exit(1); }
    $left = [];
    foreach ($counts as $col => $c) {
        $n = ($c[$id] ?? 0) + ($c["v1:$id"] ?? 0) + ($id === 'k1' ? ($c['unmarked'] ?? 0) : 0);
        if ($n > 0) $left[$col] = $n;
    }
    if ($left) {
        fwrite(STDERR, "NOT safe to retire $id — values still on it: " . json_encode($left) . "\nRun `sweep` (and check child apps registered their columns).\n");
        exit(1);
    }
    echo "OK: no stored value is on $id. Remove it from \$app_secrets in config.php" .
         ($id === 'k1' ? " (access tokens issued before the ring stop verifying and renew)" : '') . ".\n";
    exit(0);
}
if (isset($pep['keys'][$id])) {
    if ($id === $pep['current']) { fwrite(STDERR, "$id is the current pepper; make another current first\n"); exit(1); }
    $n = ($users[$id] ?? 0) + ($id === 'p1' ? ($users['v1'] ?? 0) : 0);
    if ($n > 0 && !in_array('--force', $argv, true)) {
        fwrite(STDERR, "NOT safe to retire $id — $n user(s) still on it (they must sign in, or reset their password).\n");
        exit(1);
    }
    echo $n > 0
        ? "$n user(s) are on $id: removing it from \$peppers means they must reset their password. Nothing was changed.\n"
        : "OK: no user is on $id. Remove it from \$peppers in config.php.\n";
    exit(0);
}
fwrite(STDERR, "$id is not in either ring\n");
exit(1);

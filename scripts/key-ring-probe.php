<?php
/**
 * scripts/key-ring-probe.php — the $app_secret / $hiddenhash key rings
 * (include/common/keyRingFunctions.php, docs/core-secret-rotation.md).
 *
 *   1. passwords: a pre-ring hash (bcrypt of password . pepper) still verifies and asks for
 *      an upgrade; new hashes are $wn2$<pid>$ and don't; the 72-byte bcrypt truncation that
 *      let a long password drop the pepper is gone in v2; rotating the pepper keeps every
 *      hash verifying until its pepper is retired;
 *   2. JWTs: tokens carry the current kid; a pre-ring token (no kid, raw secret) still
 *      verifies while k1 is in the ring; after a rotation old-kid tokens verify until that
 *      key is retired; an unknown kid, a forged signature or garbage fail;
 *   3. the ring: no ring = today's single values; a malformed id is ignored; init points
 *      $app_secret / $hiddenhash at the current keys; purpose keys differ.
 *   4. static: bootstrap.php and common_readonly.php run wn_key_ring_init() before the DB.
 *
 * Usage: php scripts/key-ring-probe.php [--root=/path/to/admin]
 * Exit 0 clean, 1 on a problem. DB-free (needs vendor/ for firebase/php-jwt).
 */

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$root = rtrim((string) ($opts['root'] ?? dirname(__DIR__)), '/');
$fail = [];
$ok   = function ($cond, $msg) use (&$fail) { if (!$cond) $fail[] = $msg; };

require_once "$root/vendor/autoload.php";
require_once "$root/include/common/keyRingFunctions.php";
require_once "$root/include/common/passwordFunctions.php";
require_once "$root/include/common/jwtFunctions.php";
use Firebase\JWT\JWT;

// ─── 4. static ──────────────────────────────────────────────────────────────
foreach (['include/bootstrap.php', 'include/common_readonly.php'] as $rel) {
    $src = (string) @file_get_contents("$root/$rel");
    $cfg = strpos($src, 'config/config.php');
    $ini = strpos($src, 'wn_key_ring_init(');
    $pdo = strpos($src, 'new PDO(');
    $ok($cfg !== false && $ini !== false && $pdo !== false && $cfg < $ini && $ini < $pdo,
        "$rel: wn_key_ring_init() must run after config.php and before the PDO connect");
}

// ─── 1. passwords ───────────────────────────────────────────────────────────
$hiddenhash = 'pepper-one-' . str_repeat('x', 40);
$legacy = password_hash('correct horse' . $hiddenhash, PASSWORD_BCRYPT, ['cost' => 4]);
$ok(verify_password('correct horse', $legacy), 'a pre-ring password hash no longer verifies (users locked out)');
$ok(!verify_password('wrong horse', $legacy), 'a pre-ring hash verifies the wrong password');
$ok(password_needs_upgrade($legacy), 'a pre-ring hash does not ask to be upgraded at login');

$h1 = hash_password('correct horse');
$ok(strncmp($h1, '$wn2$p1$', 8) === 0, 'new hashes are not $wn2$p1$…: ' . substr($h1, 0, 12));
$ok(verify_password('correct horse', $h1) && !verify_password('correct horsf', $h1), 'v2 hash does not verify correctly');
$ok(!password_needs_upgrade($h1), 'a current v2 hash asks to be upgraded (rehash on every login)');
$ok(strlen($h1) <= 255, 'v2 hash does not fit user.password VARCHAR(255)');

// 72-byte truncation: two passwords sharing the first 72 bytes
$long = str_repeat('a', 72);
$ok(password_verify($long . 'TAIL-B' . $hiddenhash, password_hash($long . 'TAIL-A' . $hiddenhash, PASSWORD_BCRYPT, ['cost' => 4])),
    'probe premise: v1 should have accepted a different long password (bcrypt truncation)');
$hl = hash_password($long . 'TAIL-A');
$ok(verify_password($long . 'TAIL-A', $hl) && !verify_password($long . 'TAIL-B', $hl), 'v2 still truncates long passwords');

// pepper rotation: p2 current, p1 kept
$peppers = ['p2' => 'pepper-two-' . str_repeat('y', 40), 'p1' => $hiddenhash];
$pepper_current = 'p2';
$ok(verify_password('correct horse', $legacy) && verify_password('correct horse', $h1), 'after adding p2, p1 hashes stopped verifying');
$ok(password_needs_upgrade($h1), 'a p1 hash does not ask to move to p2');
$h2 = hash_password('correct horse');
$ok(strncmp($h2, '$wn2$p2$', 8) === 0 && verify_password('correct horse', $h2) && !password_needs_upgrade($h2), 'new hashes are not on p2');
$peppers = ['p2' => $peppers['p2']];
$ok(!verify_password('correct horse', $legacy) && !verify_password('correct horse', $h1), 'a retired pepper p1 still verifies');
$ok(verify_password('correct horse', $h2), 'p2 hash stopped verifying when p1 was retired');
unset($peppers, $pepper_current);

// ─── 2. JWTs ────────────────────────────────────────────────────────────────
$app_secret = 'app-one-' . str_repeat('z', 40);
$t = jwt_issue(7, 600);
$hdr = json_decode(JWT::urlsafeB64Decode(explode('.', $t)[0]), true);
$ok(($hdr['kid'] ?? null) === 'k1', 'new tokens do not carry kid k1');
$ok(($v = jwt_verify($t)) && (int) $v->sub === 7, 'a fresh token does not verify');
$pre = JWT::encode(['sub' => 8, 'iat' => time(), 'exp' => time() + 600], $app_secret, 'HS256');
$ok(($v = jwt_verify($pre)) && (int) $v->sub === 8, 'a pre-ring token (no kid, raw secret) no longer verifies (mass sign-out on deploy)');
$forged = JWT::encode(['sub' => 9, 'exp' => time() + 600], wn_key('jwt', 'k1') . 'x', 'HS256', 'k1');
$ok(jwt_verify($forged) === false, 'a token signed with the wrong key verifies');
$ok(jwt_verify(JWT::encode(['sub' => 9, 'exp' => time() + 600], 'k9-key-' . str_repeat('q', 40), 'HS256', 'k9')) === false, 'an unknown kid verifies');
$ok(jwt_verify('garbage') === false && jwt_verify('') === false && jwt_verify('a.b.c') === false, 'garbage verifies (or throws)');

$app_secrets = ['k2' => 'app-two-' . str_repeat('w', 40), 'k1' => $app_secret];
$app_secret_current = 'k2';
$t2 = jwt_issue(10, 600);
$ok((json_decode(JWT::urlsafeB64Decode(explode('.', $t2)[0]), true)['kid'] ?? null) === 'k2', 'after rotation new tokens are not on k2');
$ok(jwt_verify($t2) && jwt_verify($t) && jwt_verify($pre), 'after rotation, k1 / pre-ring tokens stopped verifying before k1 was retired');
$app_secrets = ['k2' => $app_secrets['k2']];
$ok(jwt_verify($t2) && jwt_verify($t) === false && jwt_verify($pre) === false, 'retiring k1 did not end k1 / pre-ring tokens (or broke k2)');
unset($app_secrets, $app_secret_current);

// ─── 3. the ring ────────────────────────────────────────────────────────────
$r = wn_app_key_ring();
$ok($r['keys'] === ['k1' => $app_secret] && $r['current'] === 'k1' && $r['legacy'] === 'k1', 'no ring configured should be k1 = $app_secret');
$app_secrets = ['K-BAD!' => 'x', 'k3' => 'three-' . str_repeat('v', 40)];
$r = wn_app_key_ring();
$ok(array_keys($r['keys']) === ['k3'] && $r['current'] === 'k3' && $r['legacy'] === null, 'a malformed key id is not ignored / current not defaulted');
$ok(wn_key('jwt', 'k3') !== wn_key('seal', 'k3') && strlen(wn_key('seal', 'k3')) === 32, 'purpose keys are not separate 32-byte keys');
$ok(wn_key('seal', 'nope') === null, 'an unknown kid yields a key');
$app_secret_current = 'k3';
$peppers = ['p5' => 'five-' . str_repeat('u', 40)];
$pepper_current = 'p5';
wn_key_ring_init(true);
$ok($app_secret === $app_secrets['k3'] && $hiddenhash === $peppers['p5'], 'init does not point $app_secret / $hiddenhash at the current keys');

if ($fail) {
    fwrite(STDERR, "key-ring-probe: FAIL\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "key-ring-probe: OK\n";
exit(0);

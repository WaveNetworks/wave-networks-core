<?php
/**
 * scripts/stored-secrets-probe.php — credentials in the DB are sealed or hashed (task #2397).
 *
 * Holds include/common/secretFunctions.php and its callers to:
 *   1. every WRITE of a known secret column goes through wn_secret_seal() / wn_token_hash()
 *      (static: the write sites are named, and a raw write fails the deploy);
 *   2. the send path opens smtp_pass (get_email_settings) and totp_verify opens totp_secret;
 *   3. RUN on in-memory SQLite with the real functions: a saved password's raw DB value is
 *      not the password and carries the marker; it opens back to the password; a legacy
 *      plain row still reads correctly; the sweep seals it and a second sweep changes
 *      nothing (idempotent); afterwards no known column holds an unmarked value;
 *      rotation re-encrypts under a new key;
 *   4. migration main/5.9 is declared and hashes forgot/invite idempotently.
 *
 * Usage: php scripts/stored-secrets-probe.php [--root=/path/to/admin]
 * Exit 0 clean, 1 on a problem. DB-free (needs pdo_sqlite + openssl).
 */

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$root = rtrim((string) ($opts['root'] ?? dirname(__DIR__)), '/');
$fail = [];
$ok   = function ($cond, $msg) use (&$fail) { if (!$cond) $fail[] = $msg; };
$src  = function ($rel) use ($root) { return (string) @file_get_contents("$root/$rel"); };

// ── 1+2. static: write and read sites ────────────────────────────────────────
$ok(strpos($src('include/actions/memberActions/emailSettingsActions.php'), 'wn_secret_seal($smtp_pass_input)') !== false,
    'emailSettingsActions.php saves smtp_pass without wn_secret_seal()');
$ok(strpos($src('include/actions/memberActions/oauthActions.php'), 'wn_secret_seal($client_secret)') !== false,
    'oauthActions.php saves client_secret without wn_secret_seal()');
$ok(strpos($src('include/actions/memberActions/twoFactorActions.php'), 'wn_secret_seal($secret)') !== false,
    'twoFactorActions.php saves totp_secret without wn_secret_seal()');
$ok(substr_count($src('include/common/emailQueueFunctions.php'), "wn_secret_open(\$settings['smtp_pass'])") >= 2,
    'get_email_settings() / _get_email_settings_fresh() do not open smtp_pass');
$ok(strpos($src('include/common/totpFunctions.php'), 'wn_secret_open($secret)') !== false,
    'totp_verify() does not open the sealed totp_secret');
$ok(strpos($src('include/actions/loginActions/loginActions.php'), "'\$token_hash', NOW()") !== false,
    'loginActions.php stores the raw forgot token');
$ok(strpos($src('auth/reset.php'), 'wn_token_hash($token)') !== false, 'auth/reset.php looks up the raw forgot token');
$ok(strpos($src('auth/register_invite.php'), 'wn_token_hash($invite_token)') !== false, 'auth/register_invite.php does not look up by hash');

// Nothing else may write these columns raw: every "smtp_pass = '" / "client_secret = '" /
// "totp_secret = '" assignment in PHP must use the sealed variable names above.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || strpos($p, '/scripts/') !== false) continue;
    $code = (string) file_get_contents($p);
    if (preg_match_all("/\\b(smtp_pass|client_secret|totp_secret)\\s*=\\s*'\\\$(\\w+)'/", $code, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $ok(in_array($m[2], ['s_pass', 'safe_secret', 'sealed_secret'], true),
                substr($p, strlen($root) + 1) . ": {$m[1]} written from \${$m[2]} (not sealed?)");
        }
    }
}

// ── 3. run the real functions on SQLite ──────────────────────────────────────
$app_secret = 'probe-key-one';
require_once "$root/include/common/shardConfigFunctions.php";
require_once "$root/include/common/secretFunctions.php";
require_once "$root/scripts/rotate-secret-key.php";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE email_settings (setting_id INTEGER PRIMARY KEY, smtp_pass TEXT)");
$pdo->exec("CREATE TABLE oauth_provider (provider_id INTEGER PRIMARY KEY, client_secret TEXT)");
$pdo->exec("CREATE TABLE user (user_id INTEGER PRIMARY KEY, totp_secret TEXT)");

$pw = 'hunter2-smtp-Ü';
$pdo->prepare("INSERT INTO email_settings VALUES (1, ?)")->execute([wn_secret_seal($pw)]);
$raw = $pdo->query("SELECT smtp_pass FROM email_settings")->fetchColumn();
$ok($raw !== $pw && strpos($raw, $pw) === false, 'saved smtp_pass: raw DB value contains the password');
$ok(wn_secret_is_sealed($raw), 'saved smtp_pass: raw DB value has no enc:v1: marker');
$ok(wn_secret_open($raw) === $pw, 'saved smtp_pass does not open back to the password');
$ok(wn_secret_seal($raw) === $raw, 'sealing a sealed value changed it (double encryption)');
$ok(wn_secret_seal('') === '' && wn_secret_open('') === '', 'empty value is not left empty');

// legacy plain rows (written before this change)
$pdo->exec("INSERT INTO oauth_provider VALUES (1, 'legacy-client-secret')");
$pdo->exec("INSERT INTO user VALUES (1, 'JBSWY3DPEHPK3PXP'), (2, NULL), (3, '')");
$ok(wn_secret_open('legacy-client-secret') === 'legacy-client-secret', 'a legacy plain value does not read during rollout');
$ok(wn_secret_unsealed_counts($pdo) === ['oauth_provider.client_secret' => 1, 'user.totp_secret' => 1],
    'unsealed count wrong before sweep: ' . json_encode(wn_secret_unsealed_counts($pdo)));
$s1 = wn_secret_seal_sweep($pdo);
$after1 = $pdo->query("SELECT client_secret FROM oauth_provider")->fetchColumn() . '|' . $pdo->query("SELECT totp_secret FROM user WHERE user_id=1")->fetchColumn();
$s2 = wn_secret_seal_sweep($pdo);
$after2 = $pdo->query("SELECT client_secret FROM oauth_provider")->fetchColumn() . '|' . $pdo->query("SELECT totp_secret FROM user WHERE user_id=1")->fetchColumn();
$ok(array_sum($s1) === 2, 'first sweep should seal 2 rows, sealed ' . json_encode($s1));
$ok(array_sum($s2) === 0 && $after1 === $after2, 'second sweep changed data (not idempotent)');
$ok(wn_secret_unsealed_counts($pdo) === [], 'a known secret column still holds an unmarked value after the sweep');
$ok(wn_secret_open($pdo->query("SELECT totp_secret FROM user WHERE user_id=1")->fetchColumn()) === 'JBSWY3DPEHPK3PXP',
    'swept totp_secret does not open to the original');
$ok($pdo->query("SELECT COUNT(*) FROM user WHERE totp_secret IS NULL OR totp_secret = ''")->fetchColumn() == 2, 'sweep touched empty values');

// rotation: old key → new key, values still open, and under the new key only
$n = wn_secret_rotate($pdo, 'probe-key-one', 'probe-key-two');
$ok($n === 3, "rotation should re-encrypt 3 values, did $n");
$app_secret = 'probe-key-two';
$ok(wn_secret_open($pdo->query("SELECT smtp_pass FROM email_settings")->fetchColumn()) === $pw, 'after rotation smtp_pass does not open under the new key');
$ok(wn_secret_open($pdo->query("SELECT client_secret FROM oauth_provider")->fetchColumn()) === 'legacy-client-secret', 'after rotation client_secret does not open');

// hashing
$ok(wn_token_hash('abc') === 'sha256:' . hash('sha256', 'abc'), 'wn_token_hash format changed (breaks migration main/5.9 rows)');

// ── 4. migration main/5.9 ────────────────────────────────────────────────────
$mig = $src('db_migrations/main/5.9.sql');
$ok($mig !== '' && substr_count($mig, "NOT LIKE 'sha256:%'") === 2, 'main/5.9 missing or not idempotent (NOT LIKE sha256: guard)');
$ok(preg_match('/\$db_version\s*=\s*\$db_version \?\? (\d+\.\d+)/', $src('include/bootstrap.php'), $vm) && (float) $vm[1] >= 5.9,
    '$db_version is below 5.9');

if ($fail) {
    fwrite(STDERR, "stored-secrets-probe: FAIL\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "stored-secrets-probe: ok (writes sealed/hashed, legacy reads, sweep idempotent, rotation)\n";
exit(0);

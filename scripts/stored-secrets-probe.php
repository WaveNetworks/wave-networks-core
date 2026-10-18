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
 *   4. migration main/5.9 is declared and hashes forgot/invite idempotently;
 *   5. api_key.api_key (remember-me / device bearer keys, task #2398): create_api_key() stores
 *      wn_token_hash(), validate/delete accept the hash and a legacy raw row, the stored value
 *      is never handed back, no other code compares the column raw, and main/6.0 hashes
 *      existing rows idempotently (run on SQLite with the real functions).
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
require_once "$root/include/common/migrationSyncFunctions.php";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE email_settings (setting_id INTEGER PRIMARY KEY, smtp_pass TEXT)");
$pdo->exec("CREATE TABLE oauth_provider (provider_id INTEGER PRIMARY KEY, client_secret TEXT)");
$pdo->exec("CREATE TABLE user (user_id INTEGER PRIMARY KEY, totp_secret TEXT)");

$pw = 'hunter2-smtp-Ü';
$pdo->prepare("INSERT INTO email_settings VALUES (1, ?)")->execute([wn_secret_seal($pw)]);
$raw = $pdo->query("SELECT smtp_pass FROM email_settings")->fetchColumn();
$ok($raw !== $pw && strpos($raw, $pw) === false, 'saved smtp_pass: raw DB value contains the password');
$ok(wn_secret_is_sealed($raw), 'saved smtp_pass: raw DB value has no enc: marker');
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

// pre-ring v1 values (AES-256-CBC off the k1 secret) still open, and say which key they're on
$legacyKey = substr(hash('sha256', 'probe-key-one'), 0, 32);
$iv = random_bytes(16);
$v1 = 'enc:v1:' . base64_encode($iv . openssl_encrypt('v1-smtp', 'AES-256-CBC', $legacyKey, OPENSSL_RAW_DATA, $iv));
$ok(wn_secret_open($v1) === 'v1-smtp', 'a pre-ring enc:v1: value does not open');
$ok(wn_secret_kid($v1) === 'k1' && wn_secret_kid($raw) === 'k1', 'v1 and fresh values should both be on k1 before any rotation');
$ok(strncmp($raw, 'enc:v2:k1:', 10) === 0, 'new seals are not enc:v2:k1: (authenticated, kid-tagged)');
$tb = base64_decode(substr($raw, 10)); $tb[strlen($tb) - 1] = $tb[strlen($tb) - 1] ^ "\x01"; $t = substr($raw, 0, 10) . base64_encode($tb);
$ok(wn_secret_open($t) === '', 'a tampered enc:v2: value opened (no authentication)');

// pre-marker shard_config / migration_source framings are swept too
$pdo->exec("CREATE TABLE shard_config (layer TEXT, app_slug TEXT, shard_id TEXT, db_pass_enc TEXT)");
$pdo->exec("CREATE TABLE migration_source (source_id INTEGER PRIMARY KEY, db_password_enc TEXT)");
$pdo->prepare("INSERT INTO shard_config VALUES ('admin', '', 'shard1', ?)")->execute([base64_encode($iv . openssl_encrypt('shard-pw', 'AES-256-CBC', $legacyKey, OPENSSL_RAW_DATA, $iv))]);
$pdo->prepare("INSERT INTO migration_source VALUES (1, ?)")->execute([base64_encode($iv . '::' . base64_decode(openssl_encrypt('src-pw', 'AES-256-CBC', $legacyKey, 0, $iv)))]);
$pdo->prepare("UPDATE email_settings SET smtp_pass = ?")->execute([$v1]);
$ok(wn_secret_decrypt($pdo->query("SELECT db_pass_enc FROM shard_config")->fetchColumn()) === 'shard-pw', 'a pre-ring shard_config password does not open');
$ok(decrypt_source_password($pdo->query("SELECT db_password_enc FROM migration_source")->fetchColumn()) === 'src-pw', 'a pre-ring migration_source password does not open');
$s3 = wn_secret_seal_sweep($pdo);
$ok(($s3['email_settings.smtp_pass'] ?? 0) === 1 && ($s3['shard_config.db_pass_enc'] ?? 0) === 1 && ($s3['migration_source.db_password_enc'] ?? 0) === 1,
    'sweep should move the v1 smtp_pass and both pre-marker passwords onto the current key: ' . json_encode($s3));
$ok(wn_secret_decrypt($pdo->query("SELECT db_pass_enc FROM shard_config")->fetchColumn()) === 'shard-pw'
    && decrypt_source_password($pdo->query("SELECT db_password_enc FROM migration_source")->fetchColumn()) === 'src-pw',
    'swept shard / source passwords do not open');

// rotation with the ring: add k2, make it current, sweep, retire k1 — readable at every step
$app_secrets = ['k2' => 'probe-key-two', 'k1' => 'probe-key-one'];
$app_secret_current = 'k2';
$readAll = function () use ($pdo) {
    return wn_secret_open($pdo->query("SELECT smtp_pass FROM email_settings")->fetchColumn()) . '|'
         . wn_secret_open($pdo->query("SELECT client_secret FROM oauth_provider")->fetchColumn()) . '|'
         . wn_secret_decrypt($pdo->query("SELECT db_pass_enc FROM shard_config")->fetchColumn()) . '|'
         . decrypt_source_password($pdo->query("SELECT db_password_enc FROM migration_source")->fetchColumn());
};
$want = 'v1-smtp|legacy-client-secret|shard-pw|src-pw';
$ok($readAll() === $want, 'after making k2 current, k1 values stopped opening');
$ok(strncmp(wn_secret_seal('fresh'), 'enc:v2:k2:', 10) === 0, 'new seals are not on the current key k2');
$s4 = wn_secret_seal_sweep($pdo);
$ok(array_sum($s4) === 5, 'sweep should move all 5 values onto k2: ' . json_encode($s4));
$kc = wn_secret_key_counts($pdo);
$onK1 = 0;
foreach ($kc as $c) { $onK1 += ($c['k1'] ?? 0) + ($c['v1:k1'] ?? 0) + ($c['unmarked'] ?? 0); }
$ok($onK1 === 0, 'values left on k1 after the sweep: ' . json_encode($kc));
$ok(array_sum(wn_secret_seal_sweep($pdo)) === 0, 'second sweep after rotation changed data');
$app_secrets = ['k2' => 'probe-key-two'];
$ok($readAll() === $want, 'after retiring k1, swept values do not open');
$ok(wn_secret_open($v1) === '' && wn_secret_open($t) === '', 'a k1 value still opens after k1 was retired');
unset($app_secrets, $app_secret_current);

// hashing
$ok(wn_token_hash('abc') === 'sha256:' . hash('sha256', 'abc'), 'wn_token_hash format changed (breaks migration main/5.9 rows)');

// ── 4. migration main/5.9 ────────────────────────────────────────────────────
$mig = $src('db_migrations/main/5.9.sql');
$ok($mig !== '' && substr_count($mig, "NOT LIKE 'sha256:%'") === 2, 'main/5.9 missing or not idempotent (NOT LIKE sha256: guard)');
$ok(preg_match('/\$db_version\s*=\s*\$db_version \?\? (\d+\.\d+)/', $src('include/bootstrap.php'), $vm) && (float) $vm[1] >= 5.9,
    '$db_version is below 5.9');

// ── 5. api_key.api_key hashed at rest (task #2398) ───────────────────────────
$ak = $src('include/common/apiKeyFunctions.php');
$ok(strpos($ak, 'wn_token_hash($api_key)') !== false, 'create_api_key() stores the raw key (not wn_token_hash)');
$ok(strpos($src('include/common/mobileAuthFunctions.php'), 'delete_api_key($token)') !== false,
    'wn_revoke_device_token() no longer goes through delete_api_key() (raw lookup?)');
// Outside apiKeyFunctions.php nothing may look a key up by value: that is the raw lookup
// that would stop matching once rows are hashed.
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || strpos($p, '/scripts/') !== false
        || substr($p, -strlen('include/common/apiKeyFunctions.php')) === 'include/common/apiKeyFunctions.php') continue;
    if (preg_match("/(?<![_\w])(ak\.)?api_key\s*(=|IN\s*\()\s*'/i", (string) file_get_contents($p))) {
        $ok(false, substr($p, strlen($root) + 1) . ': looks up api_key.api_key by value — use validate_api_key()/delete_api_key()');
    }
}

$pdo->sqliteCreateFunction('CURDATE', function () { return gmdate('Y-m-d'); }, 0);
$pdo->sqliteCreateFunction('CONCAT', function ($a, $b) { return $a . $b; }, 2);
$pdo->sqliteCreateFunction('SHA2', function ($v, $bits) { return hash('sha256', (string) $v); }, 2);
$pdo->exec("CREATE TABLE api_key (key_id INTEGER PRIMARY KEY, device_id INT, user_id INT, api_key TEXT UNIQUE, key_born TEXT, remember_me TEXT)");
if (!defined('SQL')) define('SQL', 'sql');
if (!function_exists('sanitize'))         { function sanitize($v, $t = null) { return str_replace("'", "''", (string) $v); } }
if (!function_exists('generateHashCode')) { function generateHashCode($n) { return substr(bin2hex(random_bytes($n)), 0, $n); } }
if (!function_exists('db_query'))         { function db_query($sql) { global $pdo; return $pdo->query($sql); } }
if (!function_exists('db_fetch'))         { function db_fetch($r) { return $r ? $r->fetch(PDO::FETCH_ASSOC) : false; } }
require_once "$root/include/common/apiKeyFunctions.php";

$key = create_api_key(1, 7, 'yes');
$stored = $pdo->query("SELECT api_key FROM api_key WHERE device_id = 7")->fetchColumn();
$ok($stored === wn_token_hash($key) && strpos($stored, $key) === false, 'create_api_key(): DB holds the raw key, not wn_token_hash()');
$u = validate_api_key($key);
$ok($u && (int) $u['user_id'] === 1, 'validate_api_key() does not accept a freshly issued key');
$ok($u && !array_key_exists('api_key', $u), 'validate_api_key() hands the stored key value back to the caller');
$ok(validate_api_key($stored) === false, 'validate_api_key() accepts the stored HASH as a key (hash is a credential)');
$ok(validate_api_key('') === false && validate_api_key('nope') === false, 'validate_api_key() accepts an empty/unknown key');
$pdo->exec("INSERT INTO api_key (device_id, user_id, api_key, key_born, remember_me) VALUES (8, 1, 'legacy-raw-key', '2026-10-01', 'yes')");
$ok((bool) validate_api_key('legacy-raw-key'), 'a legacy raw api_key row stops working during rollout');
delete_api_key($key);
$ok((int) $pdo->query("SELECT COUNT(*) FROM api_key WHERE device_id = 7")->fetchColumn() === 0, 'delete_api_key() does not delete a hashed key');

$mig6 = $src('db_migrations/main/6.0.sql');
$ok($mig6 !== '' && strpos($mig6, "NOT LIKE 'sha256:%'") !== false, 'main/6.0 missing or not idempotent (NOT LIKE sha256: guard)');
preg_match_all('/^UPDATE[^;]+;/ms', $mig6, $um);
foreach ($um[0] as $stmt) { $pdo->exec(str_replace('`', '"', $stmt)); }
$once = $pdo->query("SELECT api_key FROM api_key ORDER BY key_id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($um[0] as $stmt) { $pdo->exec(str_replace('`', '"', $stmt)); }
$twice = $pdo->query("SELECT api_key FROM api_key ORDER BY key_id")->fetchAll(PDO::FETCH_COLUMN);
$ok($once === [wn_token_hash('legacy-raw-key')], 'main/6.0 did not hash the legacy row: ' . json_encode($once));
$ok($once === $twice, 'main/6.0 run twice changed data (not idempotent)');
$ok((bool) validate_api_key('legacy-raw-key'), 'a client holding a legacy key is signed out by main/6.0');
$ok((float) ($vm[1] ?? 0) >= 6.0, '$db_version is below 6.0 (main/6.0 would not run)');

if ($fail) {
    fwrite(STDERR, "stored-secrets-probe: FAIL\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "stored-secrets-probe: ok (writes sealed/hashed, legacy reads, sweep idempotent, key-ring rotation, api_key hashed)\n";
exit(0);

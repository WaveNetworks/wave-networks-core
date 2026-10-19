#!/bin/bash
# Roots through key-server mode, end to end (p3sig doc §4.4): a fake deployment's
# config.php values are sealed ON this host by scripts/p3sig-keyserver-seal.php (PHP wrap →
# Go key server), then blanked as migration step 3 will, and must come back through the
# live key server: DB password, $app_secret and pepper rings, a seed shard, the child DB.
# Run on the key-server machine (needs sudo to allowlist a temporary app).
set -u
CORE=$(cd "$(dirname "$0")/.." && pwd)
W=$(mktemp -d); OUT=$(mktemp -d)
A="$W/admin"; mkdir -p "$A/include/common" "$A/scripts" "$A/config" "$W/kid/config"
cp "$CORE/include/common/runtimeSecretFunctions.php" "$CORE/include/common/keyRingFunctions.php" "$A/include/common/"
cp "$CORE/scripts/p3sig-keyserver-seal.php" "$A/scripts/"
cat > "$A/config/config.php" <<'PHP'
<?php
$dbHostSpec = 'localhost'; $dbInstance = 'x_main'; $dbUserName = 'x_user'; $dbPassword = 'db-pw-ROOT';
$hiddenhash = 'pepper-old'; $app_secret = 'app-old';
$app_secrets = ['k2' => 'app-k2-NEW', 'k1' => 'app-old']; $app_secret_current = 'k2';
$peppers = ['p2' => 'pepper-p2-NEW', 'p1' => 'pepper-old']; $pepper_current = 'p2';
$smtp_pass = 'smtp-pw'; $stripe_secret_key = '';
$shardConfigs = ['shard1' => ['host' => 'h', 'name' => 'x_s1', 'user' => 'u', 'pass' => 'shard1-pw']];
PHP
cat > "$W/kid/config/config.php" <<'PHP'
<?php
$child_db_host = 'h'; $child_db_name = 'kid'; $child_db_user = 'kid_user'; $child_db_pass = 'child-pw';
$childShardConfigs = ['shard1' => ['host' => 'h', 'name' => 'kid_s1', 'user' => 'u', 'pass' => 'child-shard-pw']];
PHP
POINT=$(cat /etc/p3sig/keyserver.pub)
echo "--- seal (names only)"
php "$A/scripts/p3sig-keyserver-seal.php" --pub "$POINT" --child "$W/kid/config/config.php" --out "$OUT/cache.enc"
if php "$A/scripts/p3sig-keyserver-seal.php" --pub "$POINT" --out "$W/cache.enc" 2>/dev/null; then echo "FAIL wrote inside the web root"; else echo "PASS refuses to write inside the web root"; fi
grep -q 'db-pw-ROOT' "$OUT/cache.enc" && echo "FAIL plaintext in cache.enc" || echo "PASS no plaintext in cache.enc"
KSID=$(python3 -c "import json;print(list(json.load(open('$OUT/cache.enc'))['wraps'])[0])")
read SK PK < <(php -r '$k=sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k))," ",base64_encode(sodium_crypto_sign_publickey($k)),"\n";')
echo "$SK" > "$OUT/app.key"; chmod 600 "$OUT/app.key"
APP=roots-test-$$
php -r 'file_put_contents($argv[1], "<?php return " . var_export(["app_id" => $argv[2], "key_file" => $argv[3] . "/app.key", "cache_file" => $argv[3] . "/cache.enc", "keyservers" => [["id" => $argv[4], "url" => "http://127.0.0.1:8787/v1/unwrap"]]], true) . ";");' "$OUT/p3sig.php" "$APP" "$OUT" "$KSID"
sudo cp -p /etc/p3sig/keyserver.json "$OUT/ks.bak"
trap 'sudo cp -p "$OUT/ks.bak" /etc/p3sig/keyserver.json; sudo systemctl reload p3sig-keyserver; rm -rf "$W" "$OUT"' EXIT
sudo python3 -c "import json;p='/etc/p3sig/keyserver.json';c=json.load(open(p));c['apps']['$APP']={'key':'$PK','ips':['127.0.0.1']};json.dump(c,open(p,'w'),indent=2)"
sudo systemctl reload p3sig-keyserver; sleep 1
echo "--- roots through the key server, config.php blanked"
php -d error_log="$OUT/err.log" -r '
define("WN_P3SIG_CONFIG", $argv[1] . "/p3sig.php"); define("WN_P3SIG_WEBROOT", $argv[2]);
$dbPassword = ""; $hiddenhash = ""; $app_secret = ""; $smtp_pass = "";
$shardConfigs = ["shard1" => ["host" => "h", "name" => "x_s1", "user" => "u", "pass" => ""]];
require $argv[2] . "/admin/include/common/runtimeSecretFunctions.php";
require $argv[2] . "/admin/include/common/keyRingFunctions.php";
wn_apply_runtime_secrets();
wn_key_ring_init(true);
$u = "kid_user"; $p = ""; $cs = ["shard1" => ["pass" => ""]];
wn_apply_child_root_secrets($u, $p, $cs);
$t = function ($n, $ok) { echo ($ok ? "PASS " : "FAIL "), $n, "\n"; };
$t("DB password", $dbPassword === "db-pw-ROOT");
$t("app_secret ring + current (2e picks k2)", ($app_secrets["k1"] ?? "") === "app-old" && $app_secret === "app-k2-NEW");
$t("pepper ring + current (2e picks p2)", ($peppers["p1"] ?? "") === "pepper-old" && $hiddenhash === "pepper-p2-NEW");
$t("seed shard password", $shardConfigs["shard1"]["pass"] === "shard1-pw");
$t("service key", $smtp_pass === "smtp-pw");
$t("empty value not carried", !isset(wn_p3sig_cache_values()["STRIPE_SECRET_KEY"]));
$t("child DB password + user", $p === "child-pw" && $u === "kid_user");
$t("child seed shard password", $cs["shard1"]["pass"] === "child-shard-pw");
' "$OUT" "$W"
[ -s "$OUT/err.log" ] && { echo "--- php error log"; cat "$OUT/err.log"; }

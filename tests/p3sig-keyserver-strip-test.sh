#!/bin/bash
# Migration step 3 and its rollback (scripts/p3sig-keyserver-strip.php), end to end on the
# key-server machine: seal a fake deployment, strip config.php, check the app still gets
# every value through the key server, restore, check config.php holds them again.
set -u
CORE=$(cd "$(dirname "$0")/.." && pwd)
W=$(mktemp -d); OUT=$(mktemp -d)
A="$W/admin"; mkdir -p "$A/include/common" "$A/scripts" "$A/config" "$W/kid/config"
cp "$CORE/include/common/runtimeSecretFunctions.php" "$CORE/include/common/keyRingFunctions.php" "$A/include/common/"
cp "$CORE/scripts/p3sig-keyserver-seal.php" "$CORE/scripts/p3sig-keyserver-strip.php" "$A/scripts/"
cat > "$A/config/config.php" <<PHP
<?php
// database
\$dbHostSpec = 'localhost'; \$dbInstance = 'x_main';
\$dbUserName = "x_user";
\$dbPassword = 'db-pw-ROOT';  // main
\$hiddenhash = 'pepper-old';
\$app_secrets = ['k2' => 'app-k2-NEW', 'k1' => 'app-old'];
\$app_secret_current = 'k2';
\$smtp_host = 'smtp.example'; \$smtp_port = 587; \$smtp_pass = 'smtp-pw';
\$files_location = __DIR__ . '/../../../files/';
\$shardConfigs = [
    'shard1' => ['host' => 'h1', 'name' => 'x_s1', 'user' => 'u', 'pass' => 'shard1-pw'],
    'shard2' => array('host' => 'h2', 'name' => 'x_s2', 'user' => 'u', 'pass' => "shard2-pw"),
];
define('WN_P3SIG_CONFIG', '$OUT/p3sig.php');
?>
PHP
cat > "$W/kid/config/config.php" <<'PHP'
<?php
$child_db_host = 'h'; $child_db_name = 'kid'; $child_db_user = 'kid_user'; $child_db_pass = 'child-pw';
$childShardConfigs = ['shard1' => ['host' => 'h', 'name' => 'kid_s1', 'user' => 'u', 'pass' => 'child-shard-pw']];
PHP
cp "$A/config/config.php" "$OUT/admin.orig"; cp "$W/kid/config/config.php" "$OUT/kid.orig"
POINT=$(cat /etc/p3sig/keyserver.pub)
php "$A/scripts/p3sig-keyserver-seal.php" --pub "$POINT" --child "$W/kid/config/config.php" --out "$OUT/cache.enc" | sed 's/^/seal: /'
KSID=$(python3 -c "import json;print(list(json.load(open('$OUT/cache.enc'))['wraps'])[0])")
read SK PK < <(php -r '$k=sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k))," ",base64_encode(sodium_crypto_sign_publickey($k)),"\n";')
echo "$SK" > "$OUT/app.key"; chmod 600 "$OUT/app.key"
APP=strip-test-$$
php -r 'file_put_contents($argv[1], "<?php return " . var_export(["app_id" => $argv[2], "key_file" => $argv[3] . "/app.key", "cache_file" => $argv[3] . "/cache.enc", "keyservers" => [["id" => $argv[4], "url" => "http://127.0.0.1:8787/v1/unwrap"]]], true) . ";");' "$OUT/p3sig.php" "$APP" "$OUT" "$KSID"
sudo cp -p /etc/p3sig/keyserver.json "$OUT/ks.bak"
trap 'sudo cp -p "$OUT/ks.bak" /etc/p3sig/keyserver.json; sudo systemctl reload p3sig-keyserver; for s in $(ipcs -m | awk "\$3==\"$USER\" && \$6==0 {print \$2}"); do ipcrm -m $s 2>/dev/null; done; rm -rf "$W" "$OUT"' EXIT
sudo python3 -c "import json;p='/etc/p3sig/keyserver.json';c=json.load(open(p));c['apps']['$APP']={'key':'$PK','ips':['127.0.0.1']};json.dump(c,open(p,'w'),indent=2)"
sudo systemctl reload p3sig-keyserver; sleep 1
VARS='$v = (function ($f) { include $f; unset($f); return get_defined_vars(); })($argv[1]); unset($v["files_location"]); echo json_encode($v);'
echo "--- strip"
php "$A/scripts/p3sig-keyserver-strip.php" --child "$W/kid/config/config.php"
t() { if [ "$2" = 1 ]; then echo "PASS $1"; else echo "FAIL $1"; fi; }
t "no secret left in admin config" "$(grep -c -E 'db-pw-ROOT|pepper-old|app-k2-NEW|smtp-pw|shard1-pw|shard2-pw' "$A/config/config.php" | grep -qx 0 && echo 1)"
t "no secret left in child config" "$(grep -c -E 'child-pw|child-shard-pw' "$W/kid/config/config.php" | grep -qx 0 && echo 1)"
t "non-secrets untouched (DB host/name, shard names, files_location, define)" "$(grep -q "dbInstance = 'x_main'" "$A/config/config.php" && grep -q "files_location = __DIR__" "$A/config/config.php" && grep -q "'name' => 'x_s2'" "$A/config/config.php" && grep -q WN_P3SIG_CONFIG "$A/config/config.php" && echo 1)"
php -d error_log="$OUT/err.log" -r '
define("WN_P3SIG_WEBROOT", $argv[2]);
include $argv[2] . "/admin/config/config.php";
require $argv[2] . "/admin/include/common/runtimeSecretFunctions.php";
require $argv[2] . "/admin/include/common/keyRingFunctions.php";
wn_apply_runtime_secrets(); wn_key_ring_init(true);
include $argv[2] . "/kid/config/config.php";
wn_apply_child_root_secrets($child_db_user, $child_db_pass, $childShardConfigs);
$ok = $dbPassword === "db-pw-ROOT" && $app_secret === "app-k2-NEW" && $hiddenhash === "pepper-old" && $smtp_port === 587
   && $smtp_pass === "smtp-pw" && $shardConfigs["shard2"]["pass"] === "shard2-pw" && $child_db_pass === "child-pw"
   && $childShardConfigs["shard1"]["pass"] === "child-shard-pw";
echo ($ok ? "PASS" : "FAIL"), " stripped app gets every value through the key server\n";
' "$OUT" "$W"
echo "--- strip again (idempotent)"; php "$A/scripts/p3sig-keyserver-strip.php" --child "$W/kid/config/config.php" 2>&1 | grep -v "already defined"
echo "--- restore"
php "$A/scripts/p3sig-keyserver-strip.php" --restore --child "$W/kid/config/config.php"
t "admin config holds the same values as before" "$( [ "$(php -r "$VARS" "$A/config/config.php")" = "$(php -r "$VARS" "$OUT/admin.orig")" ] && echo 1)"
t "child config holds the same values as before" "$( [ "$(php -r "$VARS" "$W/kid/config/config.php")" = "$(php -r "$VARS" "$OUT/kid.orig")" ] && echo 1)"
echo "--- a bundle that disagrees with config.php refuses to strip"
sed -i "s/'smtp-pw'/'smtp-pw-CHANGED'/" "$A/config/config.php"
php "$A/scripts/p3sig-keyserver-strip.php" --child "$W/kid/config/config.php" 2>&1 | sed 's/^/  /'
t "nothing emptied after refusal" "$(grep -q db-pw-ROOT "$A/config/config.php" && echo 1)"
[ -s "$OUT/err.log" ] && { echo "--- php error log"; cat "$OUT/err.log"; }

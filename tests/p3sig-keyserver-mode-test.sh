#!/bin/bash
# Key-server mode end to end, on a machine running `p3sig keyserver serve` as the
# p3sig-keyserver service (packaging/keyserver in p3sig-keyserver). Needs sudo to
# allowlist a temporary app; restores the allowlist afterwards.
set -u
CORE=$(cd "$(dirname "$0")/.." && pwd)
DIR=$(mktemp -d); mkdir "$DIR/public_html"
POINT=$(cat /etc/p3sig/keyserver.pub)
KSID=$(printf %s "$POINT" | base64 -d | sha256sum | cut -d' ' -f1 | xxd -r -p | base64 | tr '+/' '-_' | cut -c1-16)
echo '{"STRIPE_SECRET_KEY":"sk_test_core"}' > "$DIR/v.json"
p3sig keyserver seal --pub "$POINT" --in "$DIR/v.json" --out "$DIR/cache.enc"; rm "$DIR/v.json"
read SK PK < <(php -r '$k=sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k))," ",base64_encode(sodium_crypto_sign_publickey($k)),"\n";')
echo "$SK" > "$DIR/app.key"; chmod 600 "$DIR/app.key"
APP=core-test-$$
sudo cp -p /etc/p3sig/keyserver.json "$DIR/ks.bak"
trap 'sudo cp -p "$DIR/ks.bak" /etc/p3sig/keyserver.json; sudo systemctl reload p3sig-keyserver; rm -rf "$DIR"' EXIT
sudo python3 -c "import json;p='/etc/p3sig/keyserver.json';c=json.load(open(p));c['apps']['$APP']={'key':'$PK','ips':['127.0.0.1']};json.dump(c,open(p,'w'),indent=2)"
sudo systemctl reload p3sig-keyserver; sleep 1
T="$CORE/tests/p3sig-keyserver-mode-test.php"
php "$T" "$DIR" "$APP" "$KSID" http://127.0.0.1:8787/v1/unwrap cold
php "$T" "$DIR" "$APP" "$KSID" http://127.0.0.1:9/v1/unwrap warm
php "$T" "$DIR" "$APP" "$KSID" http://127.0.0.1:9/v1/unwrap stale-down
php "$T" "$DIR" "$APP" "$KSID" http://127.0.0.1:9/v1/unwrap cold-down
echo "--- php error log"; sed 's/^\[[^]]*\] //' "$DIR/php-error.log" 2>/dev/null

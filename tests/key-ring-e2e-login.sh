#!/bin/bash
# Run from the admin root after: docker compose -p wnring -f docker-compose.yml -f tests/key-ring-e2e.compose.yml up -d --build
# End-to-end: a user whose hash predates the key ring signs in through the real login
# action; the hash is upgraded to $wn2$p1$; signing in again works; a wrong password fails.
set -u
P="docker compose -p wnring -f docker-compose.yml -f tests/key-ring-e2e.compose.yml"
B=http://127.0.0.1:18080/admin
J=$(mktemp -d)
SQL() { $P exec -T db mysql -uroot -pdev_root_password_123 admin_standalone_main -N -e "$1" 2>/dev/null; }
APPPHP() { $P exec -T app php -r "$1" 2>/dev/null; }

curl -s -o /dev/null -w "first request (runs migrations): %{http_code}\n" "$B/auth/login.php"
$P exec -T app php /var/www/public_html/admin/scripts/create_test_user.php 2>&1 | tail -1
EMAIL=nokemo@nokemo.com; PW='Correct-Horse-1!'
LEGACY=$(APPPHP "echo password_hash('$PW' . getenv('HIDDEN_HASH'), PASSWORD_BCRYPT);")
SQL "UPDATE user SET password='$LEGACY', is_confirmed=1, totp_enabled=0 WHERE email='$EMAIL'"
echo "stored before: $(SQL "SELECT LEFT(password,8) FROM user WHERE email='$EMAIL'")"

login() { rm -f $J/c; curl -s -c $J/c -b $J/c -o $J/body -w "%{http_code} %{redirect_url}" "$B/auth/login.php" \
  --data-urlencode action=login --data-urlencode "email=$EMAIL" --data-urlencode "password=$1"; }
echo "login wrong password: $(login 'nope') $(grep -o -i 'Invalid email or password' $J/body | head -1)"
echo "stored after wrong:  $(SQL "SELECT LEFT(password,8) FROM user WHERE email='$EMAIL'")"
echo "login legacy hash:   $(login "$PW")"
echo "stored after login:  $(SQL "SELECT LEFT(password,8) FROM user WHERE email='$EMAIL'")"
echo "dashboard w/ session: $(curl -s -b $J/c -o /dev/null -w '%{http_code} %{redirect_url}' "$B/app/index.php")"
echo "login again (v2):    $(login "$PW")"
echo "login wrong (v2):    $(login 'Correct-Horse-2!') $(grep -o -i 'Invalid email or password' $J/body | head -1)"
echo "sealed smtp test:    $(APPPHP "require '/var/www/public_html/admin/include/common_readonly.php'; \$s=wn_secret_seal('x-pass'); echo substr(\$s,0,10), ' opens=', var_export(wn_secret_open(\$s)==='x-pass',true);")"
echo "jwt roundtrip:       $(APPPHP "require '/var/www/public_html/admin/include/common_readonly.php'; \$t=jwt_issue(5,60); echo var_export((int)(jwt_verify(\$t)->sub ?? 0)===5,true);")"
echo "rotate status:"; $P exec -T app php /var/www/public_html/admin/scripts/rotate-secret-key.php status 2>&1 | sed 's/^/  /'
rm -rf $J

#!/usr/bin/env bash
# Round trip for include/common/backupFunctions.php + scripts/wn-backup.php against a real
# MariaDB in Docker: dump two databases + a files tree encrypted to a fresh key, restore,
# import into NEW databases and compare every table (CHECKSUM TABLE + row counts) and every
# file (sha256). Then the refusals: wrong key, a flipped byte, a truncated file, a backup
# with no key configured, keygen over an existing file.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"; NAME="wnbk-test-$$"; PORT=$((20000 + RANDOM % 10000))
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$WORK"; }
trap cleanup EXIT
pass() { echo "PASS $*"; }
die()  { echo "FAIL $*"; exit 1; }

docker run -d --name "$NAME" -e MARIADB_ROOT_PASSWORD=rootpw -p 127.0.0.1:$PORT:3306 mariadb:10.11 >/dev/null
for i in $(seq 1 60); do docker exec "$NAME" mariadb -uroot -prootpw -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
SQL() { docker exec -i "$NAME" mariadb -uroot -prootpw "$@"; }

SQL <<'SQL'
CREATE DATABASE t_admin; CREATE DATABASE t_child;
USE t_admin;
CREATE TABLE parent (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE child (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT NOT NULL, note TEXT NULL, raw BLOB NULL,
  amount DECIMAL(12,4) NULL, at DATETIME NULL, doc JSON NULL, zero_id INT NOT NULL DEFAULT 0,
  name_len INT AS (CHAR_LENGTH(note)) VIRTUAL, FOREIGN KEY (parent_id) REFERENCES parent(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE empty_one (id INT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE zero_ai (id INT AUTO_INCREMENT PRIMARY KEY, v INT) ENGINE=InnoDB;
SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'; INSERT INTO zero_ai VALUES (0, 7), (5, 8);
INSERT INTO parent (name) VALUES ('Zoë 🐙 O''Brien'), ('back\\slash "quote"'), ('line1\nline2\r\n(tab\t)'), ('');
INSERT INTO child (parent_id, note, raw, amount, at, doc) VALUES
 (1, 'emoji 👨‍👩‍👧 family', 0x00FF10E2289A00, 12.3456, '2026-10-19 01:02:03', '{"a":[1,2,{"b":"c"}]}'),
 (2, NULL, NULL, NULL, NULL, NULL),
 (3, '(starts with paren\n(next line paren', 0x28, -0.0001, '1970-01-01 00:00:01', '"x"');
CREATE VIEW parent_names AS SELECT id, name FROM parent;
USE t_child;
CREATE TABLE big (id INT AUTO_INCREMENT PRIMARY KEY, payload MEDIUMTEXT, bin VARBINARY(64)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
# >1 MB of rows so frames and INSERT batches split.
SQL t_child -e "INSERT INTO big (payload, bin) SELECT REPEAT(CONCAT('row ', seq, ' ünïcode '), 40), UNHEX(SHA2(seq,256)) FROM seq_1_to_3000;"
pass "seeded two databases"

# Files tree: nested, binary, empty, a sessions dir (skipped) and a symlink (skipped).
F="$WORK/files"; mkdir -p "$F/home/a/b" "$F/sessions" "$F/branding"
head -c 2500000 /dev/urandom > "$F/home/a/b/photo.bin"; : > "$F/home/empty.txt"
printf 'logo' > "$F/branding/logo.svg"; printf 'sess' > "$F/sessions/sess_abc"; ln -s /etc/passwd "$F/home/link"

cat > "$WORK/run.php" <<PHP
<?php
require '$ROOT/include/common/backupFunctions.php';
\$GLOBALS['files_location'] = '$F/';
\$GLOBALS['backup_dir'] = '$WORK/backups';
\$GLOBALS['backup_slug'] = 'testapp';
\$GLOBALS['dbInstance'] = 't_admin'; \$GLOBALS['dbHostSpec'] = '127.0.0.1;port=$PORT';
\$GLOBALS['dbUserName'] = 'root'; \$GLOBALS['dbPassword'] = 'rootpw';
function db_size_known_databases() { return ['t_admin' => null,
  't_child' => ['host' => '127.0.0.1;port=$PORT', 'name' => 't_child', 'user' => 'root', 'pass' => 'rootpw']]; }
if ((\$argv[1] ?? '') === 'pub') { \$GLOBALS['backup_public_key'] = trim(file_get_contents('$WORK/pub')); }
try { \$m = wn_backup_run(2); echo wn_backup_summary(\$m), "\n"; } catch (Throwable \$e) { echo "ERR ", \$e->getMessage(), "\n"; exit(3); }
PHP

# No key → refused, nothing written.
out=$(php "$WORK/run.php" || true); [[ "$out" == *"backups are off"* ]] || die "no-key run: $out"
pass "no public key = backups off"

php "$ROOT/scripts/wn-backup.php" keygen --out "$WORK/secret.txt" > "$WORK/keygen.out"
sed -n 3p "$WORK/keygen.out" > "$WORK/pub"
[[ "$(stat -c %a "$WORK/secret.txt")" == 600 ]] || die "secret not 0600"
grep -q wnbk-secret "$WORK/keygen.out" && die "keygen printed the secret"
php "$ROOT/scripts/wn-backup.php" keygen --out "$WORK/secret.txt" >/dev/null 2>&1 && die "keygen overwrote"
pass "keygen: 0600 secret, prints only the public key, refuses to overwrite"

php "$WORK/run.php" pub
BK=$(ls "$WORK"/backups/*.wnbk); JS="${BK%.wnbk}.json"
{ grep -qF "Brien" "$BK" || grep -qF "family" "$BK"; } && die "plaintext visible in backup"
grep -qF "row 12 ünïcode" "$BK" && die "plaintext visible in backup"
[[ "$(sha256sum "$BK" | cut -d' ' -f1)" == "$(php -r "echo json_decode(file_get_contents('$JS'),true)['ct_sha256'];")" ]] || die "manifest sha mismatch"
[[ -z "$(ls "$WORK"/backups/*.partial 2>/dev/null)" ]] || die "partial left behind"
pass "backup written, no plaintext, manifest sha256 matches"

php "$ROOT/scripts/wn-backup.php" verify --key "$WORK/secret.txt" --in "$BK" | tail -1 | grep -q "verify OK" || die verify
pass "verify OK"

php "$ROOT/scripts/wn-backup.php" restore --key - --in "$BK" --out "$WORK/r" < "$WORK/secret.txt" | tail -1
for db in t_admin t_child; do
  SQL -e "CREATE DATABASE r_$db"; SQL "r_$db" < "$WORK/r/$db.sql"
done
for pair in t_admin:parent t_admin:child t_admin:empty_one t_admin:zero_ai t_child:big; do
  db=${pair%%:*}; t=${pair##*:}
  a=$(SQL -N -e "CHECKSUM TABLE $db.$t" | awk '{print $2}'); b=$(SQL -N -e "CHECKSUM TABLE r_$db.$t" | awk '{print $2}')
  ca=$(SQL -N -e "SELECT COUNT(*) FROM $db.$t"); cb=$(SQL -N -e "SELECT COUNT(*) FROM r_$db.$t")
  [[ "$a" == "$b" && "$ca" == "$cb" ]] || die "$db.$t differs ($ca/$cb rows, checksum $a/$b)"
done
[[ "$(SQL -N -e 'SELECT COUNT(*) FROM r_t_admin.parent_names')" == 4 ]] || die "view not restored"
[[ "$(SQL -N -e 'SELECT name_len FROM r_t_admin.child WHERE id=1')" == "$(SQL -N -e 'SELECT name_len FROM t_admin.child WHERE id=1')" ]] || die "generated column"
pass "restored databases identical (checksums, row counts, view, generated column, NO_AUTO_VALUE_ON_ZERO)"

(cd "$F" && find . -type f ! -path './sessions/*' -exec sha256sum {} \; | sort) > "$WORK/a.sum"
(cd "$WORK/r/files" && find . -type f -exec sha256sum {} \; | sort) > "$WORK/b.sum"
diff -q "$WORK/a.sum" "$WORK/b.sum" >/dev/null || die "files differ"
[[ ! -e "$WORK/r/files/sessions" && ! -e "$WORK/r/files/home/link" ]] || die "sessions or symlink included"
pass "files identical; sessions/ and symlinks skipped"

php "$ROOT/scripts/wn-backup.php" keygen --out "$WORK/other.txt" >/dev/null
php "$ROOT/scripts/wn-backup.php" verify --key "$WORK/other.txt" --in "$BK" >/dev/null 2>&1 && die "wrong key accepted"
cp "$BK" "$WORK/flip.wnbk"; size=$(stat -c %s "$BK")
printf '\x55' | dd of="$WORK/flip.wnbk" bs=1 seek=$((size / 2)) conv=notrunc 2>/dev/null
php "$ROOT/scripts/wn-backup.php" verify --key "$WORK/secret.txt" --in "$WORK/flip.wnbk" >/dev/null 2>&1 && die "flipped byte accepted"
head -c $((size - 100)) "$BK" > "$WORK/trunc.wnbk"
php "$ROOT/scripts/wn-backup.php" verify --key "$WORK/secret.txt" --in "$WORK/trunc.wnbk" >/dev/null 2>&1 && die "truncated accepted"
# Cut exactly at a frame boundary (drop the final manifest frame).
php -r '$f=file_get_contents($argv[1]); $p=strpos($f,"\n",6)+1; $last=$p; while($p<strlen($f)){ $last=$p; $p+=4+unpack("N",substr($f,$p,4))[1]; } file_put_contents($argv[2], substr($f,0,$last));' "$BK" "$WORK/cut.wnbk"
php "$ROOT/scripts/wn-backup.php" verify --key "$WORK/secret.txt" --in "$WORK/cut.wnbk" >/dev/null 2>&1 && die "frame-boundary truncation accepted"
pass "refused: wrong key, flipped byte, truncated file, missing final frame"

php "$ROOT/scripts/wn-backup.php" restore --key "$WORK/secret.txt" --in "$BK" --out "$WORK/r" >/dev/null 2>&1 && die "restore over existing dir"
pass "restore refuses an existing directory"

sleep 1; php "$WORK/run.php" pub >/dev/null; sleep 1; php "$WORK/run.php" pub >/dev/null
[[ $(ls "$WORK"/backups/*.wnbk | wc -l) == 2 && $(ls "$WORK"/backups/*.json | wc -l) == 2 ]] || die "local retention"
pass "local retention keeps the newest 2"
echo "ALL PASS"

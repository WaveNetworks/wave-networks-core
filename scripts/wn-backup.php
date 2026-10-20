<?php
/**
 * wn-backup.php — encrypted backups (include/common/backupFunctions.php).
 *
 * On the host (bootstraps the app):
 *   php scripts/wn-backup.php run        make a backup now (also what the cron spawns)
 *   php scripts/wn-backup.php stream --pub B64
 *       Write the encrypted backup to STDOUT (nothing on the host's disk) and its plain
 *       manifest as the last STDERR line: "WNBK-MANIFEST {json}". Started over SSH by the
 *       backup box on hosts whose cron kills long jobs. The public key comes on the command
 *       line (it is public); the host needs no backup.pub for this.
 *   php scripts/wn-backup.php status     is a key set, where, the newest backup
 *
 * Anywhere with php + sodium (no app config needed):
 *   php scripts/wn-backup.php keygen --out FILE
 *       Writes the SECRET line to FILE (mode 0600, refuses to overwrite) and prints only the
 *       public key. Put the secret in your p3sig vault and an offline copy, then delete FILE.
 *   php scripts/wn-backup.php verify  --key FILE --in X.wnbk
 *       Decrypts everything in memory, checks every frame and the row/file counts against
 *       the manifest. Writes nothing; prints counts only.
 *   php scripts/wn-backup.php restore --key FILE --in X.wnbk --out DIR
 *       Writes DIR/<database>.sql, DIR/files/..., DIR/manifest.json. Load a database with
 *       mysql <name> < DIR/<name>.sql. DIR must not exist yet.
 * The key FILE holds the secret line; "-" reads it from stdin. It is never printed.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }

$cmd = $argv[1] ?? '';
$opt = [];
for ($i = 2; $i < count($argv); $i++) {
    if (substr($argv[$i], 0, 2) === '--') {
        $k = substr($argv[$i], 2);
        $opt[$k] = (isset($argv[$i + 1]) && substr($argv[$i + 1], 0, 2) !== '--') ? $argv[++$i] : true;
    }
}
function fail($msg) { fwrite(STDERR, "wn-backup: $msg\n"); exit(1); }

if ($cmd === 'stream') {
    // STDOUT is the backup: warnings go to STDERR, and anything the bootstrap prints is moved there.
    ini_set('display_errors', 'stderr');
    ob_start();
    include __DIR__ . '/../include/common_readonly.php';
    $junk = ob_get_clean();
    if ($junk !== '' && $junk !== false) { fwrite(STDERR, $junk); }
} elseif ($cmd === 'run' || $cmd === 'status') {
    include __DIR__ . '/../include/common_readonly.php';
} else {
    require_once __DIR__ . '/../include/common/backupFunctions.php';
}

function load_keypair($opt) {
    $src = $opt['key'] ?? null;
    if (!is_string($src)) { fail('--key FILE is required (- for stdin)'); }
    $line = $src === '-' ? stream_get_contents(STDIN) : @file_get_contents($src);
    if ($line === false) { fail("cannot read $src"); }
    $kp = wn_backup_parse_secret($line);
    if (!$kp) { fail('that is not a backup secret (expected one line starting ' . WN_BACKUP_SECRET_PREFIX . ')'); }
    return $kp;
}

/** Walk a backup; $sink(kind, payload) gets each record. Checks counts against the manifest. */
function walk_backup($in, $kp, callable $sink) {
    $rows = []; $files = 0; $fileBytes = 0; $cur = null; $curBytes = 0; $sizeBad = 0;
    $buf = '';
    $flushSql = function ($final) use (&$buf, &$rows, &$cur) {
        // Count tuples per table from complete lines; keep the incomplete tail.
        $cut = $final ? strlen($buf) : strrpos($buf, "\n");
        if ($cut === false) { return; }
        $done = substr($buf, 0, $cut); $buf = (string) substr($buf, $cut);
        static $table = null;
        foreach (explode("\n", $done) as $ln) {
            if (strncmp($ln, 'INSERT INTO `', 13) === 0) {
                $table = str_replace('``', '`', substr($ln, 13, strpos($ln, '` (', 13) - 13));
            } elseif ($table !== null && $ln !== '' && $ln[0] === '(') {
                $rows[$cur['name']][$table] = ($rows[$cur['name']][$table] ?? 0) + 1;
                if (substr($ln, -1) === ';') { $table = null; }
            }
        }
    };
    $close = function () use (&$cur, &$curBytes, &$sizeBad, &$flushSql) {
        if ($cur && $cur['type'] === 'db') { $flushSql(true); }
        if ($cur && $cur['type'] === 'file' && $curBytes !== (int) $cur['size']) { $sizeBad++; }
    };
    $manifest = wn_backup_read($in, $kp, function ($kind, $p) use (&$cur, &$curBytes, &$files, &$fileBytes, &$buf, $sink, $close, $flushSql) {
        if ($kind === 'h') {
            $close();
            if (!is_array($p) || !in_array($p['type'] ?? '', ['db', 'file'], true)) { throw new RuntimeException('bad entry header'); }
            $cur = $p; $curBytes = 0; $buf = '';
            if ($p['type'] === 'file') { $files++; }
        } elseif ($kind === 'd') {
            if (!$cur) { throw new RuntimeException('data before any entry'); }
            $curBytes += strlen($p);
            if ($cur['type'] === 'db') { $buf .= $p; $flushSql(false); } else { $fileBytes += strlen($p); }
        } else {
            $close(); $cur = null;
        }
        $sink($kind, $p);
    });
    $problems = [];
    foreach ((array) ($manifest['databases'] ?? []) as $db => $info) {
        foreach ((array) ($info['tables'] ?? []) as $t => $n) {
            $got = $rows[$db][$t] ?? 0;
            if ($got !== (int) $n) { $problems[] = "$db.$t: manifest $n rows, backup has $got"; }
        }
    }
    if ($manifest['files'] !== null && ((int) $manifest['files']['count'] !== $files || (int) $manifest['files']['bytes'] !== $fileBytes)) {
        $problems[] = "files: manifest {$manifest['files']['count']} / {$manifest['files']['bytes']}B, backup has $files / {$fileBytes}B";
    }
    if ($sizeBad) { $problems[] = "$sizeBad file(s) differ from their recorded size"; }
    return [$manifest, $problems];
}

function print_counts($m) {
    $t = 0; $r = 0;
    foreach ((array) $m['databases'] as $d) { $t += count($d['tables']); $r += array_sum($d['tables']); }
    printf("  app %s, made %s, key %s\n  %d databases, %d tables, %d rows, %d files (%.1f MB)\n",
        $m['app'], $m['created'], $m['key_id'], count($m['databases']), $t, $r,
        $m['files']['count'] ?? 0, ($m['files']['bytes'] ?? 0) / 1048576);
}

switch ($cmd) {
case 'keygen':
    $out = $opt['out'] ?? null;
    if (!is_string($out)) { fail('--out FILE is required'); }
    if (file_exists($out)) { fail("$out exists; refusing to overwrite a key"); }
    [$secret, $pub] = wn_backup_keygen();
    $old = umask(077);
    $ok = file_put_contents($out, $secret . "\n", LOCK_EX);
    umask($old);
    if ($ok === false) { fail("cannot write $out"); }
    chmod($out, 0600);
    echo "Secret written to $out (0600). Put it in your p3sig vault and an offline copy, then delete the file.\n";
    echo "Public key (goes on the host as backup.pub or \$backup_public_key):\n$pub\n";
    echo "Key id: " . wn_backup_key_id(base64_decode($pub)) . "\n";
    break;

case 'verify':
    $kp = load_keypair($opt);
    $in = $opt['in'] ?? null;
    if (!is_string($in)) { fail('--in X.wnbk is required'); }
    try {
        [$m, $problems] = walk_backup($in, $kp, function () {});
    } catch (Throwable $e) { fail('verify FAILED: ' . $e->getMessage()); }
    sodium_memzero($kp);
    print_counts($m);
    if ($problems) { echo "verify FAILED:\n  " . implode("\n  ", $problems) . "\n"; exit(1); }
    echo "verify OK: every frame authenticated, counts match the manifest.\n";
    break;

case 'restore':
    $kp = load_keypair($opt);
    $in = $opt['in'] ?? null; $out = $opt['out'] ?? null;
    if (!is_string($in) || !is_string($out)) { fail('--in X.wnbk and --out DIR are required'); }
    if (file_exists($out)) { fail("$out exists; restore into a new directory"); }
    $old = umask(077);
    mkdir($out . '/files', 0700, true);
    $out = realpath($out);
    $fh = null;
    try {
        [$m, $problems] = walk_backup($in, $kp, function ($kind, $p) use (&$fh, $out) {
            if ($kind === 'h') {
                if ($fh) { fclose($fh); $fh = null; }
                if ($p['type'] === 'db') {
                    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $p['name']);
                    $fh = fopen("$out/$name.sql", 'xb');
                } else {
                    $rel = (string) $p['path'];
                    if ($rel === '' || $rel[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $rel) || strpos($rel, "\0") !== false) {
                        throw new RuntimeException('refusing unsafe path in backup');
                    }
                    $dest = "$out/files/$rel";
                    if (!is_dir(dirname($dest))) { mkdir(dirname($dest), 0700, true); }
                    $fh = fopen($dest, 'xb');
                    if ($fh && !empty($p['mtime'])) { register_shutdown_function('touch', $dest, (int) $p['mtime']); }
                }
                if (!$fh) { throw new RuntimeException('cannot write restore output'); }
            } elseif ($kind === 'd') {
                fwrite($fh, $p);
            } else {
                if ($fh) { fclose($fh); $fh = null; }
                file_put_contents("$out/manifest.json", json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }
        });
    } catch (Throwable $e) { fail('restore FAILED: ' . $e->getMessage() . " (partial output left in $out)"); }
    umask($old);
    sodium_memzero($kp);
    print_counts($m);
    if ($problems) { echo "restore FAILED checks:\n  " . implode("\n  ", $problems) . "\n"; exit(1); }
    echo "restore OK into $out: one .sql per database, files/ tree, manifest.json.\n";
    break;

case 'run':
    try {
        $m = wn_backup_run((int) ($backup_keep_local ?? 3));
    } catch (Throwable $e) {
        if (function_exists('log_error_to_db')) { log_error_to_db('ERROR', 'Backup failed: ' . $e->getMessage(), __FILE__, __LINE__, null); }
        if (function_exists('log_cron')) { log_cron('backup', 'FAILED: ' . $e->getMessage()); }
        fail($e->getMessage());
    }
    $line = wn_backup_summary($m);
    if (function_exists('log_cron')) { log_cron('backup', $line); }
    echo "backup OK $line\n";
    break;

case 'stream':
    $pk = wn_backup_parse_public(is_string($opt['pub'] ?? null) ? $opt['pub'] : '');
    if (!$pk) { fail('--pub <base64 public key> is required'); }
    try {
        $m = wn_backup_stream($pk);
    } catch (Throwable $e) {
        fwrite(STDERR, 'WNBK-FAILED ' . $e->getMessage() . "\n");
        if (function_exists('log_error_to_db')) { log_error_to_db('ERROR', 'Backup stream failed: ' . $e->getMessage(), __FILE__, __LINE__, null); }
        exit(1);
    }
    if (function_exists('log_cron')) { log_cron('backup', 'streamed ' . wn_backup_summary($m)); }
    fwrite(STDERR, 'WNBK-MANIFEST ' . json_encode($m, JSON_UNESCAPED_SLASHES) . "\n");
    break;

case 'status':
    $pk = wn_backup_public_key();
    echo 'backups: ' . ($pk ? 'ON, key ' . wn_backup_key_id($pk) : 'off (no backup public key)') . "\n";
    echo 'dir: ' . (wn_backup_dir() ?? '(none)') . "\n";
    $m = wn_backup_latest();
    echo 'newest: ' . ($m ? wn_backup_summary($m) : 'none') . "\n";
    break;

default:
    fwrite(STDERR, "usage: wn-backup.php run | status | keygen --out FILE | verify --key FILE --in X.wnbk | restore --key FILE --in X.wnbk --out DIR\n");
    exit(2);
}

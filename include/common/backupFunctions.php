<?php
/**
 * backupFunctions.php — encrypted backups the host can write but never read.
 *
 * A deployment's databases (admin main + shards, every sibling child app's main + shards,
 * every shard_config row) and its $files_location are streamed into ONE file encrypted to a
 * backup PUBLIC key. The host holds only that public key, so neither the host, nor whoever
 * copies the files off it, nor the box that keeps them can open a backup. The private key
 * lives with the owner (their p3sig vault + an offline copy); restoring needs it, nothing
 * else does. No key server is involved.
 *
 * Off by default: nothing runs until a public key is configured, as either
 *   $backup_public_key = '<base64 X25519 public key>';   in config/config.php, or
 *   <backup dir>/backup.pub                              (one line, same base64)
 * The backup dir is $backup_dir if set, else "backups/" beside $files_location (one level
 * above public_html, so never web-reachable).
 *
 * FILE FORMAT (.wnbk), streamed — plaintext never touches disk:
 *   "WNBK1\n"
 *   header line: JSON {v:1, key_id, sealed: b64(crypto_box_seal(K, pub)), ss: b64(secretstream
 *                header), app, created} + "\n"
 *   frames:      u32be length ‖ crypto_secretstream_xchacha20poly1305 ciphertext under K,
 *                additional data = the header line (so a header cannot be swapped onto
 *                another file's frames)
 *   Each frame's plaintext is one record: 1 kind byte + payload.
 *     'h' entry header JSON: {type:"db", name} | {type:"file", path, size, mtime}
 *     'd' data bytes for the current entry (SQL text for a db, file bytes for a file)
 *     'm' the manifest JSON — the LAST frame, tagged TAG_FINAL. A file without it is
 *         truncated and is refused.
 * Beside it: <name>.json, the plain manifest (table names, row counts, sizes, the ciphertext's
 * sha256) so a puller can check a backup is whole and sane without decrypting it. It holds no
 * user data.
 *
 * The format functions (wn_backup_writer_*, wn_backup_read, keys) need only ext-sodium, so
 * scripts/wn-backup.php can restore on any box without this app's config.
 */

const WN_BACKUP_MAGIC = "WNBK1\n";
const WN_BACKUP_CHUNK = 1048576;          // plaintext bytes per frame
const WN_BACKUP_SECRET_PREFIX = 'wnbk-secret-v1:';

// ─── KEYS ───────────────────────────────────────────────────────────────────

/** A new backup keypair: [secret line for the vault/paper, public key b64]. */
function wn_backup_keygen() {
    $kp = sodium_crypto_box_keypair();
    $sk = sodium_crypto_box_secretkey($kp);
    $pk = sodium_crypto_box_publickey($kp);
    $line = WN_BACKUP_SECRET_PREFIX . base64_encode($sk . $pk);
    sodium_memzero($kp); sodium_memzero($sk);
    return [$line, base64_encode($pk)];
}

/** Secret line → the 64-byte crypto_box keypair, or null. */
function wn_backup_parse_secret($line) {
    $line = trim((string) $line);
    if (strncmp($line, WN_BACKUP_SECRET_PREFIX, strlen(WN_BACKUP_SECRET_PREFIX)) !== 0) { return null; }
    $raw = base64_decode(substr($line, strlen(WN_BACKUP_SECRET_PREFIX)), true);
    if ($raw === false || strlen($raw) !== 64) { return null; }
    if (sodium_crypto_box_publickey_from_secretkey(substr($raw, 0, 32)) !== substr($raw, 32)) { return null; }
    return sodium_crypto_box_keypair_from_secretkey_and_publickey(substr($raw, 0, 32), substr($raw, 32));
}

/** base64 public key → 32 raw bytes, or null. */
function wn_backup_parse_public($b64) {
    $raw = base64_decode(trim((string) $b64), true);
    return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) ? $raw : null;
}

/** Short id of a public key: first 16 chars of base64url(sha256(pk)). */
function wn_backup_key_id($pk_raw) {
    return substr(rtrim(strtr(base64_encode(hash('sha256', $pk_raw, true)), '+/', '-_'), '='), 0, 16);
}

// ─── WRITER ─────────────────────────────────────────────────────────────────

/** Open $path for writing a backup to $pk_raw. Returns the writer state. */
function wn_backup_writer_open($path, $pk_raw, array $meta = []) {
    $fh = fopen($path, 'xb');
    if (!$fh) { throw new RuntimeException("cannot create $path"); }
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    [$state, $ssh] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    $header = json_encode([
        'v' => 1, 'key_id' => wn_backup_key_id($pk_raw),
        'sealed' => base64_encode(sodium_crypto_box_seal($key, $pk_raw)),
        'ss' => base64_encode($ssh),
    ] + $meta, JSON_UNESCAPED_SLASHES) . "\n";
    sodium_memzero($key);
    $w = ['fh' => $fh, 'state' => $state, 'ad' => $header, 'hash' => hash_init('sha256'), 'bytes' => 0, 'buf' => ''];
    wn_backup_raw_write($w, WN_BACKUP_MAGIC . $header);
    return $w;
}

function wn_backup_raw_write(array &$w, $bytes) {
    $n = strlen($bytes);
    for ($off = 0; $off < $n; ) {
        $wrote = fwrite($w['fh'], $off ? substr($bytes, $off) : $bytes);
        if ($wrote === false || $wrote === 0) { throw new RuntimeException('backup write failed (disk full?)'); }
        $off += $wrote;
    }
    hash_update($w['hash'], $bytes);
    $w['bytes'] += $n;
}

function wn_backup_frame(array &$w, $record, $final = false) {
    $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
    $ct = sodium_crypto_secretstream_xchacha20poly1305_push($w['state'], $record, $w['ad'], $tag);
    wn_backup_raw_write($w, pack('N', strlen($ct)) . $ct);
}

/** Start a new entry ({type:"db",...} or {type:"file",...}). */
function wn_backup_entry(array &$w, array $entry) {
    wn_backup_flush($w);
    wn_backup_frame($w, 'h' . json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Append data to the current entry (buffered into ~1 MB frames). */
function wn_backup_data(array &$w, $bytes) {
    $w['buf'] .= $bytes;
    while (strlen($w['buf']) >= WN_BACKUP_CHUNK) {
        wn_backup_frame($w, 'd' . substr($w['buf'], 0, WN_BACKUP_CHUNK));
        $w['buf'] = (string) substr($w['buf'], WN_BACKUP_CHUNK);
    }
}

function wn_backup_flush(array &$w) {
    if ($w['buf'] !== '') { wn_backup_frame($w, 'd' . $w['buf']); $w['buf'] = ''; }
}

/** Write the manifest as the final frame and close. Returns [ct_bytes, ct_sha256]. */
function wn_backup_writer_close(array &$w, array $manifest) {
    wn_backup_flush($w);
    wn_backup_frame($w, 'm' . json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
    if (!fflush($w['fh']) || !fclose($w['fh'])) { throw new RuntimeException('backup close failed'); }
    return [$w['bytes'], hash_final($w['hash'])];
}

// ─── READER ─────────────────────────────────────────────────────────────────

/**
 * Read a backup with the owner's keypair. $on(kind, payload) is called per record:
 * kind 'h' with the decoded entry array, 'd' with bytes, 'm' with the manifest array.
 * Throws on a wrong key, a tampered or truncated file. Returns the manifest.
 */
function wn_backup_read($path, $keypair, callable $on) {
    $fh = fopen($path, 'rb');
    if (!$fh) { throw new RuntimeException("cannot open $path"); }
    try {
        if (fread($fh, strlen(WN_BACKUP_MAGIC)) !== WN_BACKUP_MAGIC) { throw new RuntimeException('not a WNBK1 backup'); }
        $line = fgets($fh, 65536);
        $hdr = json_decode((string) $line, true);
        if (!is_array($hdr) || ($hdr['v'] ?? null) !== 1) { throw new RuntimeException('bad backup header'); }
        $pk = sodium_crypto_box_publickey($keypair);
        if (($hdr['key_id'] ?? '') !== wn_backup_key_id($pk)) {
            throw new RuntimeException('this backup was made for key ' . ($hdr['key_id'] ?? '?') . ', not ' . wn_backup_key_id($pk));
        }
        $key = sodium_crypto_box_seal_open(base64_decode((string) $hdr['sealed'], true) ?: '', $keypair);
        if ($key === false) { throw new RuntimeException('the key does not open this backup'); }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(base64_decode((string) $hdr['ss'], true) ?: '', $key);
        sodium_memzero($key);
        $manifest = null;
        while (true) {
            $lenb = fread($fh, 4);
            if ($lenb === '' || $lenb === false) { break; }
            if (strlen($lenb) !== 4) { throw new RuntimeException('truncated frame length'); }
            $len = unpack('N', $lenb)[1];
            if ($len < 18 || $len > WN_BACKUP_CHUNK + 4096) { throw new RuntimeException('bad frame length'); }
            $ct = '';
            while (strlen($ct) < $len && !feof($fh)) { $ct .= fread($fh, $len - strlen($ct)); }
            if (strlen($ct) !== $len) { throw new RuntimeException('truncated frame'); }
            $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ct, $line);
            if ($r === false) { throw new RuntimeException('a frame failed authentication (tampered or corrupt)'); }
            [$rec, $tag] = $r;
            if ($manifest !== null) { throw new RuntimeException('data after the final frame'); }
            $kind = $rec[0] ?? '';
            $payload = (string) substr($rec, 1);
            if ($kind === 'h') {
                $on('h', json_decode($payload, true));
            } elseif ($kind === 'd') {
                $on('d', $payload);
            } elseif ($kind === 'm' && $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $manifest = json_decode($payload, true);
                $on('m', $manifest);
            } else {
                throw new RuntimeException('unknown record');
            }
        }
        if ($manifest === null) { throw new RuntimeException('backup is truncated (no final manifest)'); }
        return $manifest;
    } finally {
        fclose($fh);
    }
}

// ─── DUMPING (host side; needs the app bootstrap) ───────────────────────────

/** Where backups live: $backup_dir, else "backups/" beside $files_location. */
function wn_backup_dir() {
    $dir = (string) ($GLOBALS['backup_dir'] ?? '');
    if ($dir === '') {
        $files = rtrim((string) ($GLOBALS['files_location'] ?? ''), '/');
        if ($files === '') { return null; }
        $parent = dirname($files);
        $dir = (realpath($parent) ?: $parent) . '/backups';
    }
    return rtrim($dir, '/') . '/';
}

/** The configured public key (raw) or null — null means backups are off. */
function wn_backup_public_key() {
    $cfg = wn_backup_parse_public($GLOBALS['backup_public_key'] ?? '');
    if ($cfg) { return $cfg; }
    $dir = wn_backup_dir();
    return $dir && is_file($dir . 'backup.pub') ? wn_backup_parse_public((string) file_get_contents($dir . 'backup.pub')) : null;
}

/** Every database this deployment owns: name => [host, name, user, pass]. */
function wn_backup_databases() {
    $out = [];
    if (!empty($GLOBALS['dbInstance'])) {
        $out[$GLOBALS['dbInstance']] = ['host' => ($GLOBALS['dbHostSpec'] ?? '') ?: 'localhost', 'name' => $GLOBALS['dbInstance'],
            'user' => $GLOBALS['dbUserName'] ?? '', 'pass' => $GLOBALS['dbPassword'] ?? ''];
    }
    if (function_exists('db_size_known_databases')) {
        foreach (db_size_known_databases() as $name => $conn) {
            if ($conn !== null && !isset($out[$name])) { $out[$name] = $conn; }
        }
    }
    return $out;
}

/** One SQL literal for a fetched value. Non-UTF-8 (binary) strings go out as hex. */
function wn_backup_sql_value(PDO $pdo, $v) {
    if ($v === null) { return 'NULL'; }
    if (is_int($v) || is_float($v)) { return (string) $v; }
    $v = (string) $v;
    if ($v !== '' && (!mb_check_encoding($v, 'UTF-8') || strpos($v, "\0") !== false)) { return '0x' . bin2hex($v); }
    return $pdo->quote($v);
}

/**
 * Stream one database as SQL into the writer, from one consistent snapshot. Returns
 * {tables: {name: rows}, views: n}.
 */
function wn_backup_dump_db(array &$w, array $conn) {
    $pdo = new PDO("mysql:host={$conn['host']};dbname={$conn['name']};charset=utf8mb4", $conn['user'], $conn['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
    ]);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    $tables = []; $views = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
        if ($r[1] === 'VIEW') { $views[] = $r[0]; } else { $tables[] = $r[0]; }
    }
    $cols = [];
    $st = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, EXTRA FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION");
    $st->execute([$conn['name']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (stripos((string) $c['EXTRA'], 'GENERATED') !== false) { continue; }   // computed on restore
        $cols[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
    }

    wn_backup_entry($w, ['type' => 'db', 'name' => $conn['name']]);
    wn_backup_data($w, "-- wn-backup of `{$conn['name']}` " . gmdate('Y-m-d H:i:s') . " UTC\n"
        . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n"
        . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    $q = function ($n) { return '`' . str_replace('`', '``', $n) . '`'; };
    $counts = [];
    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE ' . $q($t))->fetch(PDO::FETCH_NUM)[1];
        wn_backup_data($w, "DROP TABLE IF EXISTS " . $q($t) . ";\n$create;\n");
        $list = $cols[$t] ?? [];
        $counts[$t] = 0;
        if (!$list) { wn_backup_data($w, "\n"); continue; }
        $colSql = implode(',', array_map($q, $list));
        $prefix = 'INSERT INTO ' . $q($t) . " ($colSql) VALUES\n";
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $rows = $pdo->query("SELECT $colSql FROM " . $q($t));
        $batch = []; $size = 0;
        while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
            $vals = [];
            foreach ($row as $v) { $vals[] = wn_backup_sql_value($pdo, $v); }
            $tuple = '(' . implode(',', $vals) . ')';
            $batch[] = $tuple; $size += strlen($tuple);
            $counts[$t]++;
            if (count($batch) >= 500 || $size >= 900000) {
                wn_backup_data($w, $prefix . implode(",\n", $batch) . ";\n");
                $batch = []; $size = 0;
            }
        }
        $rows->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        if ($batch) { wn_backup_data($w, $prefix . implode(",\n", $batch) . ";\n"); }
        wn_backup_data($w, "\n");
    }
    foreach ($views as $v) {
        $create = $pdo->query('SHOW CREATE VIEW ' . $q($v))->fetch(PDO::FETCH_NUM)[1];
        $create = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create);
        wn_backup_data($w, "DROP VIEW IF EXISTS " . $q($v) . ";\n$create;\n");
    }
    wn_backup_data($w, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
    $pdo->exec('COMMIT');
    $pdo = null;
    return ['tables' => $counts, 'views' => count($views)];
}

/**
 * Stream $root's files into the writer. Skips symlinks and $skip dirs (relative, e.g.
 * 'sessions' — login session files are transient and are credentials). Returns
 * {count, bytes, unreadable}.
 */
function wn_backup_dump_files(array &$w, $root, array $skip = ['sessions']) {
    $root = rtrim(realpath($root) ?: $root, '/');
    $out = ['count' => 0, 'bytes' => 0, 'unreadable' => 0];
    if (!is_dir($root)) { return $out; }
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($f, $k, $iter) use ($root, $skip) {
                if ($f->isLink()) { return false; }
                $rel = substr($f->getPathname(), strlen($root) + 1);
                return !in_array(explode('/', $rel)[0], $skip, true);
            }
        )
    );
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $rel = substr($f->getPathname(), strlen($root) + 1);
        $fh = @fopen($f->getPathname(), 'rb');
        if (!$fh) { $out['unreadable']++; continue; }
        wn_backup_entry($w, ['type' => 'file', 'path' => $rel, 'size' => $f->getSize(), 'mtime' => $f->getMTime()]);
        while (!feof($fh)) {
            $chunk = fread($fh, WN_BACKUP_CHUNK);
            if ($chunk === false) { break; }
            wn_backup_data($w, $chunk);
        }
        fclose($fh);
        $out['count']++; $out['bytes'] += $f->getSize();
    }
    return $out;
}

/** This deployment's short name for file names: the parent of public_html's first child app, else 'admin'. */
function wn_backup_slug() {
    $slug = (string) ($GLOBALS['backup_slug'] ?? '');
    if ($slug === '') {
        foreach (glob(dirname(__DIR__, 3) . '/*/config/config.php') ?: [] as $cfg) {
            $d = basename(dirname(dirname($cfg)));
            if ($d !== 'admin') { $slug = $d; break; }
        }
    }
    $slug = preg_replace('/[^a-z0-9_-]+/i', '', $slug);
    return $slug !== '' ? $slug : 'admin';
}

/**
 * Make one backup now. Returns the plain manifest (with file name), or throws. Holds a lock
 * so two runs never overlap; keeps the newest $keep backups in the dir.
 */
function wn_backup_run($keep = 3) {
    $pk = wn_backup_public_key();
    if (!$pk) { throw new RuntimeException('backups are off: no backup public key configured'); }
    $dir = wn_backup_dir();
    if (!$dir) { throw new RuntimeException('no backup dir ($files_location unset)'); }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { throw new RuntimeException("cannot create $dir"); }
    @chmod($dir, 0700);
    $lock = fopen($dir . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('another backup is running'); }
    @set_time_limit(0);

    $slug = wn_backup_slug();
    $started = time();
    $base = 'wnbk-' . $slug . '-' . gmdate('Ymd-His', $started) . 'Z';
    $part = $dir . $base . '.partial';
    $created = gmdate('Y-m-d\TH:i:s\Z', $started);
    $manifest = ['v' => 1, 'app' => $slug, 'created' => $created, 'key_id' => wn_backup_key_id($pk), 'databases' => [], 'files' => null];
    try {
        $w = wn_backup_writer_open($part, $pk, ['app' => $slug, 'created' => $created]);
        foreach (wn_backup_databases() as $name => $conn) {
            $manifest['databases'][$name] = wn_backup_dump_db($w, $conn);
        }
        $files = (string) ($GLOBALS['files_location'] ?? '');
        if ($files !== '') { $manifest['files'] = wn_backup_dump_files($w, $files); }
        $manifest['finished'] = gmdate('Y-m-d\TH:i:s\Z');
        [$bytes, $sha] = wn_backup_writer_close($w, $manifest);
    } catch (Throwable $e) {
        if (isset($w['fh']) && is_resource($w['fh'])) { fclose($w['fh']); }
        @unlink($part);
        flock($lock, LOCK_UN);
        throw $e;
    }
    $plain = $manifest + ['file' => $base . '.wnbk', 'ct_bytes' => $bytes, 'ct_sha256' => $sha];
    rename($part, $dir . $base . '.wnbk');
    @chmod($dir . $base . '.wnbk', 0600);
    file_put_contents($dir . $base . '.json', json_encode($plain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @chmod($dir . $base . '.json', 0600);

    $all = glob($dir . 'wnbk-*.wnbk') ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - max(1, (int) $keep))) as $old) {
        @unlink($old);
        @unlink(substr($old, 0, -5) . '.json');
    }
    foreach (glob($dir . '*.partial') ?: [] as $stale) {
        if ($stale !== $part && filemtime($stale) < time() - 86400) { @unlink($stale); }
    }
    flock($lock, LOCK_UN);
    return $plain;
}

/** The newest finished backup's plain manifest in the dir, or null. */
function wn_backup_latest() {
    $dir = wn_backup_dir();
    $all = $dir ? (glob($dir . 'wnbk-*.json') ?: []) : [];
    if (!$all) { return null; }
    sort($all);
    return json_decode((string) file_get_contents(end($all)), true);
}

/** One-line summary of a manifest for logs: databases, tables, rows, files, size. */
function wn_backup_summary(array $m) {
    $t = 0; $r = 0;
    foreach ((array) ($m['databases'] ?? []) as $d) { $t += count($d['tables'] ?? []); $r += array_sum($d['tables'] ?? []); }
    return sprintf('%s: databases=%d tables=%d rows=%d files=%d size=%.1fMB key=%s',
        $m['file'] ?? '?', count($m['databases'] ?? []), $t, $r, $m['files']['count'] ?? 0,
        ($m['ct_bytes'] ?? 0) / 1048576, $m['key_id'] ?? '?');
}

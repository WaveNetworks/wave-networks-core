<?php
/**
 * scripts/rotate-secret-key.php — re-encrypt every sealed DB credential under a new key.
 * Task #2397. The key is $app_secret (config/config.php or APP_SECRET env), never the DB.
 *
 * Rotation, per host:
 *   1. php scripts/rotate-secret-key.php --old='<current app_secret>' --new='<new app_secret>' [--dry-run]
 *   2. put the new value in config/config.php ($app_secret) / APP_SECRET straight after.
 * Between 1 and 2 sealed values will not open (mail send fails, TOTP fails) — do both in
 * one sitting. Covers WN_SEALED_COLUMNS plus shard_config.db_pass_enc and
 * migration_source.db_password_enc (their own framings, same key). Re-running with the
 * same --old/--new is safe: a value that does not open under --old is skipped and named.
 * ⚠️ $app_secret also keys other things (e.g. HMACs); check docs/stored-credentials.md first.
 */

/**
 * @return int values re-encrypted
 */
function wn_secret_rotate($pdo, $old, $new, $dry = false) {
    global $app_secret;
    $keep = $app_secret;
    $n = 0;
    $cols = WN_SEALED_COLUMNS;
    foreach ($cols as [$table, $pk, $col]) {
        try {
            $rows = $pdo->query("SELECT $pk AS id, $col AS v FROM $table WHERE $col LIKE 'enc:v1:%'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { continue; }
        $up = $pdo->prepare("UPDATE $table SET $col = ? WHERE $pk = ? AND $col = ?");
        foreach ($rows as $r) {
            $app_secret = $old;
            $plain = wn_secret_decrypt(substr($r['v'], strlen(WN_SECRET_MARKER)));
            if ($plain === false) { fwrite(STDERR, "skip $table.$col #{$r['id']}: does not open under --old\n"); continue; }
            $app_secret = $new;
            $sealed = wn_secret_seal($plain);
            if (!$dry) $up->execute([$sealed, $r['id'], $r['v']]);
            $n++;
        }
    }
    // Older encrypted columns: shard_config (wn_secret_*), migration_source (*_source_password).
    $extra = [
        ['shard_config', "layer, app_slug, shard_id", 'db_pass_enc', 'wn_secret_decrypt', 'wn_secret_encrypt'],
        ['migration_source', 'source_id', 'db_password_enc', 'decrypt_source_password', 'encrypt_source_password'],
    ];
    foreach ($extra as [$table, $pkList, $col, $dec, $enc]) {
        if (!function_exists($dec) || !function_exists($enc)) continue;
        try { $rows = $pdo->query("SELECT *, $col AS v FROM $table WHERE $col IS NOT NULL AND $col <> ''")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { continue; }
        $pks = array_map('trim', explode(',', $pkList));
        $where = implode(' AND ', array_map(function ($k) { return "$k = ?"; }, $pks));
        $up = $pdo->prepare("UPDATE $table SET $col = ? WHERE $where AND $col = ?");
        foreach ($rows as $r) {
            $app_secret = $old;
            $plain = $dec($r['v']);
            if ($plain === false) { fwrite(STDERR, "skip $table.$col: does not open under --old\n"); continue; }
            $app_secret = $new;
            $re = $enc($plain);
            if (!$dry) $up->execute(array_merge([$re], array_map(function ($k) use ($r) { return $r[$k]; }, $pks), [$r['v']]));
            $n++;
        }
    }
    $app_secret = $keep;
    return $n;
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $opts = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
    }
    if (empty($opts['old']) || empty($opts['new'])) {
        fwrite(STDERR, "usage: php scripts/rotate-secret-key.php --old=SECRET --new=SECRET [--dry-run]\n");
        exit(2);
    }
    include __DIR__ . '/../include/common_readonly.php';
    global $db;
    $n = wn_secret_rotate($db, $opts['old'], $opts['new'], !empty($opts['dry-run']));
    echo (!empty($opts['dry-run']) ? '[dry-run] would re-encrypt' : 're-encrypted') . " $n value(s). Now set \$app_secret to the new value.\n";
}

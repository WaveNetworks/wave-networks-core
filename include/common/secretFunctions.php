<?php
/**
 * secretFunctions.php
 * Credentials stored in a database: encrypted when they must be USED, hashed when
 * they are only COMPARED. Task #2397. Inventory: docs/stored-credentials.md.
 *
 * ENCRYPTED (must be used) — wn_secret_seal() / wn_secret_open()
 *   'enc:v2:<kid>:' + base64(nonce ‖ XChaCha20-Poly1305 ciphertext), key = wn_key('seal', kid)
 *   from the $app_secret key ring (keyRingFunctions.php; config.php / APP_SECRET env, never
 *   the DB). Authenticated: a tampered value fails to open. 'enc:v1:' (AES-256-CBC off the
 *   k1 secret, no MAC) is the pre-ring format: still read, re-sealed by the sweep. The
 *   marker is what tells a sealed value from a plain-text one, so:
 *     - wn_secret_open() of an unmarked value returns it unchanged. That is the
 *       rollout window: rows written before this code are still read correctly.
 *     - wn_secret_seal_sweep() seals every unmarked value and re-seals every value not on
 *       the CURRENT key (v1, or an older kid). Idempotent — a value on the current key is
 *       never touched again. Runs daily (cron/days/1) and as a self-heal on read for
 *       smtp_pass. It also covers shard_config / migration_source and the columns child
 *       apps register with wn_register_sealed_column().
 *     - scripts/stored-secrets-probe.php fails the deploy if a known column's
 *       write path stops sealing; scripts/stored-secrets-check.php names any
 *       unmarked value still in a live DB (and --fix seals it).
 *
 * HASHED (only compared) — wn_token_hash()
 *   'sha256:' + hex. One-time tokens (forgot, invite) are looked up by hash; migration
 *   main/5.9 hashes the existing rows in SQL.
 *
 * Rotation (docs/core-secret-rotation.md): add a key to $app_secrets, make it current,
 * let the sweep move every value onto it, then retire the old one once
 * `php scripts/rotate-secret-key.php status` shows nothing left on it.
 */

require_once __DIR__ . '/keyRingFunctions.php';

define('WN_SECRET_MARKER', 'enc:v1:');       // pre-ring AES-256-CBC (read only)
define('WN_SECRET_MARKER_V2', 'enc:v2:');
define('WN_TOKEN_MARKER', 'sha256:');

/**
 * Every DB column that holds a credential core must USE. table => [pk, column].
 * Add a row here when you add such a column; the probe and the sweep read this list.
 * (shard_config.db_pass_enc and migration_source.db_password_enc predate the marker
 * and are encrypted by their own helpers — see docs/stored-credentials.md.)
 */
const WN_SEALED_COLUMNS = [
    ['email_settings', 'setting_id', 'smtp_pass'],
    ['oauth_provider', 'provider_id', 'client_secret'],
    ['user',           'user_id',     'totp_secret'],
];

function wn_secret_is_sealed($v) {
    return is_string($v) && (strncmp($v, WN_SECRET_MARKER_V2, 7) === 0 || strncmp($v, WN_SECRET_MARKER, 7) === 0);
}

/** Plain → 'enc:v2:<current kid>:…'. Empty stays empty; an already-sealed value is returned as-is. */
function wn_secret_seal($plain) {
    if ($plain === null || $plain === '') return $plain;
    if (wn_secret_is_sealed($plain)) return $plain;
    return wn_secret_seal_now($plain);
}

/** Seal under the current key whatever the input looks like (the sweep's re-seal). */
function wn_secret_seal_now($plain, $kid = null) {
    $ring = wn_app_key_ring();
    $kid  = $kid ?? $ring['current'];
    $key  = wn_key('seal', $kid);
    if ($key === null) return false;
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $ad    = WN_SECRET_MARKER_V2 . $kid;
    $ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt((string) $plain, $ad, $nonce, $key);
    sodium_memzero($key);
    return $ad . ':' . base64_encode($nonce . $ct);
}

/** The key id a stored value is sealed under: kid for v2, the legacy id for v1, null if unmarked. */
function wn_secret_kid($stored) {
    if (!is_string($stored)) return null;
    if (strncmp($stored, WN_SECRET_MARKER_V2, 7) === 0) {
        $rest = substr($stored, 7);
        $p = strpos($rest, ':');
        return $p === false ? null : substr($rest, 0, $p);
    }
    if (strncmp($stored, WN_SECRET_MARKER, 7) === 0) return wn_app_key_ring()['legacy'];
    return null;
}

/** A marked value → plain, or false if it does not open (unknown kid, wrong key, tampered). */
function wn_secret_open_marked($stored) {
    if (strncmp($stored, WN_SECRET_MARKER_V2, 7) === 0) {
        $kid = wn_secret_kid($stored);
        $key = $kid === null ? null : wn_key('seal', $kid);
        if ($key === null) return false;
        $raw = base64_decode(substr($stored, 7 + strlen($kid) + 1), true);
        $n   = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw) <= $n) { sodium_memzero($key); return false; }
        $out = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), WN_SECRET_MARKER_V2 . $kid, substr($raw, 0, $n), $key);
        sodium_memzero($key);
        return $out;
    }
    if (strncmp($stored, WN_SECRET_MARKER, 7) === 0) {
        return wn_secret_decrypt_legacy_cbc(substr($stored, 7));
    }
    return false;
}

/** Sealed → plain. An unmarked value is legacy plain text and is returned unchanged. */
function wn_secret_open($stored) {
    if (!wn_secret_is_sealed($stored)) return $stored;
    $plain = wn_secret_open_marked($stored);
    return $plain === false ? '' : $plain;
}

/** One-time token → 'sha256:<hex>'. Store this; look up by this. */
function wn_token_hash($token) {
    return WN_TOKEN_MARKER . hash('sha256', (string)$token);
}

// ─── Sealed-column registry ─────────────────────────────────────────────────
//
// kind 'plain': an unmarked value is plain text (seal it).
// kind 'cbc':   an unmarked value is pre-marker wn_secret_encrypt() output (shard_config).
// kind 'source': an unmarked value is pre-marker encrypt_source_password() output.
// db 'main' = $db, 'child' = the child app's $child_db.

/** A child app registers a column it fills with wn_secret_encrypt()/wn_secret_seal(). */
function wn_register_sealed_column($table, $pk, $col, $kind = 'cbc', $db = 'child') {
    $GLOBALS['wn_registered_sealed_columns']["$db:$table.$col"] =
        ['db' => $db, 'table' => $table, 'pk' => (array) $pk, 'col' => $col, 'kind' => $kind];
}

/** Every known sealed column: core's main-DB columns + whatever child apps registered. */
function wn_sealed_column_registry() {
    $out = [];
    foreach (WN_SEALED_COLUMNS as [$table, $pk, $col]) {
        $out["main:$table.$col"] = ['db' => 'main', 'table' => $table, 'pk' => [$pk], 'col' => $col, 'kind' => 'plain'];
    }
    $out['main:shard_config.db_pass_enc'] = ['db' => 'main', 'table' => 'shard_config',
        'pk' => ['layer', 'app_slug', 'shard_id'], 'col' => 'db_pass_enc', 'kind' => 'cbc'];
    $out['main:migration_source.db_password_enc'] = ['db' => 'main', 'table' => 'migration_source',
        'pk' => ['source_id'], 'col' => 'db_password_enc', 'kind' => 'source'];
    return $out + ($GLOBALS['wn_registered_sealed_columns'] ?? []);
}

/** The PDO a registry entry lives on, or null. */
function wn_sealed_column_pdo($entry, $main, $child) {
    if ($entry['db'] === 'child') return $child ?? ($GLOBALS['child_db'] ?? null);
    return $main;
}

/** Stored value → plain for the sweep, by kind. false = could not open (left alone). */
function wn_secret_open_for_sweep($v, $kind) {
    if (wn_secret_is_sealed($v)) return wn_secret_open_marked($v);
    if ($kind === 'plain')  return $v;
    if ($kind === 'cbc')    return wn_secret_decrypt_legacy_cbc($v);
    if ($kind === 'source') return function_exists('decrypt_source_password_legacy') ? decrypt_source_password_legacy($v) : false;
    return false;
}

/**
 * Seal every unmarked value and re-seal every value not on the current key, across the
 * registry. Idempotent. Uses the PDOs passed in (or $db / $child_db) so the probe can run
 * it on SQLite. A value that won't open is left as it is and logged.
 *
 * @return array db:table.column => rows written (missing tables are skipped, not errors)
 */
function wn_secret_seal_sweep($pdo = null, $child = null) {
    if ($pdo === null) { global $db; $pdo = $db; }
    $cur = WN_SECRET_MARKER_V2 . wn_app_key_ring()['current'] . ':';
    $out = [];
    foreach (wn_sealed_column_registry() as $name => $e) {
        $h = wn_sealed_column_pdo($e, $pdo, $child);
        if (!$h) continue;
        $label = $e['db'] === 'main' ? "{$e['table']}.{$e['col']}" : $name;
        $n = 0;
        try {
            $sel = implode(', ', $e['pk']);
            $rows = $h->query("SELECT $sel, {$e['col']} AS v FROM {$e['table']}
                               WHERE {$e['col']} IS NOT NULL AND {$e['col']} <> '' AND {$e['col']} NOT LIKE '$cur%'")
                      ->fetchAll(PDO::FETCH_ASSOC);
            $where = implode(' AND ', array_map(function ($k) { return "$k = ?"; }, $e['pk']));
            $up = $h->prepare("UPDATE {$e['table']} SET {$e['col']} = ? WHERE $where AND {$e['col']} = ?");
            foreach ($rows as $r) {
                $plain = wn_secret_open_for_sweep($r['v'], $e['kind']);
                if ($plain === false) { error_log("seal sweep: $label value does not open; left as is"); continue; }
                $sealed = wn_secret_seal_now($plain);
                if ($sealed === false) continue;
                $up->execute(array_merge([$sealed], array_map(function ($k) use ($r) { return $r[$k]; }, $e['pk']), [$r['v']]));
                $n += $up->rowCount();
            }
        } catch (Throwable $ex) {
            // Table/column absent on this host (pre-migration) — nothing to seal.
            continue;
        }
        $out[$label] = $n;
    }
    return $out;
}

/**
 * Count unmarked plain-text values per core sealed column. Empty array = clean.
 */
function wn_secret_unsealed_counts($pdo = null) {
    if ($pdo === null) { global $db; $pdo = $db; }
    $out = [];
    foreach (WN_SEALED_COLUMNS as [$table, $pk, $col]) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM $table
                                   WHERE $col IS NOT NULL AND $col <> '' AND $col NOT LIKE 'enc:v1:%' AND $col NOT LIKE 'enc:v2:%'")
                          ->fetchColumn();
        } catch (Throwable $e) { continue; }
        if ($n > 0) $out["$table.$col"] = $n;
    }
    return $out;
}

/**
 * How many stored values sit on each key, per registered column:
 * ['email_settings.smtp_pass' => ['k2' => 3, 'k1' => 1, 'unmarked' => 0], …].
 * 'unmarked' is plain text (kind plain) or pre-marker ciphertext (kind cbc/source) —
 * both belong to the legacy key until the sweep moves them.
 */
function wn_secret_key_counts($pdo = null, $child = null) {
    if ($pdo === null) { global $db; $pdo = $db; }
    $out = [];
    foreach (wn_sealed_column_registry() as $name => $e) {
        $h = wn_sealed_column_pdo($e, $pdo, $child);
        if (!$h) continue;
        $label = $e['db'] === 'main' ? "{$e['table']}.{$e['col']}" : $name;
        try {
            $vals = $h->query("SELECT {$e['col']} FROM {$e['table']} WHERE {$e['col']} IS NOT NULL AND {$e['col']} <> ''")
                      ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $ex) { continue; }
        $c = [];
        foreach ($vals as $v) {
            $k = wn_secret_is_sealed($v) ? (strncmp($v, WN_SECRET_MARKER_V2, 7) === 0 ? wn_secret_kid($v) : 'v1:' . (wn_secret_kid($v) ?? 'k1-retired')) : 'unmarked';
            $c[$k] = ($c[$k] ?? 0) + 1;
        }
        $out[$label] = $c;
    }
    return $out;
}

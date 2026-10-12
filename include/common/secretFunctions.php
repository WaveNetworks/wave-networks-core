<?php
/**
 * secretFunctions.php
 * Credentials stored in a database: encrypted when they must be USED, hashed when
 * they are only COMPARED. Task #2397. Inventory: docs/stored-credentials.md.
 *
 * ENCRYPTED (must be used) — wn_secret_seal() / wn_secret_open()
 *   The value is wn_secret_encrypt() (shardConfigFunctions.php: AES-256-CBC, key from
 *   $app_secret, which comes from config.php / APP_SECRET env, never the DB) with an
 *   'enc:v1:' marker in front. The marker is what tells a sealed value from a
 *   plain-text one, so:
 *     - wn_secret_open() of an unmarked value returns it unchanged. That is the
 *       rollout window: rows written before this code are still read correctly.
 *     - wn_secret_seal_sweep() seals every unmarked value in place. Idempotent —
 *       a marked value is never touched again. Runs daily (cron/days/1) and as a
 *       self-heal on read for smtp_pass.
 *     - scripts/stored-secrets-probe.php fails the deploy if a known column's
 *       write path stops sealing; scripts/stored-secrets-check.php names any
 *       unmarked value still in a live DB (and --fix seals it).
 *
 * HASHED (only compared) — wn_token_hash()
 *   'sha256:' + hex. One-time tokens (forgot, invite) are looked up by hash; migration
 *   main/5.9 hashes the existing rows in SQL.
 *
 * Rotation (new $app_secret): scripts/rotate-secret-key.php re-encrypts every column in
 * WN_SEALED_COLUMNS from the old key to the new one.
 */

define('WN_SECRET_MARKER', 'enc:v1:');
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
    return is_string($v) && strncmp($v, WN_SECRET_MARKER, strlen(WN_SECRET_MARKER)) === 0;
}

/** Plain → 'enc:v1:…'. Empty stays empty; an already-sealed value is returned as-is. */
function wn_secret_seal($plain) {
    if ($plain === null || $plain === '') return $plain;
    if (wn_secret_is_sealed($plain)) return $plain;
    $enc = wn_secret_encrypt($plain);
    if ($enc === false) return false;
    return WN_SECRET_MARKER . $enc;
}

/** 'enc:v1:…' → plain. An unmarked value is legacy plain text and is returned unchanged. */
function wn_secret_open($stored) {
    if (!wn_secret_is_sealed($stored)) return $stored;
    $plain = wn_secret_decrypt(substr($stored, strlen(WN_SECRET_MARKER)));
    return $plain === false ? '' : $plain;
}

/** One-time token → 'sha256:<hex>'. Store this; look up by this. */
function wn_token_hash($token) {
    return WN_TOKEN_MARKER . hash('sha256', (string)$token);
}

/**
 * Seal every unmarked, non-empty value in WN_SEALED_COLUMNS. Idempotent.
 * Uses the PDO handle passed in (or global $db) so the probe can run it on SQLite.
 *
 * @return array table.column => rows sealed (missing tables are skipped, not errors)
 */
function wn_secret_seal_sweep($pdo = null) {
    if ($pdo === null) { global $db; $pdo = $db; }
    $out = [];
    foreach (WN_SEALED_COLUMNS as [$table, $pk, $col]) {
        $n = 0;
        try {
            $rows = $pdo->query("SELECT $pk AS id, $col AS v FROM $table
                                 WHERE $col IS NOT NULL AND $col <> '' AND $col NOT LIKE 'enc:v1:%'")
                        ->fetchAll(PDO::FETCH_ASSOC);
            $up = $pdo->prepare("UPDATE $table SET $col = ? WHERE $pk = ? AND $col = ?");
            foreach ($rows as $r) {
                $sealed = wn_secret_seal($r['v']);
                if ($sealed === false) continue;
                $up->execute([$sealed, $r['id'], $r['v']]);
                $n += $up->rowCount();
            }
        } catch (Throwable $e) {
            // Table/column absent on this host (pre-migration) — nothing to seal.
            continue;
        }
        $out["$table.$col"] = $n;
    }
    return $out;
}

/**
 * Count unmarked values per sealed column. Empty array = clean.
 */
function wn_secret_unsealed_counts($pdo = null) {
    if ($pdo === null) { global $db; $pdo = $db; }
    $out = [];
    foreach (WN_SEALED_COLUMNS as [$table, $pk, $col]) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM $table
                                   WHERE $col IS NOT NULL AND $col <> '' AND $col NOT LIKE 'enc:v1:%'")
                          ->fetchColumn();
        } catch (Throwable $e) { continue; }
        if ($n > 0) $out["$table.$col"] = $n;
    }
    return $out;
}

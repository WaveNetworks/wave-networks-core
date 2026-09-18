<?php
/**
 * shardConfigFunctions.php
 * Shard registry — the source of truth for which shards exist.
 *
 * WHY THIS EXISTS
 * ---------------
 * $shardConfigs used to be a literal array in the gitignored, per-host
 * admin/config/config.php, hardcoded to exactly two shards. That does not scale:
 * 20i caps every database at 1GB (DB_SIZE_LIMIT_MB) without a Turbo upgrade, so
 * the per-shard size ceiling cannot move and capacity can only grow by shard
 * COUNT. Hand-editing a PHP array on every host, per shard, is not a mechanism
 * that reaches the shard counts that implies.
 *
 * So shards are rows. db_host is per-shard on purpose — shards may live on
 * different hosting packages or servers, so one package's database-count cap
 * stops being a ceiling on the app.
 *
 * BACKWARD COMPATIBILITY IS THE CONTRACT: an absent or empty shard_config table
 * means the platform behaves exactly as it did before. config.php stays the
 * seed; rows only ever ADD to (or override) what config.php declared. Every
 * read here is failure-tolerant for exactly that reason — a host that has not
 * run migration 4.8 yet must keep serving.
 *
 * STATUS SEMANTICS
 *   pending  — credentials stored, NOT connected and NOT assignable. Where a
 *              shard sits between "creds pasted" and "migrations passed".
 *   active   — migrated and assignable to new users.
 *   draining — still served (existing users' data lives there) but never
 *              assigned to anyone new. How a shard is retired, and what the
 *              high-water guard flips a filling shard to.
 *   disabled — not loaded at all. Only safe when empty.
 */

// ─── SECRETS ────────────────────────────────────────────────────────────────
//
// Same scheme and same key material as encrypt_source_password() in
// migrationSyncFunctions.php (AES-256-CBC off $app_secret) — that function
// already solves this exact problem: storing another database's password in a
// table. Kept self-contained here rather than depending on the migration-sync
// helper, since the shard registry loads as part of bootstrap.
//
// ⚠️ One deliberate difference: the framing. The migration-sync pair joins the
// IV and ciphertext with a '::' separator and splits on the first occurrence —
// but the IV is 16 random BYTES, which contain 0x3A3A ('::') roughly 1 time in
// 4400. When that happens the value silently cannot be decrypted. A shard whose
// password won't decrypt is a shard that drops out of $shardConfigs, so here the
// IV is a fixed-length 16-byte prefix and the split is by offset, never by
// searching for a delimiter that can occur inside the data.

function wn_secret_encrypt($plain) {
    global $app_secret;
    $key = substr(hash('sha256', (string)$app_secret), 0, 32);
    $iv  = openssl_random_pseudo_bytes(16);
    $ct  = openssl_encrypt((string)$plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return false;
    return base64_encode($iv . $ct);
}

function wn_secret_decrypt($enc) {
    global $app_secret;
    $raw = base64_decode((string)$enc, true);
    if ($raw === false || strlen($raw) <= 16) return false;
    $key = substr(hash('sha256', (string)$app_secret), 0, 32);
    return openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
}

// ─── TABLE ──────────────────────────────────────────────────────────────────

/**
 * Belt-and-suspenders creation, for hosts where the decimal migration runner
 * drops DDL (MariaDB). Runs in autocommit. Mirrors ensure_db_size_cache_table().
 */
function ensure_shard_config_table() {
    db_query("CREATE TABLE IF NOT EXISTS shard_config (
        layer          VARCHAR(16)   NOT NULL DEFAULT 'admin',
        app_slug       VARCHAR(64)   NOT NULL DEFAULT '',
        shard_id       VARCHAR(32)   NOT NULL,
        db_host        VARCHAR(255)  NOT NULL,
        db_name        VARCHAR(128)  NOT NULL,
        db_user        VARCHAR(128)  NOT NULL,
        db_pass_enc    TEXT          NOT NULL,
        status         VARCHAR(16)   NOT NULL DEFAULT 'pending',
        schema_version DECIMAL(6,1)  NULL,
        size_mb        DECIMAL(10,2) NOT NULL DEFAULT 0,
        size_checked   DATETIME      NULL,
        user_count     INT UNSIGNED  NOT NULL DEFAULT 0,
        last_migrated  DATETIME      NULL,
        last_error     TEXT          NULL,
        notes          VARCHAR(255)  NULL,
        created        DATETIME      NOT NULL,
        updated        DATETIME      NULL,
        PRIMARY KEY (layer, app_slug, shard_id),
        KEY idx_assign (layer, app_slug, status, size_mb)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ─── THRESHOLDS ─────────────────────────────────────────────────────────────

/**
 * The size at which a shard stops receiving NEW users.
 *
 * Reuses DB_SIZE_CRITICAL_MB (dbSizeFunctions.php) rather than introducing a
 * second threshold, so "critical" means exactly one thing across the platform:
 * stop assigning here. dbSizeFunctions.php already alerts at this point.
 *
 * This guard is not a nicety. A user's shard_id never changes after
 * registration, so a user pinned to a shard that reaches the 1GB ceiling has
 * failing writes and CANNOT be rescued by adding more shards afterwards. The
 * only workable moment to act is before the shard fills.
 */
function shard_high_water_mb() {
    return defined('DB_SIZE_CRITICAL_MB') ? (float)DB_SIZE_CRITICAL_MB : 900.0;
}

// ─── READS ──────────────────────────────────────────────────────────────────

/**
 * Raw registry rows. Never throws: a host that has not run migration 4.8 yet
 * must keep serving off config.php.
 *
 * @param string     $layer    'admin' | 'child'
 * @param string     $app_slug '' for the admin layer, the child slug otherwise
 * @param array|null $statuses restrict to these statuses (null = all)
 * @return array
 */
function shard_config_rows($layer = 'admin', $app_slug = '', $statuses = null) {
    global $db;
    if (!($db instanceof PDO)) return [];

    $sql    = "SELECT * FROM shard_config WHERE layer = ? AND app_slug = ?";
    $params = [(string)$layer, (string)$app_slug];

    if (is_array($statuses) && $statuses) {
        $sql .= " AND status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
        $params = array_merge($params, array_values($statuses));
    }
    $sql .= " ORDER BY LENGTH(shard_id), shard_id";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table absent (pre-4.8 host) or unreadable — fall back to config.php.
        return [];
    }
}

/**
 * Merge registry rows into a $shardConfigs-shaped array.
 *
 * Only 'active' and 'draining' shards are exposed to the application: a
 * 'pending' shard has not passed its migration yet, and connecting to it would
 * hand a user a schema-less database.
 *
 * Rows WIN over the config.php seed for the same shard_id, so a shard can be
 * repointed at a new host without editing a file on the server.
 *
 * @param array  $configs  existing config (from config.php / env)
 * @param string $layer
 * @param string $app_slug
 * @return array merged config
 */
function shard_config_merge($configs, $layer = 'admin', $app_slug = '') {
    if (!is_array($configs)) $configs = [];

    foreach (shard_config_rows($layer, $app_slug, ['active', 'draining']) as $row) {
        $pass = wn_secret_decrypt($row['db_pass_enc']);
        if ($pass === false) {
            error_log("shard_config_merge: cannot decrypt password for {$layer}/{$app_slug}/{$row['shard_id']}");
            continue;
        }
        $configs[$row['shard_id']] = [
            'host' => $row['db_host'],
            'name' => $row['db_name'],
            'user' => $row['db_user'],
            'pass' => $pass,
        ];
    }

    return $configs;
}

/**
 * The shards that may receive a NEW user right now: active, and below the
 * high-water mark. Ordered by bytes free.
 *
 * Ordering by size rather than user count is deliberate — the constraint is the
 * 1GB ceiling, and two shards with identical user counts can be very different
 * sizes. Sizes come from the shard_config cache (refreshed by
 * refresh_shard_config_sizes()), so assignment never touches information_schema.
 *
 * @return array rows, emptiest first
 */
function shard_assignable_rows($layer = 'admin', $app_slug = '') {
    $high = shard_high_water_mb();
    $out  = [];
    foreach (shard_config_rows($layer, $app_slug, ['active']) as $row) {
        if ((float)$row['size_mb'] >= $high) continue;
        $out[] = $row;
    }
    usort($out, function ($a, $b) {
        $c = (float)$a['size_mb'] <=> (float)$b['size_mb'];
        return $c !== 0 ? $c : ((int)$a['user_count'] <=> (int)$b['user_count']);
    });
    return $out;
}

/**
 * The next unused shard id for a layer, e.g. 'shard7'. Drives the console's
 * "create this database next" hint.
 */
function shard_config_next_id($layer = 'admin', $app_slug = '') {
    $max = 0;
    foreach (shard_config_rows($layer, $app_slug) as $row) {
        if (preg_match('/^shard(\d+)$/', $row['shard_id'], $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    // config.php's seed shards are not rows, so count them too.
    if ($layer === 'admin') {
        global $shardConfigs;
        foreach (array_keys((array)$shardConfigs) as $sid) {
            if (preg_match('/^shard(\d+)$/', (string)$sid, $m)) $max = max($max, (int)$m[1]);
        }
    }
    return 'shard' . ($max + 1);
}

// ─── WRITES ─────────────────────────────────────────────────────────────────

/**
 * Create or update a registry row.
 *
 * The row is written BEFORE any connection test — a failed test must never
 * discard credentials that were just typed in. Pass $pass === null (or '') on
 * an update to keep the stored password ("on file, blank keeps it").
 *
 * @return array [bool $ok, string $err]
 */
function shard_config_upsert($layer, $app_slug, $shard_id, $host, $name, $user, $pass = null, $notes = null) {
    ensure_shard_config_table();

    $layer    = (string)$layer;
    $app_slug = (string)$app_slug;
    $shard_id = trim((string)$shard_id);

    if (!in_array($layer, ['admin', 'child'], true))      return [false, 'layer must be admin or child.'];
    if (!preg_match('/^[a-z0-9_]{1,32}$/i', $shard_id))   return [false, 'shard_id must be alphanumeric.'];
    if (trim((string)$host) === '')                       return [false, 'db_host is required.'];
    if (trim((string)$name) === '')                       return [false, 'db_name is required.'];
    if (trim((string)$user) === '')                       return [false, 'db_user is required.'];

    $existing = null;
    foreach (shard_config_rows($layer, $app_slug) as $row) {
        if ($row['shard_id'] === $shard_id) { $existing = $row; break; }
    }

    if ($existing === null && (string)$pass === '') {
        return [false, 'db_password is required for a new shard.'];
    }

    $enc = ((string)$pass === '') ? $existing['db_pass_enc'] : wn_secret_encrypt($pass);
    if ($enc === false) return [false, 'Could not encrypt the password.'];

    if ($existing === null) {
        $ok = db_query_prepared(
            "INSERT INTO shard_config
                (layer, app_slug, shard_id, db_host, db_name, db_user, db_pass_enc, status, notes, created)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())",
            [$layer, $app_slug, $shard_id, $host, $name, $user, $enc, $notes]
        );
    } else {
        $ok = db_query_prepared(
            "UPDATE shard_config
                SET db_host = ?, db_name = ?, db_user = ?, db_pass_enc = ?,
                    notes = COALESCE(?, notes), updated = NOW()
              WHERE layer = ? AND app_slug = ? AND shard_id = ?",
            [$host, $name, $user, $enc, $notes, $layer, $app_slug, $shard_id]
        );
    }

    return $ok === false ? [false, 'Could not save the shard.'] : [true, ''];
}

function shard_config_set_status($layer, $app_slug, $shard_id, $status) {
    if (!in_array($status, ['pending', 'active', 'draining', 'disabled'], true)) {
        return [false, 'Unknown status.'];
    }
    $ok = db_query_prepared(
        "UPDATE shard_config SET status = ?, updated = NOW()
          WHERE layer = ? AND app_slug = ? AND shard_id = ?",
        [$status, (string)$layer, (string)$app_slug, (string)$shard_id]
    );
    return $ok === false ? [false, 'Could not update status.'] : [true, ''];
}

function shard_config_record_error($layer, $app_slug, $shard_id, $err) {
    db_query_prepared(
        "UPDATE shard_config SET last_error = ?, updated = NOW()
          WHERE layer = ? AND app_slug = ? AND shard_id = ?",
        [($err === null ? null : (string)$err), (string)$layer, (string)$app_slug, (string)$shard_id]
    );
}

/**
 * Open a one-off connection to a registry row. Used by test/migrate/size paths,
 * which must reach 'pending' shards that prime_shard() deliberately cannot see.
 *
 * @return array [PDO|null $conn, string $err]
 */
function shard_config_connect($row) {
    $pass = wn_secret_decrypt($row['db_pass_enc']);
    if ($pass === false) return [null, 'Stored password could not be decrypted.'];
    try {
        $conn = new PDO(
            "mysql:host={$row['db_host']};dbname={$row['db_name']};charset=utf8mb4",
            $row['db_user'], $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10,
             PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"]   // one clock: UTC
        );
        return [$conn, ''];
    } catch (PDOException $e) {
        return [null, $e->getMessage()];
    }
}

/**
 * Refresh cached size + user count for every registry row.
 *
 * Local shards (same server as the admin DB) are read from the shard_db_size
 * cache that dbSizeFunctions.php already collects hourly — no second source of
 * truth, no extra information_schema sweep. A shard on ANOTHER host is not in
 * that cache, so it is measured over its own connection.
 *
 * Crossing the high-water mark flips a shard to 'draining': it keeps serving
 * its existing users and stops receiving new ones.
 *
 * @return array summary counts
 */
function refresh_shard_config_sizes($layer = 'admin', $app_slug = '') {
    $high      = shard_high_water_mb();
    $local     = [];
    $drained   = [];
    $checked   = 0;

    foreach (db_fetch_all(db_query("SELECT schema_name, size_mb FROM shard_db_size")) ?: [] as $r) {
        $local[$r['schema_name']] = (float)$r['size_mb'];
    }

    foreach (shard_config_rows($layer, $app_slug) as $row) {
        if ($row['status'] === 'disabled') continue;

        $size = null;
        if (isset($local[$row['db_name']])) {
            $size = $local[$row['db_name']];
        } else {
            list($conn, $err) = shard_config_connect($row);
            if ($conn) {
                try {
                    $st = $conn->prepare(
                        "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb
                           FROM information_schema.TABLES WHERE table_schema = ?"
                    );
                    $st->execute([$row['db_name']]);
                    $size = (float)$st->fetchColumn();
                } catch (PDOException $e) {
                    shard_config_record_error($layer, $app_slug, $row['shard_id'], $e->getMessage());
                }
            } else {
                shard_config_record_error($layer, $app_slug, $row['shard_id'], $err);
            }
        }

        if ($size === null) continue;
        $checked++;

        db_query_prepared(
            "UPDATE shard_config SET size_mb = ?, size_checked = NOW(), updated = NOW()
              WHERE layer = ? AND app_slug = ? AND shard_id = ?",
            [$size, $layer, $app_slug, $row['shard_id']]
        );

        if ($size >= $high && $row['status'] === 'active') {
            shard_config_set_status($layer, $app_slug, $row['shard_id'], 'draining');
            $drained[] = $row['shard_id'];
            log_error_to_db(
                'WARNING',
                "Shard {$row['shard_id']} ({$row['db_name']}) reached " . round($size) . "MB of the "
                . DB_SIZE_LIMIT_MB . "MB ceiling — set to draining, no longer accepting new users.",
                __FILE__, __LINE__
            );
        }
    }

    // The condition that actually breaks registration: nowhere left to put a
    // new user. Loud, because adding a shard afterwards cannot fix the users
    // who were already pinned to a full one.
    if (!shard_assignable_rows($layer, $app_slug) && shard_config_rows($layer, $app_slug, ['active', 'draining'])) {
        log_error_to_db(
            'FATAL',
            "No shard below the {$high}MB high-water mark for {$layer}/{$app_slug} — new "
            . "registrations have nowhere to go. Provision a shard now.",
            __FILE__, __LINE__
        );
    }

    return ['checked' => $checked, 'drained' => $drained];
}

/**
 * Refresh cached per-shard user counts from the main user table.
 * Admin layer only — child apps do not own the user table.
 */
function refresh_shard_config_user_counts() {
    $counts = [];
    foreach (db_fetch_all(db_query("SELECT shard_id, COUNT(*) AS cnt FROM user GROUP BY shard_id")) ?: [] as $r) {
        $counts[$r['shard_id']] = (int)$r['cnt'];
    }
    foreach (shard_config_rows('admin', '') as $row) {
        db_query_prepared(
            "UPDATE shard_config SET user_count = ?, updated = NOW()
              WHERE layer = 'admin' AND app_slug = '' AND shard_id = ?",
            [$counts[$row['shard_id']] ?? 0, $row['shard_id']]
        );
    }
}

<?php
/**
 * migrationSyncFunctions.php
 * Parallel auth migration — sync external users into core, legacy password rehash,
 * on-the-fly migration helper for child apps.
 */

// ─── ENCRYPTION ─────────────────────────────────────────────────────────────

/**
 * Encrypt a plaintext password for storage in migration_source.db_password_enc.
 *
 * @param string $plain
 * @return string Base64-encoded ciphertext
 */
function encrypt_source_password($plain) {
    global $app_secret;
    $key    = substr(hash('sha256', $app_secret), 0, 32);
    $iv     = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . '::' . base64_decode($cipher));
}

/**
 * Decrypt a stored migration_source.db_password_enc value.
 *
 * @param string $enc Base64-encoded ciphertext
 * @return string|false Plaintext password or false on failure
 */
function decrypt_source_password($enc) {
    global $app_secret;
    $key  = substr(hash('sha256', $app_secret), 0, 32);
    $data = base64_decode($enc);
    $parts = explode('::', $data, 2);
    if (count($parts) !== 2) return false;
    $iv     = $parts[0];
    $cipher = base64_encode($parts[1]);
    return openssl_decrypt($cipher, 'AES-256-CBC', $key, 0, $iv);
}

// ─── MIGRATION SOURCE ────────────────────────────────────────────────────────

/**
 * Get the configured migration source (single-source design).
 *
 * @return array|false
 */
function get_migration_source() {
    $r = db_query("SELECT * FROM migration_source ORDER BY source_id ASC LIMIT 1");
    return db_fetch($r);
}

/**
 * Get migration source by ID.
 *
 * @param int $source_id
 * @return array|false
 */
function get_migration_source_by_id($source_id) {
    $source_id = (int)$source_id;
    $r = db_query("SELECT * FROM migration_source WHERE source_id = '$source_id'");
    return db_fetch($r);
}

/**
 * Open a PDO connection to the external database.
 *
 * @param array $source migration_source row
 * @return PDO|false
 */
function connect_external_db($source) {
    $password = decrypt_source_password($source['db_password_enc']);
    if ($password === false) return false;

    try {
        $dsn = "mysql:host={$source['db_host']};port={$source['db_port']};dbname={$source['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $source['db_user'], $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Migration: external DB connection failed — " . $e->getMessage());
        return false;
    }
}

// ─── MAPPING LOOKUPS ─────────────────────────────────────────────────────────

/**
 * Look up a migration map entry by external user ID.
 *
 * @param int    $source_id
 * @param string $external_id
 * @return array|false
 */
function get_migration_map_by_external_id($source_id, $external_id) {
    $source_id   = (int)$source_id;
    $external_id = sanitize($external_id, SQL);
    $r = db_query("SELECT * FROM user_migration_map WHERE source_id = '$source_id' AND external_user_id = '$external_id'");
    return db_fetch($r);
}

/**
 * Look up a migration map entry by email.
 *
 * @param string $email
 * @return array|false
 */
function get_migration_map_by_email($email) {
    $email = sanitize($email, SQL);
    $r = db_query("SELECT * FROM user_migration_map WHERE external_email = '$email' LIMIT 1");
    return db_fetch($r);
}

// ─── LEGACY PASSWORD VERIFICATION ───────────────────────────────────────────

/**
 * Verify a plaintext password against a legacy hash using the specified algorithm.
 *
 * @param string      $password       Plaintext password
 * @param string      $hash           Legacy hash from external system
 * @param string      $algo           Algorithm: bcrypt, md5, sha256, sha512, argon2, sha1
 * @param string|null $salt           Global salt the old app used (if any)
 * @param string      $salt_position  'append' = password+salt, 'prepend' = salt+password
 * @return bool
 */
function verify_legacy_password($password, $hash, $algo, $salt = null, $salt_position = 'append') {
    // Build the salted password based on position
    if ($salt) {
        $salted = ($salt_position === 'prepend') ? $salt . $password : $password . $salt;
    } else {
        $salted = $password;
    }

    switch (strtolower($algo)) {
        case 'bcrypt':
            return password_verify($salted, $hash);

        case 'argon2':
        case 'argon2i':
        case 'argon2id':
            return password_verify($salted, $hash);

        case 'md5':
            return hash_equals($hash, md5($salted));

        case 'sha256':
            return hash_equals($hash, hash('sha256', $salted));

        case 'sha512':
            return hash_equals($hash, hash('sha512', $salted));

        case 'sha1':
            return hash_equals($hash, sha1($salted));

        default:
            // Try password_verify as a generic fallback (works for bcrypt/argon2)
            return password_verify($salted, $hash);
    }
}

/**
 * Attempt to log in a user using their legacy (pre-migration) password.
 * If successful, rehashes the password with the core algorithm and updates the user record.
 *
 * @param string $email    User's email
 * @param string $password Plaintext password
 * @return array|false     Core user row on success, false on failure
 */
function attempt_legacy_login($email, $password) {
    $map = get_migration_map_by_email($email);
    if (!$map) return false;
    if ($map['password_migrated']) return false;
    if (empty($map['legacy_password_hash'])) return false;

    // Load source config for salt and position
    $source        = get_migration_source_by_id($map['source_id']);
    $salt          = $source ? $source['password_salt'] : null;
    $salt_position = $source ? ($source['salt_position'] ?? 'append') : 'append';

    $algo = $map['legacy_hash_algo'] ?: ($source ? $source['password_algo'] : 'bcrypt');

    if (!verify_legacy_password($password, $map['legacy_password_hash'], $algo, $salt, $salt_position)) {
        return false;
    }

    // Legacy password verified — rehash with core algorithm
    $core_user_id = (int)$map['core_user_id'];
    if (!$core_user_id) return false;

    $new_hash = hash_password($password);
    db_query("UPDATE user SET password = '$new_hash' WHERE user_id = '$core_user_id'");

    // Clear legacy hash, mark migrated
    $map_id = (int)$map['map_id'];
    db_query("UPDATE user_migration_map SET legacy_password_hash = NULL, password_migrated = 1 WHERE map_id = '$map_id'");

    return get_user($core_user_id);
}

// ─── CORE SYNC FUNCTION ─────────────────────────────────────────────────────

/**
 * Sync a single external user into the core system.
 *
 * @param array $source  migration_source row
 * @param array $ext_user Associative array with keys matching source column mappings:
 *                        [id, email, password, first_name, last_name]
 * @return array ['core_user_id' => int|null, 'status' => string, 'reason' => string|null]
 */
function sync_external_user($source, $ext_user) {
    $source_id   = (int)$source['source_id'];
    $external_id = (string)$ext_user['id'];
    $email       = trim($ext_user['email']);
    $first_name  = trim($ext_user['first_name'] ?? '');
    $last_name   = trim($ext_user['last_name'] ?? '');
    $password    = $ext_user['password'] ?? null;

    // 1. Already mapped?
    $existing_map = get_migration_map_by_external_id($source_id, $external_id);
    if ($existing_map && $existing_map['sync_status'] === 'synced') {
        return ['core_user_id' => (int)$existing_map['core_user_id'], 'status' => 'already_synced', 'reason' => null];
    }

    // 2. Check if email already exists in core
    $core_user = get_user_by_email($email);

    if ($core_user) {
        // Link mapping to existing core user
        $core_id = (int)$core_user['user_id'];
        $safe_ext_id = sanitize($external_id, SQL);
        $safe_email  = sanitize($email, SQL);

        if ($existing_map) {
            $map_id = (int)$existing_map['map_id'];
            db_query("UPDATE user_migration_map
                      SET core_user_id = '$core_id', sync_status = 'synced', synced_at = NOW()
                      WHERE map_id = '$map_id'");
        } else {
            $safe_hash = $password ? sanitize($password, SQL) : '';
            $safe_algo = sanitize($source['password_algo'], SQL);
            db_query("INSERT INTO user_migration_map
                      (source_id, external_user_id, core_user_id, external_email, legacy_password_hash, legacy_hash_algo, sync_status, synced_at)
                      VALUES ('$source_id', '$safe_ext_id', '$core_id', '$safe_email',
                              " . ($password ? "'$safe_hash'" : "NULL") . ",
                              '$safe_algo', 'synced', NOW())");
        }

        return ['core_user_id' => $core_id, 'status' => 'synced', 'reason' => 'linked_existing'];
    }

    // 3. Create new core user
    $shard_id   = get_least_loaded_shard();
    $safe_email = sanitize($email, SQL);

    // Password is NULL in core — legacy hash lives in mapping table until rehash
    $r = db_query("INSERT INTO user (email, shard_id, is_confirmed, created_date)
                    VALUES ('$safe_email', '$shard_id', 1, NOW())");

    if (!$r) {
        // Record conflict
        $safe_ext_id = sanitize($external_id, SQL);
        $safe_email2 = sanitize($email, SQL);
        $err_msg     = sanitize(db_error(), SQL);
        if ($existing_map) {
            $map_id = (int)$existing_map['map_id'];
            db_query("UPDATE user_migration_map SET sync_status = 'conflict', conflict_reason = '$err_msg' WHERE map_id = '$map_id'");
        } else {
            db_query("INSERT INTO user_migration_map
                      (source_id, external_user_id, external_email, sync_status, conflict_reason)
                      VALUES ('$source_id', '$safe_ext_id', '$safe_email2', 'conflict', '$err_msg')");
        }
        return ['core_user_id' => null, 'status' => 'conflict', 'reason' => db_error()];
    }

    $new_id = db_insert_id();

    // Create profile on shard
    prime_shard($shard_id);
    db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                    VALUES ('$new_id', '" . sanitize($first_name, SQL) . "', '" . sanitize($last_name, SQL) . "', NOW())");

    // Create homedir
    $_SESSION['shard_id'] = $shard_id;
    create_home_dir_id($new_id);
    if (!isset($_SESSION['user_id'])) {
        unset($_SESSION['shard_id']);
    }

    // 4. Create mapping
    $safe_ext_id = sanitize($external_id, SQL);
    $safe_email  = sanitize($email, SQL);
    $safe_hash   = $password ? sanitize($password, SQL) : '';
    $safe_algo   = sanitize($source['password_algo'], SQL);

    if ($existing_map) {
        $map_id = (int)$existing_map['map_id'];
        db_query("UPDATE user_migration_map
                  SET core_user_id = '$new_id',
                      legacy_password_hash = " . ($password ? "'$safe_hash'" : "NULL") . ",
                      legacy_hash_algo = '$safe_algo',
                      sync_status = 'synced', synced_at = NOW()
                  WHERE map_id = '$map_id'");
    } else {
        db_query("INSERT INTO user_migration_map
                  (source_id, external_user_id, core_user_id, external_email, legacy_password_hash, legacy_hash_algo, sync_status, synced_at)
                  VALUES ('$source_id', '$safe_ext_id', '$new_id', '$safe_email',
                          " . ($password ? "'$safe_hash'" : "NULL") . ",
                          '$safe_algo', 'synced', NOW())");
    }

    return ['core_user_id' => $new_id, 'status' => 'synced', 'reason' => 'created_new'];
}

// ─── BATCH SYNC ─────────────────────────────────────────────────────────────

/**
 * Sync a batch of users from the external database.
 *
 * @param array    $source migration_source row
 * @param int      $limit  Max users per batch
 * @param int      $offset Starting offset
 * @param string|null $since Only sync users created/modified after this datetime (for incremental)
 * @return array   ['total' => int, 'synced' => int, 'conflicts' => int, 'skipped' => int, 'already' => int]
 */
function sync_batch($source, $limit = 500, $offset = 0, $since = null) {
    $extDb = connect_external_db($source);
    if (!$extDb) {
        return ['total' => 0, 'synced' => 0, 'conflicts' => 0, 'skipped' => 0, 'already' => 0, 'error' => 'Could not connect to external database.'];
    }

    $table      = $source['user_table'];
    $col_id     = $source['col_id'];
    $col_email  = $source['col_email'];
    $col_pass   = $source['col_password'];
    $col_fname  = $source['col_first_name'];
    $col_lname  = $source['col_last_name'];

    // Build query
    $where_parts = [];
    if (!empty($source['sync_filter_sql'])) {
        $where_parts[] = '(' . $source['sync_filter_sql'] . ')';
    }

    $params = [];
    if ($since) {
        // Try common date columns for incremental sync
        $where_parts[] = "(created_at >= :since OR updated_at >= :since2 OR created >= :since3 OR updated >= :since4)";
        $params[':since']  = $since;
        $params[':since2'] = $since;
        $params[':since3'] = $since;
        $params[':since4'] = $since;
    }

    $where = count($where_parts) > 0 ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    // Build column list
    $cols = "`$col_id` AS id, `$col_email` AS email";
    if ($col_pass) $cols .= ", `$col_pass` AS password";
    if ($col_fname) $cols .= ", `$col_fname` AS first_name";
    if ($col_lname) $cols .= ", `$col_lname` AS last_name";

    $sql = "SELECT $cols FROM `$table` $where ORDER BY `$col_id` ASC LIMIT $limit OFFSET $offset";

    try {
        $stmt = $extDb->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Migration: batch query failed — " . $e->getMessage());
        return ['total' => 0, 'synced' => 0, 'conflicts' => 0, 'skipped' => 0, 'already' => 0, 'error' => $e->getMessage()];
    }

    $stats = ['total' => count($rows), 'synced' => 0, 'conflicts' => 0, 'skipped' => 0, 'already' => 0];

    foreach ($rows as $row) {
        if (empty($row['email'])) {
            $stats['skipped']++;
            continue;
        }

        // Fill in defaults for missing columns
        if (!isset($row['password']))   $row['password']   = null;
        if (!isset($row['first_name'])) $row['first_name'] = '';
        if (!isset($row['last_name']))  $row['last_name']  = '';

        $result = sync_external_user($source, $row);

        switch ($result['status']) {
            case 'synced':        $stats['synced']++;    break;
            case 'conflict':      $stats['conflicts']++; break;
            case 'already_synced': $stats['already']++;  break;
            default:              $stats['skipped']++;   break;
        }
    }

    return $stats;
}

// ─── ON-THE-FLY HELPER (for child apps) ─────────────────────────────────────

/**
 * Ensure a user from an external system exists in core.
 * Called by child apps to on-the-fly migrate a user.
 *
 * @param string      $external_user_id  User ID in the old system
 * @param string      $email             User's email
 * @param string      $first_name        First name
 * @param string      $last_name         Last name
 * @param string|null $legacy_password_hash  Old password hash (optional)
 * @param string|null $legacy_algo       Hash algorithm (optional, defaults to source config)
 * @return int|false  Core user_id on success, false on failure
 */
function ensure_core_user($external_user_id, $email, $first_name = '', $last_name = '', $legacy_password_hash = null, $legacy_algo = null) {
    $source = get_migration_source();
    if (!$source) return false;

    $source_id = (int)$source['source_id'];

    // 1. Already mapped?
    $map = get_migration_map_by_external_id($source_id, $external_user_id);
    if ($map && $map['core_user_id'] && $map['sync_status'] === 'synced') {
        return (int)$map['core_user_id'];
    }

    // 2. Email exists in core?
    $core_user = get_user_by_email($email);
    if ($core_user) {
        // Link and return
        $core_id     = (int)$core_user['user_id'];
        $safe_ext_id = sanitize((string)$external_user_id, SQL);
        $safe_email  = sanitize($email, SQL);
        $safe_hash   = $legacy_password_hash ? sanitize($legacy_password_hash, SQL) : '';
        $safe_algo   = sanitize($legacy_algo ?: $source['password_algo'], SQL);

        if ($map) {
            $map_id = (int)$map['map_id'];
            db_query("UPDATE user_migration_map SET core_user_id = '$core_id', sync_status = 'synced', synced_at = NOW() WHERE map_id = '$map_id'");
        } else {
            db_query("INSERT INTO user_migration_map
                      (source_id, external_user_id, core_user_id, external_email, legacy_password_hash, legacy_hash_algo, sync_status, synced_at)
                      VALUES ('$source_id', '$safe_ext_id', '$core_id', '$safe_email',
                              " . ($legacy_password_hash ? "'$safe_hash'" : "NULL") . ",
                              '$safe_algo', 'synced', NOW())");
        }
        return $core_id;
    }

    // 3. Create new core user via standard sync
    $ext_user = [
        'id'         => $external_user_id,
        'email'      => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'password'   => $legacy_password_hash,
    ];

    $result = sync_external_user($source, $ext_user);
    return $result['core_user_id'] ?: false;
}

/**
 * Create a migration map entry for a SAML login.
 * Looks up the user in the external DB by email and links them.
 *
 * @param int    $core_user_id  Core user_id just logged in via SAML
 * @param string $email         User's email
 * @param string $saml_slug     SAML provider slug used for login
 * @return bool  True if mapping was created/updated
 */
function link_saml_migration_map($core_user_id, $email, $saml_slug) {
    $source = get_migration_source();
    if (!$source) return false;
    if ($source['saml_provider_slug'] !== $saml_slug) return false;

    $source_id = (int)$source['source_id'];

    // Check if already mapped
    $existing = get_migration_map_by_email($email);
    if ($existing && $existing['sync_status'] === 'synced') return true;

    // Look up in external DB
    $extDb = connect_external_db($source);
    if (!$extDb) return false;

    $col_id    = $source['col_id'];
    $col_email = $source['col_email'];
    $table     = $source['user_table'];

    try {
        $stmt = $extDb->prepare("SELECT `$col_id` AS id FROM `$table` WHERE `$col_email` = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $ext_user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Migration: SAML lookup failed — " . $e->getMessage());
        return false;
    }

    if (!$ext_user) return false;

    $safe_ext_id = sanitize((string)$ext_user['id'], SQL);
    $safe_email  = sanitize($email, SQL);
    $core_id     = (int)$core_user_id;

    if ($existing) {
        $map_id = (int)$existing['map_id'];
        db_query("UPDATE user_migration_map SET core_user_id = '$core_id', sync_status = 'synced', synced_at = NOW() WHERE map_id = '$map_id'");
    } else {
        db_query("INSERT INTO user_migration_map
                  (source_id, external_user_id, core_user_id, external_email, sync_status, synced_at)
                  VALUES ('$source_id', '$safe_ext_id', '$core_id', '$safe_email', 'synced', NOW())");
    }

    return true;
}

// ─── STATS ──────────────────────────────────────────────────────────────────

/**
 * Get migration sync statistics.
 *
 * @return array ['total' => int, 'synced' => int, 'pending' => int, 'conflicts' => int, 'skipped' => int, 'password_migrated' => int]
 */
function get_migration_stats() {
    $total     = (int)(db_fetch(db_query("SELECT COUNT(*) as cnt FROM user_migration_map"))['cnt'] ?? 0);
    $synced    = (int)(db_fetch(db_query("SELECT COUNT(*) as cnt FROM user_migration_map WHERE sync_status = 'synced'"))['cnt'] ?? 0);
    $pending   = (int)(db_fetch(db_query("SELECT COUNT(*) as cnt FROM user_migration_map WHERE sync_status = 'pending'"))['cnt'] ?? 0);
    $conflicts = (int)(db_fetch(db_query("SELECT COUNT(*) as cnt FROM user_migration_map WHERE sync_status = 'conflict'"))['cnt'] ?? 0);
    $skipped   = (int)(db_fetch(db_query("SELECT COUNT(*) as cnt FROM user_migration_map WHERE sync_status = 'skipped'"))['cnt'] ?? 0);
    $pw_done   = (int)(db_fetch(db_query("SELECT COUNT(*) as cnt FROM user_migration_map WHERE password_migrated = 1"))['cnt'] ?? 0);

    return [
        'total'              => $total,
        'synced'             => $synced,
        'pending'            => $pending,
        'conflicts'          => $conflicts,
        'skipped'            => $skipped,
        'password_migrated'  => $pw_done,
    ];
}

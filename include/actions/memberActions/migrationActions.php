<?php
/**
 * Migration Actions
 * Actions: saveMigrationSource, testMigrationConnection, runMigrationSync,
 *          getMigrationStatus, getMigrationConflicts, resolveMigrationConflict
 */

// ─── SAVE MIGRATION SOURCE ───────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'saveMigrationSource') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $source_name  = trim($_POST['source_name'] ?? '');
    $db_host      = trim($_POST['db_host'] ?? '');
    $db_port      = intval($_POST['db_port'] ?? 3306);
    $db_name      = trim($_POST['db_name'] ?? '');
    $db_user      = trim($_POST['db_user'] ?? '');
    $db_password  = $_POST['db_password'] ?? '';
    $user_table   = trim($_POST['user_table'] ?? 'users');
    $col_id       = trim($_POST['col_id'] ?? 'id');
    $col_email    = trim($_POST['col_email'] ?? 'email');
    $col_password = trim($_POST['col_password'] ?? '');
    $col_fname    = trim($_POST['col_first_name'] ?? '');
    $col_lname    = trim($_POST['col_last_name'] ?? '');
    $pw_algo      = trim($_POST['password_algo'] ?? 'bcrypt');
    $pw_salt      = trim($_POST['password_salt'] ?? '');
    $salt_pos     = trim($_POST['salt_position'] ?? 'append');
    $saml_slug    = trim($_POST['saml_provider_slug'] ?? '');
    $sync_filter  = trim($_POST['sync_filter_sql'] ?? '');
    $source_id    = intval($_POST['source_id'] ?? 0);

    if (!$source_name) { $errs['source_name'] = 'Source name is required.'; }
    if (!$db_host)     { $errs['db_host'] = 'Database host is required.'; }
    if (!$db_name)     { $errs['db_name'] = 'Database name is required.'; }
    if (!$db_user)     { $errs['db_user'] = 'Database user is required.'; }
    if (!$user_table)  { $errs['user_table'] = 'User table name is required.'; }
    if (!$col_id)      { $errs['col_id'] = 'ID column is required.'; }
    if (!$col_email)   { $errs['col_email'] = 'Email column is required.'; }
    if (!in_array($salt_pos, ['append', 'prepend'])) { $errs['salt_position'] = 'Salt position must be append or prepend.'; }
    if ($db_port < 1 || $db_port > 65535) { $errs['db_port'] = 'Invalid port number.'; }

    if (count($errs) <= 0) {
        global $db;

        // Encrypt password if provided, otherwise keep existing
        if ($source_id > 0 && $db_password === '') {
            // Keep existing password
            $existing = get_migration_source_by_id($source_id);
            $enc_password = $existing ? $existing['db_password_enc'] : '';
        } else {
            if (!$db_password) { $errs['db_password'] = 'Database password is required.'; }
            else { $enc_password = encrypt_source_password($db_password); }
        }
    }

    if (count($errs) <= 0) {
        $s_name       = sanitize($source_name, SQL);
        $s_host       = sanitize($db_host, SQL);
        $s_dbname     = sanitize($db_name, SQL);
        $s_dbuser     = sanitize($db_user, SQL);
        $s_enc_pw     = sanitize($enc_password, SQL);
        $s_table      = sanitize($user_table, SQL);
        $s_col_id     = sanitize($col_id, SQL);
        $s_col_email  = sanitize($col_email, SQL);
        $s_col_pass   = sanitize($col_password, SQL);
        $s_col_fname  = sanitize($col_fname, SQL);
        $s_col_lname  = sanitize($col_lname, SQL);
        $s_algo       = sanitize($pw_algo, SQL);
        $s_salt       = sanitize($pw_salt, SQL);
        $s_salt_pos   = sanitize($salt_pos, SQL);
        $s_saml       = sanitize($saml_slug, SQL);
        $s_filter     = sanitize($sync_filter, SQL);

        if ($source_id > 0) {
            $r = db_query("UPDATE migration_source SET
                source_name = '$s_name',
                db_host = '$s_host', db_port = '$db_port', db_name = '$s_dbname',
                db_user = '$s_dbuser', db_password_enc = '$s_enc_pw',
                user_table = '$s_table',
                col_id = '$s_col_id', col_email = '$s_col_email',
                col_password = " . ($col_password ? "'$s_col_pass'" : "NULL") . ",
                col_first_name = " . ($col_fname ? "'$s_col_fname'" : "NULL") . ",
                col_last_name = " . ($col_lname ? "'$s_col_lname'" : "NULL") . ",
                password_algo = '$s_algo',
                password_salt = " . ($pw_salt ? "'$s_salt'" : "NULL") . ",
                salt_position = '$s_salt_pos',
                saml_provider_slug = " . ($saml_slug ? "'$s_saml'" : "NULL") . ",
                sync_filter_sql = " . ($sync_filter ? "'$s_filter'" : "NULL") . "
                WHERE source_id = '$source_id'");
        } else {
            $r = db_query("INSERT INTO migration_source
                (source_name, db_host, db_port, db_name, db_user, db_password_enc,
                 user_table, col_id, col_email, col_password, col_first_name, col_last_name,
                 password_algo, password_salt, salt_position, saml_provider_slug, sync_filter_sql)
                VALUES ('$s_name', '$s_host', '$db_port', '$s_dbname', '$s_dbuser', '$s_enc_pw',
                        '$s_table', '$s_col_id', '$s_col_email',
                        " . ($col_password ? "'$s_col_pass'" : "NULL") . ",
                        " . ($col_fname ? "'$s_col_fname'" : "NULL") . ",
                        " . ($col_lname ? "'$s_col_lname'" : "NULL") . ",
                        '$s_algo', " . ($pw_salt ? "'$s_salt'" : "NULL") . ", '$s_salt_pos',
                        " . ($saml_slug ? "'$s_saml'" : "NULL") . ",
                        " . ($sync_filter ? "'$s_filter'" : "NULL") . ")");
        }

        if ($r) {
            $data['source_id'] = $source_id ?: db_insert_id();
            $_SESSION['success'] = 'Migration source saved.';
        } else {
            $errs['db'] = db_error();
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── TEST CONNECTION ─────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'testMigrationConnection') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $source_id = intval($_POST['source_id'] ?? 0);
    if (!$source_id) { $errs['source'] = 'No migration source configured.'; }

    if (count($errs) <= 0) {
        $source = get_migration_source_by_id($source_id);
        if (!$source) { $errs['source'] = 'Migration source not found.'; }
    }

    if (count($errs) <= 0) {
        $extDb = connect_external_db($source);
        if (!$extDb) {
            $errs['connection'] = 'Could not connect to external database. Check credentials.';
        }
    }

    if (count($errs) <= 0) {
        try {
            $table = $source['user_table'];
            $where = !empty($source['sync_filter_sql']) ? 'WHERE ' . $source['sync_filter_sql'] : '';
            $stmt  = $extDb->query("SELECT COUNT(*) as cnt FROM `$table` $where");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            $data['user_count']  = (int)$count;
            $data['table']       = $table;
            $_SESSION['success'] = "Connection successful. Found $count users in `$table`.";
        } catch (PDOException $e) {
            $errs['query'] = 'Connected but query failed: ' . $e->getMessage();
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── RUN SYNC ────────────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'runMigrationSync') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $source_id = intval($_POST['source_id'] ?? 0);
    if (!$source_id) { $errs['source'] = 'No migration source configured.'; }

    if (count($errs) <= 0) {
        $source = get_migration_source_by_id($source_id);
        if (!$source) { $errs['source'] = 'Migration source not found.'; }
    }

    if (count($errs) <= 0) {
        $incremental = ($_POST['incremental'] ?? '0') === '1';
        $since = $incremental ? $source['last_sync_at'] : null;

        // Sync in batches
        $total_stats = ['total' => 0, 'synced' => 0, 'conflicts' => 0, 'skipped' => 0, 'already' => 0];
        $batch_size  = 500;
        $offset      = 0;
        $max_batches = 100; // Safety limit

        for ($i = 0; $i < $max_batches; $i++) {
            $batch = sync_batch($source, $batch_size, $offset, $since);

            if (isset($batch['error'])) {
                $errs['sync'] = $batch['error'];
                break;
            }

            $total_stats['total']     += $batch['total'];
            $total_stats['synced']    += $batch['synced'];
            $total_stats['conflicts'] += $batch['conflicts'];
            $total_stats['skipped']   += $batch['skipped'];
            $total_stats['already']   += $batch['already'];

            if ($batch['total'] < $batch_size) break; // Last batch
            $offset += $batch_size;
        }

        if (count($errs) <= 0) {
            // Update last_sync_at
            $sid = (int)$source['source_id'];
            db_query("UPDATE migration_source SET last_sync_at = NOW() WHERE source_id = '$sid'");

            $data['stats'] = $total_stats;
            $_SESSION['success'] = "Sync complete: {$total_stats['synced']} synced, {$total_stats['conflicts']} conflicts, {$total_stats['skipped']} skipped, {$total_stats['already']} already mapped.";
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── GET STATUS ──────────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'getMigrationStatus') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $source = get_migration_source();
        $data['source'] = $source ?: null;

        // Remove encrypted password from response
        if ($data['source']) {
            unset($data['source']['db_password_enc']);
        }

        $data['stats'] = get_migration_stats();
        $_SESSION['success'] = 'Status loaded.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── GET CONFLICTS ───────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'getMigrationConflicts') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;

    if (count($errs) <= 0) {
        $countR = db_query("SELECT COUNT(*) as cnt FROM user_migration_map WHERE sync_status = 'conflict'");
        $total  = (int)(db_fetch($countR)['cnt'] ?? 0);

        $r = db_query("SELECT * FROM user_migration_map WHERE sync_status = 'conflict' ORDER BY created DESC LIMIT $offset, $per_page");
        $conflicts = db_fetch_all($r);

        $data['conflicts'] = $conflicts;
        $data['total']     = $total;
        $data['page']      = $page;
        $data['pages']     = ceil($total / $per_page);
        $_SESSION['success'] = 'Conflicts loaded.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── RESOLVE CONFLICT ────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'resolveMigrationConflict') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $map_id     = intval($_POST['map_id'] ?? 0);
    $resolution = $_POST['resolution'] ?? '';

    if (!$map_id) { $errs['map_id'] = 'Map entry ID required.'; }
    if (!in_array($resolution, ['link', 'create', 'skip'])) { $errs['resolution'] = 'Invalid resolution.'; }

    if (count($errs) <= 0) {
        $map = db_fetch(db_query("SELECT * FROM user_migration_map WHERE map_id = '$map_id'"));
        if (!$map) { $errs['map_id'] = 'Map entry not found.'; }
    }

    if (count($errs) <= 0) {
        if ($resolution === 'link') {
            // Link to existing core user by email
            $core_user = get_user_by_email($map['external_email']);
            if (!$core_user) {
                $errs['resolve'] = 'No core user found with email ' . $map['external_email'];
            } else {
                $core_id = (int)$core_user['user_id'];
                db_query("UPDATE user_migration_map SET core_user_id = '$core_id', sync_status = 'synced', conflict_reason = NULL, synced_at = NOW() WHERE map_id = '$map_id'");
                $_SESSION['success'] = 'Conflict resolved: linked to existing user.';
            }
        } elseif ($resolution === 'create') {
            // Force-create a new core user
            $source = get_migration_source_by_id($map['source_id']);
            if (!$source) {
                $errs['resolve'] = 'Migration source not found.';
            } else {
                $ext_user = [
                    'id'         => $map['external_user_id'],
                    'email'      => $map['external_email'],
                    'first_name' => '',
                    'last_name'  => '',
                    'password'   => $map['legacy_password_hash'],
                ];
                $result = sync_external_user($source, $ext_user);
                if ($result['status'] === 'synced') {
                    $_SESSION['success'] = 'Conflict resolved: new user created.';
                } else {
                    $errs['resolve'] = 'Still could not create user: ' . ($result['reason'] ?? 'unknown error');
                }
            }
        } elseif ($resolution === 'skip') {
            db_query("UPDATE user_migration_map SET sync_status = 'skipped', conflict_reason = 'Manually skipped by admin' WHERE map_id = '$map_id'");
            $_SESSION['success'] = 'Conflict resolved: entry skipped.';
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── TOGGLE SYNC ─────────────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'toggleMigrationSync') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $source_id = intval($_POST['source_id'] ?? 0);
    if (!$source_id) { $errs['source'] = 'No migration source configured.'; }

    if (count($errs) <= 0) {
        $source = get_migration_source_by_id($source_id);
        if (!$source) { $errs['source'] = 'Migration source not found.'; }
    }

    if (count($errs) <= 0) {
        $new_state = $source['sync_enabled'] ? 0 : 1;
        db_query("UPDATE migration_source SET sync_enabled = '$new_state' WHERE source_id = '$source_id'");
        $label = $new_state ? 'enabled' : 'disabled';
        $_SESSION['success'] = "Recurring sync $label.";
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

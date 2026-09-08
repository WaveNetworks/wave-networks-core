<?php
/**
 * shardAdminActions.php
 * Shard registry admin surface, authenticated via service API key (Bearer token).
 * Scope: shards:admin
 *
 * These run in the ADMIN context on whichever host owns the app, and are what
 * nokemo's shard console drives through monitorCallAppAdmin — so nokemo never
 * holds a shard's database password, and the operator never edits config.php on
 * a server.
 *
 * Actions: shardList · shardAdd · shardTest · shardMigrate · shardSetStatus · shardStats
 *
 * A NOTE ON MIGRATING CHILD SHARDS: shardMigrate covers the ADMIN layer only.
 * A child app's shard schema version and migration directory belong to the child
 * app, not to core, so a child shard is migrated by that app's own bootstrap and
 * cron once the row exists — which is also the only place that can know what
 * version it should be at. Registry CRUD below works for both layers.
 *
 * Passwords are write-only across this surface: nothing here ever returns
 * db_pass_enc or a plaintext password.
 */

/**
 * Strip the stored secret from a row before it leaves the process.
 */
function _shard_api_public_row($row) {
    unset($row['db_pass_enc']);
    $row['size_mb']    = (float)$row['size_mb'];
    $row['user_count'] = (int)$row['user_count'];
    $row['pct_of_cap'] = min(100, round(($row['size_mb'] / DB_SIZE_LIMIT_MB) * 100, 1));
    return $row;
}

function _shard_api_layer() {
    $layer = trim((string)($_POST['layer'] ?? $_GET['layer'] ?? 'admin'));
    return in_array($layer, ['admin', 'child'], true) ? $layer : 'admin';
}

function _shard_api_slug() {
    return trim((string)($_POST['app_slug'] ?? $_GET['app_slug'] ?? ''));
}

/**
 * Find one registry row, or null.
 */
function _shard_api_find($layer, $slug, $shard_id) {
    foreach (shard_config_rows($layer, $slug) as $row) {
        if ($row['shard_id'] === $shard_id) return $row;
    }
    return null;
}

// ─── shardList ──────────────────────────────────────────────────────────────

if (($action ?? null) == 'shardList') {
    if (require_api_scope('shards:admin')) {
        $layer = _shard_api_layer();
        $slug  = _shard_api_slug();

        ensure_shard_config_table();

        $rows = array_map('_shard_api_public_row', shard_config_rows($layer, $slug));

        // config.php's seed shards are not registry rows. Surfacing them keeps
        // the console honest about what the app is actually running on — they
        // are the shards you cannot manage from here until they are adopted.
        global $shardConfigs;
        $seed = [];
        if ($layer === 'admin') {
            $known = array_column($rows, 'shard_id');
            foreach (array_keys((array)$shardConfigs) as $sid) {
                if (!in_array($sid, $known, true)) $seed[] = $sid;
            }
        }

        $data['shards']        = $rows;
        $data['seed_only']     = $seed;
        $data['next_shard_id'] = shard_config_next_id($layer, $slug);
        $data['high_water_mb'] = shard_high_water_mb();
        $data['limit_mb']      = DB_SIZE_LIMIT_MB;
        $_SESSION['success']   = 'OK';
    }
}

// ─── shardAdd ───────────────────────────────────────────────────────────────

if (($action ?? null) == 'shardAdd') {
    if (require_api_scope('shards:admin')) {
        $layer    = _shard_api_layer();
        $slug     = _shard_api_slug();
        $shard_id = trim((string)($_POST['shard_id'] ?? ''));

        // The row is written BEFORE anything is tested. A failed connection test
        // must never discard credentials that were just typed in — the operator
        // would have to go back to the host control panel and reset the password
        // to recover them, since a panel does not show an existing one twice.
        list($ok, $err) = shard_config_upsert(
            $layer, $slug, $shard_id,
            (string)($_POST['db_host'] ?? ''),
            (string)($_POST['db_name'] ?? ''),
            (string)($_POST['db_user'] ?? ''),
            array_key_exists('db_pass', $_POST) ? (string)$_POST['db_pass'] : '',
            array_key_exists('notes', $_POST) ? (string)$_POST['notes'] : null
        );

        if (!$ok) {
            $_SESSION['error'] = $err;
        } else {
            $row = _shard_api_find($layer, $slug, $shard_id);
            list($conn, $terr) = shard_config_connect($row);
            shard_config_record_error($layer, $slug, $shard_id, $terr ?: null);

            $data['shard_id']  = $shard_id;
            $data['saved']     = true;
            $data['connected'] = (bool)$conn;
            $data['status']    = $row['status'];
            if ($conn) {
                $_SESSION['success'] = "Shard $shard_id saved and reachable. Migrate it next.";
            } else {
                // Saved is saved. The test result is information, not a rollback.
                $data['test_error']  = $terr;
                $_SESSION['warning'] = "Shard $shard_id saved, but the connection test failed: $terr";
            }
        }
    }
}

// ─── shardTest ──────────────────────────────────────────────────────────────

if (($action ?? null) == 'shardTest') {
    if (require_api_scope('shards:admin')) {
        $layer = _shard_api_layer();
        $slug  = _shard_api_slug();
        $row   = _shard_api_find($layer, $slug, trim((string)($_POST['shard_id'] ?? '')));

        if (!$row) {
            $_SESSION['error'] = 'Unknown shard.';
        } else {
            list($conn, $err) = shard_config_connect($row);
            shard_config_record_error($layer, $slug, $row['shard_id'], $err ?: null);
            $data['connected'] = (bool)$conn;
            if ($conn) {
                $data['schema_version'] = get_current_db_version($conn);
                $_SESSION['success']    = 'Connection OK.';
            } else {
                $data['test_error'] = $err;
                $_SESSION['error']  = "Connection failed: $err";
            }
        }
    }
}

// ─── shardMigrate ───────────────────────────────────────────────────────────

if (($action ?? null) == 'shardMigrate') {
    if (require_api_scope('shards:admin')) {
        global $shard_version;
        $layer = _shard_api_layer();
        $slug  = _shard_api_slug();
        $row   = _shard_api_find($layer, $slug, trim((string)($_POST['shard_id'] ?? '')));

        if (!$row) {
            $_SESSION['error'] = 'Unknown shard.';
        } elseif ($layer !== 'admin') {
            // See the header note: core does not know a child app's shard schema
            // version or migration directory.
            $_SESSION['error'] = 'Child shards are migrated by their own app once the row exists.';
        } else {
            list($conn, $err) = shard_config_connect($row);
            if (!$conn) {
                shard_config_record_error($layer, $slug, $row['shard_id'], $err);
                $_SESSION['error'] = "Cannot connect: $err";
            } else {
                $base_dir = defined('APP_MIGRATION_DIR')
                    ? APP_MIGRATION_DIR
                    : (__DIR__ . '/../../../db_migrations/');

                $current = get_current_db_version($conn);
                $ran     = [];

                foreach (get_available_migrations('shard', $base_dir) as $ver) {
                    if ($ver <= $current)       continue;
                    if ($ver > $shard_version)  break;
                    $file = rtrim($base_dir, '/') . '/shard/' . number_format($ver, 1, '.', '') . '.sql';
                    if (!file_exists($file))    continue;
                    run_migration($conn, $file, "shard/{$row['shard_id']}", $ver);
                    $ran[] = $ver;
                }

                $now = get_current_db_version($conn);
                db_query_prepared(
                    "UPDATE shard_config SET schema_version = ?, last_migrated = NOW(), updated = NOW()
                      WHERE layer = ? AND app_slug = ? AND shard_id = ?",
                    [$now, $layer, $slug, $row['shard_id']]
                );

                $data['schema_version'] = $now;
                $data['target_version'] = $shard_version;
                $data['migrations_run'] = $ran;
                $data['at_target']      = ($now >= $shard_version);

                if ($now >= $shard_version) {
                    shard_config_record_error($layer, $slug, $row['shard_id'], null);
                    $_SESSION['success'] = "Shard {$row['shard_id']} migrated to v$now. Activate it to start assigning users.";
                } else {
                    shard_config_record_error($layer, $slug, $row['shard_id'], "Stalled at v$now, target v$shard_version");
                    $_SESSION['error'] = "Migration stalled at v$now (target v$shard_version).";
                }
            }
        }
    }
}

// ─── shardSetStatus ─────────────────────────────────────────────────────────

if (($action ?? null) == 'shardSetStatus') {
    if (require_api_scope('shards:admin')) {
        global $shard_version;
        $layer  = _shard_api_layer();
        $slug   = _shard_api_slug();
        $status = trim((string)($_POST['status'] ?? ''));
        $row    = _shard_api_find($layer, $slug, trim((string)($_POST['shard_id'] ?? '')));

        if (!$row) {
            $_SESSION['error'] = 'Unknown shard.';
        } elseif ($status === 'active' && $layer === 'admin'
                  && (float)$row['schema_version'] < (float)$shard_version) {
            // Activating an unmigrated shard hands the next registration a
            // database with no schema. The console runs migrate before activate;
            // this is the backstop for anyone calling the API directly.
            $_SESSION['error'] = "Shard {$row['shard_id']} is at v"
                . (float)$row['schema_version'] . ", target v$shard_version. Migrate it first.";
        } elseif ($status === 'disabled' && (int)$row['user_count'] > 0) {
            // Disabling drops the shard out of $shardConfigs entirely, so every
            // user pinned to it loses their data access. Draining is the safe
            // way to retire a shard that still holds users.
            $_SESSION['error'] = "Shard {$row['shard_id']} still holds {$row['user_count']} users. "
                . "Use 'draining' to stop new assignments without cutting them off.";
        } else {
            list($ok, $err) = shard_config_set_status($layer, $slug, $row['shard_id'], $status);
            if ($ok) {
                $data['shard_id']    = $row['shard_id'];
                $data['status']      = $status;
                $_SESSION['success'] = "Shard {$row['shard_id']} is now $status.";
            } else {
                $_SESSION['error'] = $err;
            }
        }
    }
}

// ─── shardStats ─────────────────────────────────────────────────────────────

if (($action ?? null) == 'shardStats') {
    if (require_api_scope('shards:admin')) {
        $layer = _shard_api_layer();
        $slug  = _shard_api_slug();

        if (!empty($_POST['refresh'])) {
            if ($layer === 'admin') refresh_shard_config_user_counts();
            refresh_shard_config_sizes($layer, $slug);
        }

        $all        = shard_config_rows($layer, $slug);
        $assignable = shard_assignable_rows($layer, $slug);
        $high       = shard_high_water_mb();

        $headroom = 0.0;
        foreach ($assignable as $row) $headroom += max(0, $high - (float)$row['size_mb']);

        $by_status = [];
        $used      = 0.0;
        foreach ($all as $row) {
            $by_status[$row['status']] = ($by_status[$row['status']] ?? 0) + 1;
            $used += (float)$row['size_mb'];
        }

        $data['total_shards']     = count($all);
        $data['by_status']        = $by_status;
        $data['accepting_new']    = count($assignable);
        $data['headroom_mb']      = round($headroom, 1);
        $data['used_mb']          = round($used, 1);
        $data['high_water_mb']    = $high;
        $data['limit_mb']         = DB_SIZE_LIMIT_MB;
        // The condition that breaks registration outright.
        $data['capacity_alarm']   = (count($assignable) === 0 && count($all) > 0);
        $_SESSION['success']      = 'OK';
    }
}

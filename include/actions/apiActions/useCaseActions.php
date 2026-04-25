<?php
/**
 * Use-case API Actions — feed the dynamic Playwright test suite.
 *
 *   apiListTestSessionActions  read user_action_log for the test user
 *                              (is_test_account=1) on a given child app,
 *                              grouped by session, ordered by created.
 *   apiListUseCases            list use_case rows for a source_app
 *   apiGetUseCase              fetch a single use_case by id or slug
 *   apiUpsertUseCase           UPSERT a use_case (key: source_app+slug)
 *   apiRecordUseCaseTestRun    INSERT a use_case_test_run row
 *   apiListUseCaseTestRuns     list runs for a use_case (latest first)
 *
 * Read scope: actions:read   (logs + use_cases)
 * Write scope: tests:write   (use_case + use_case_test_run mutations)
 *
 * The user_action_log table is sharded — actions for any given user
 * live in their assigned shard. We resolve the test user's shard from
 * the main `user` row before querying.
 */

// ── Bootstrap-only self-scope grant ────────────────────────────────────
// Reason: when a new admin instance comes online, its existing service
// keys don't yet have the actions:read / tests:write scopes the test
// runner needs. The api_keys.php Edit-modal UI bug stranded several
// admins; this is the curl-friendly bootstrap path.
//
// Security: the calling key authenticates itself via Bearer header
// (validated by common_api.php into $_SERVICE_API_KEY before any action
// runs). It can ONLY add scopes from a hard-coded bootstrap allowlist —
// not arbitrary scopes — so a compromised key cannot escalate to
// (e.g.) feedback:admin or stripe:write through this path.
if (($action ?? null) == 'apiAdminGrantSelfScopes') {
    global $_SERVICE_API_KEY;
    $errs = [];
    $req = $_POST['scopes'] ?? '';
    if (is_string($req)) {
        $req = array_filter(array_map('trim', explode(',', $req)));
    }
    $req = is_array($req) ? array_values(array_unique($req)) : [];

    $bootstrap_allow = ['actions:read', 'tests:write'];
    foreach ($req as $s) {
        if (!in_array($s, $bootstrap_allow, true)) {
            $errs[] = "scope '$s' is not in the bootstrap allowlist (" .
                      implode(', ', $bootstrap_allow) . ")";
        }
    }
    if (empty($req))             $errs[] = 'scopes is required.';
    if (empty($_SERVICE_API_KEY)) $errs[] = 'No service key on request.';

    if (empty($errs)) {
        $key_id   = (int)$_SERVICE_API_KEY['service_key_id'];
        $existing = json_decode($_SERVICE_API_KEY['scopes'] ?? '[]', true);
        if (!is_array($existing)) $existing = [];
        $merged   = array_values(array_unique(array_merge($existing, $req)));
        db_query_prepared(
            "UPDATE service_api_key SET scopes = ? WHERE service_key_id = ?",
            [json_encode($merged), $key_id]
        );
        $data['service_key_id'] = $key_id;
        $data['key_prefix']     = $_SERVICE_API_KEY['key_prefix'] ?? null;
        $data['before_scopes']  = $existing;
        $data['after_scopes']   = $merged;
        $data['added']          = array_values(array_diff($merged, $existing));
        $_SESSION['success']    = 'Scopes granted.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Idempotent test-user seed (mirrors create_test_user.php) ───────────
// Per `admin/CLAUDE.md` the canonical cross-host test user is
// nokemo@nokemo.com with is_test_account=1. The CLI seed script
// (admin/scripts/create_test_user.php) only runs on hosts where the
// admin/operator has shell access; this HTTP twin lets nokemo bootstrap
// the test user across every registered app it administers.
//
// Idempotent: if the user already exists with the flag set, returns
// the existing row. If it exists without the flag, sets it. Safe to
// re-run.
if (($action ?? null) == 'apiAdminEnsureTestUser') {
    if (require_api_scope('tests:write')) {
        $email     = 'nokemo@nokemo.com';
        $s_email   = sanitize($email, SQL);
        $existing  = db_fetch(db_query(
            "SELECT user_id, shard_id, is_test_account
             FROM `user` WHERE email = '$s_email'"
        ));

        if ($existing) {
            $uid   = (int)$existing['user_id'];
            $shard = $existing['shard_id'];
            if ((int)$existing['is_test_account'] !== 1) {
                db_query("UPDATE `user` SET is_test_account = 1
                          WHERE user_id = '$uid'");
                $note = 'flag fixed';
            } else {
                $note = 'already exists';
            }
            $data['user_id']        = $uid;
            $data['shard_id']       = $shard;
            $data['email']          = $email;
            $data['is_test_account']= 1;
            $data['note']           = $note;
            $_SESSION['success']    = 'OK';
        } else {
            $shard_id  = function_exists('get_least_loaded_shard')
                ? get_least_loaded_shard()
                : 'shard1';
            $password  = bin2hex(random_bytes(32));
            $hashed    = hash_password($password);
            $confirm   = generateHashCode(100);
            $s_shard   = sanitize($shard_id, SQL);
            $s_confirm = sanitize($confirm, SQL);

            $ins = db_query(
                "INSERT INTO `user`
                   (email, password, shard_id, is_confirmed, is_test_account,
                    confirm_hash, created_date)
                 VALUES ('$s_email', '$hashed', '$s_shard', 1, 1,
                         '$s_confirm', NOW())"
            );
            if (!$ins) {
                $_SESSION['error'] = 'Failed to insert test user.';
            } else {
                $new_id = (int)db_insert_id();
                prime_shard($shard_id);
                db_query_shard($shard_id,
                    "INSERT INTO user_profile (user_id, first_name, last_name, created)
                     VALUES ('$new_id', 'Nokemo', 'Test', NOW())"
                );
                // create_home_dir_id reads $_SESSION['shard_id']
                $_SESSION['shard_id'] = $shard_id;
                if (function_exists('create_home_dir_id')) {
                    create_home_dir_id($new_id);
                }
                unset($_SESSION['shard_id']);

                $data['user_id']         = $new_id;
                $data['shard_id']        = $shard_id;
                $data['email']           = $email;
                $data['is_test_account'] = 1;
                $data['note']            = 'created';
                $_SESSION['success']     = 'OK';
            }
        }
    }
}

// ── Shared helpers ──────────────────────────────────────────────────────
function _uc_test_user(): ?array {
    // Conventionally nokemo@nokemo.com; fall back to any is_test_account
    // user if that exact account doesn't exist on this install.
    $r = db_query_prepared(
        "SELECT user_id, email, shard_id
         FROM `user`
         WHERE is_test_account = 1
         ORDER BY (email = 'nokemo@nokemo.com') DESC, user_id ASC
         LIMIT 1",
        []
    );
    if (!$r) return null;
    $row = $r->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function _uc_action_path_norm($json): string {
    // Stable shape for use_case dedup: only page+action, drop timing/params.
    if (is_string($json)) {
        $arr = json_decode($json, true) ?: [];
    } else {
        $arr = (array) $json;
    }
    $out = [];
    foreach ($arr as $step) {
        $out[] = ($step['page'] ?? '') . '|' . ($step['action'] ?? '');
    }
    return implode('>', $out);
}

// ── List per-session test-user actions on a child app ───────────────────
if (($action ?? null) == 'apiListTestSessionActions') {
    if (require_api_scope('actions:read')) {
        $app   = trim((string)($_POST['source_app'] ?? ''));
        $since = trim((string)($_POST['since']      ?? '')); // YYYY-MM-DD HH:MM:SS
        $limit = max(1, min(2000, (int)($_POST['limit'] ?? 500)));
        // Optional: derive from a specific user instead of the canonical
        // test account. Useful while bootstrapping when nobody has been
        // logging in as nokemo@nokemo.com yet but real admins have
        // generated organic action history under their own user_id.
        $email_filter = trim((string)($_POST['email'] ?? ''));

        if ($app === '') {
            $_SESSION['error'] = 'source_app is required.';
        } else {
            if ($email_filter !== '') {
                $s_email = sanitize($email_filter, SQL);
                $r = db_query_prepared(
                    "SELECT user_id, email, shard_id FROM `user` WHERE email = ? LIMIT 1",
                    [$email_filter]
                );
                $user = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
            } else {
                $user = _uc_test_user();
            }
            if (!$user) {
                $_SESSION['error'] = $email_filter !== ''
                    ? "No user with email '$email_filter'."
                    : 'No is_test_account=1 user found.';
            } else {
                prime_shard($user['shard_id']);
                $sql = "SELECT log_id, user_id, session_id, source_app, page, action,
                               params_json, result, duration_ms, created
                        FROM user_action_log
                        WHERE user_id = ? AND source_app = ?";
                $args = [(int)$user['user_id'], $app];
                if ($since !== '') {
                    $sql .= " AND created >= ?";
                    $args[] = $since;
                }
                $sql .= " ORDER BY created ASC LIMIT $limit";

                $rows = [];
                $r = db_query_shard_prepared($user['shard_id'], $sql, $args);
                if ($r) {
                    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
                        $rows[] = $row;
                    }
                }
                // Group by session_id, preserving order.
                $by_session = [];
                foreach ($rows as $row) {
                    $sid = $row['session_id'] ?: 'unknown';
                    $by_session[$sid][] = $row;
                }
                $sessions = [];
                foreach ($by_session as $sid => $entries) {
                    $sessions[] = [
                        'session_id' => $sid,
                        'started_at' => $entries[0]['created'],
                        'ended_at'   => end($entries)['created'],
                        'count'      => count($entries),
                        'actions'    => $entries,
                    ];
                }

                $data['test_user'] = [
                    'user_id'  => (int)$user['user_id'],
                    'email'    => $user['email'],
                    'shard_id' => $user['shard_id'],
                ];
                $data['source_app']   = $app;
                $data['action_count'] = count($rows);
                $data['sessions']     = $sessions;
                $_SESSION['success']  = 'OK';
            }
        }
    }
}

// ── List use_case rows for a source_app ─────────────────────────────────
if (($action ?? null) == 'apiListUseCases') {
    if (require_api_scope('actions:read')) {
        $app   = trim((string)($_POST['source_app'] ?? ''));
        $limit = max(1, min(500, (int)($_POST['limit'] ?? 100)));
        $sql = "SELECT use_case_id, source_app, slug, name, description,
                       requires_login, starting_page, ending_action, action_path,
                       test_category, test_status, derived_from_log_count,
                       last_seen_at, last_test_run_id, created, updated
                FROM use_case";
        $args = [];
        if ($app !== '') {
            $sql .= " WHERE source_app = ?";
            $args[] = $app;
        }
        $sql .= " ORDER BY source_app ASC, slug ASC LIMIT $limit";
        $r = db_query_prepared($sql, $args);
        $items = [];
        if ($r) {
            while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
                $items[] = $row;
            }
        }
        $data['items'] = $items;
        $data['count'] = count($items);
        $_SESSION['success'] = 'OK';
    }
}

// ── Fetch a single use_case by id or slug ──────────────────────────────
if (($action ?? null) == 'apiGetUseCase') {
    if (require_api_scope('actions:read')) {
        $id  = (int)($_POST['use_case_id'] ?? 0);
        $app = trim((string)($_POST['source_app'] ?? ''));
        $slug = trim((string)($_POST['slug'] ?? ''));
        if ($id > 0) {
            $r = db_query_prepared("SELECT * FROM use_case WHERE use_case_id = ?", [$id]);
        } elseif ($app !== '' && $slug !== '') {
            $r = db_query_prepared(
                "SELECT * FROM use_case WHERE source_app = ? AND slug = ?",
                [$app, $slug]
            );
        } else {
            $_SESSION['error'] = 'use_case_id, or source_app+slug, is required.';
            $r = null;
        }
        if ($r) {
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $data['use_case'] = $row;
                $_SESSION['success'] = 'OK';
            } else {
                $_SESSION['error'] = 'Not found.';
            }
        }
    }
}

// ── UPSERT a use_case row (key: source_app + slug) ──────────────────────
if (($action ?? null) == 'apiUpsertUseCase') {
    if (require_api_scope('tests:write')) {
        $errs = [];
        $app   = trim((string)($_POST['source_app']    ?? ''));
        $slug  = trim((string)($_POST['slug']          ?? ''));
        $name  = trim((string)($_POST['name']          ?? ''));
        $desc  = trim((string)($_POST['description']   ?? ''));
        $req   = (int)($_POST['requires_login']        ?? 1);
        $start = trim((string)($_POST['starting_page'] ?? ''));
        $end   = trim((string)($_POST['ending_action'] ?? ''));
        $path  = $_POST['action_path']                 ?? '';
        $cat   = trim((string)($_POST['test_category'] ?? 'feature'));

        if ($app === '')  $errs[] = 'source_app is required.';
        if ($slug === '') $errs[] = 'slug is required.';
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            $errs[] = 'slug must be lowercase letters/digits/dash/underscore.';
        }
        $allowed_cat = ['preflight','auth','smoke','feature','accessibility'];
        if (!in_array($cat, $allowed_cat, true)) $cat = 'feature';

        // action_path may arrive as a JSON string or a pre-encoded blob.
        if (is_array($path)) {
            $path_json = json_encode($path);
        } else {
            $maybe = json_decode((string)$path, true);
            $path_json = is_array($maybe) ? json_encode($maybe) : (string)$path;
        }
        $log_count = (int)($_POST['derived_from_log_count'] ?? 0);

        if (empty($errs)) {
            $sql = "INSERT INTO use_case
                    (source_app, slug, name, description, requires_login,
                     starting_page, ending_action, action_path, test_category,
                     derived_from_log_count, last_seen_at, created, updated)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                       name = VALUES(name),
                       description = VALUES(description),
                       requires_login = VALUES(requires_login),
                       starting_page = VALUES(starting_page),
                       ending_action = VALUES(ending_action),
                       action_path = VALUES(action_path),
                       test_category = VALUES(test_category),
                       derived_from_log_count = VALUES(derived_from_log_count),
                       last_seen_at = NOW(),
                       updated = NOW()";
            db_query_prepared($sql, [
                $app, $slug, $name, $desc, $req,
                $start ?: null, $end ?: null, $path_json, $cat, $log_count,
            ]);
            $r = db_query_prepared(
                "SELECT * FROM use_case WHERE source_app = ? AND slug = ?",
                [$app, $slug]
            );
            $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
            $data['use_case']   = $row;
            $data['fingerprint'] = _uc_action_path_norm($path_json);
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = implode('<br>', $errs);
        }
    }
}

// ── Record a run result + bump use_case.last_test_run_id ────────────────
if (($action ?? null) == 'apiRecordUseCaseTestRun') {
    if (require_api_scope('tests:write')) {
        $errs = [];
        $uid     = (int)($_POST['use_case_id']         ?? 0);
        $perm    = trim((string)($_POST['permutation']  ?? 'authed'));
        $status  = trim((string)($_POST['status']       ?? 'pass'));
        $err     = trim((string)($_POST['fail_reason']  ?? ''));
        $dur     = (int)($_POST['duration_ms']         ?? 0);
        $screens = $_POST['screenshot_paths']          ?? '';
        $axe     = $_POST['axe_violations']            ?? '';
        $cons    = $_POST['console_errors']            ?? '';

        if ($uid <= 0) $errs[] = 'use_case_id is required.';
        // run.status values per migration 3.4 enum
        $allowed_run = ['pass','fail','flaky','skipped'];
        if (!in_array($status, $allowed_run, true)) $status = 'pass';

        $to_json = function ($x) {
            if (is_array($x)) return json_encode($x);
            $maybe = json_decode((string)$x, true);
            return is_array($maybe) ? json_encode($maybe) : '[]';
        };
        $screens_json = $to_json($screens);
        $axe_json     = $to_json($axe);
        $cons_json    = $to_json($cons);

        if (empty($errs)) {
            db_query_prepared(
                "INSERT INTO use_case_test_run
                 (use_case_id, run_at, permutation, status, duration_ms,
                  screenshot_paths, axe_violations_json, console_errors_json,
                  fail_reason)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)",
                [$uid, $perm, $status, $dur ?: null,
                 $screens_json, $axe_json, $cons_json,
                 $err ?: null]
            );
            $rid_row = db_query("SELECT LAST_INSERT_ID() AS rid");
            $rid = $rid_row ? (int)$rid_row->fetch(PDO::FETCH_ASSOC)['rid'] : 0;

            // Map run.status (pass/fail/flaky/skipped) onto the parent
            // use_case.test_status enum (pending/passing/failing/flaky/disabled)
            // so the list view colour-codes off a single column.
            $uc_status_map = [
                'pass'    => 'passing',
                'fail'    => 'failing',
                'flaky'   => 'flaky',
                'skipped' => 'pending',
            ];
            $uc_status = $uc_status_map[$status] ?? 'pending';
            db_query_prepared(
                "UPDATE use_case
                 SET test_status      = ?,
                     last_test_run_id = ?,
                     updated          = NOW()
                 WHERE use_case_id = ?",
                [$uc_status, $rid, $uid]
            );

            $data['run_id']      = $rid;
            $data['use_case_id'] = $uid;
            $data['status']      = $status;
            $data['use_case_status'] = $uc_status;
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = implode('<br>', $errs);
        }
    }
}

// ── List runs for a use_case (latest first) ─────────────────────────────
if (($action ?? null) == 'apiListUseCaseTestRuns') {
    if (require_api_scope('actions:read')) {
        $uid   = (int)($_POST['use_case_id'] ?? 0);
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 20)));
        if ($uid <= 0) {
            $_SESSION['error'] = 'use_case_id is required.';
        } else {
            $r = db_query_prepared(
                "SELECT run_id, use_case_id, run_at, permutation, status,
                        duration_ms, screenshot_paths, axe_violations_json,
                        console_errors_json, fail_reason
                 FROM use_case_test_run
                 WHERE use_case_id = ?
                 ORDER BY run_id DESC
                 LIMIT $limit",
                [$uid]
            );
            $items = [];
            if ($r) {
                while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
                    $items[] = $row;
                }
            }
            $data['items'] = $items;
            $data['count'] = count($items);
            $_SESSION['success'] = 'OK';
        }
    }
}

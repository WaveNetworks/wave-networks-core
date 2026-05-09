<?php
/**
 * useCaseAdminActions.php
 * AJAX actions for the admin Use Cases viewer. Admin-only.
 *
 * The use_case + use_case_test_run tables live on the admin main DB.
 * The Bearer-token API equivalents (apiActions/useCaseActions.php) feed
 * the Playwright runner; these in-session actions feed the admin UI.
 */

if (($_POST['action'] ?? '') == 'getUseCases') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $per_page = max(1, min(200, (int)($_POST['per_page'] ?? 50)));
        $offset   = ($page - 1) * $per_page;

        $where = [];
        $args  = [];
        if (!empty($_POST['source_app'])) {
            $where[] = "source_app = ?";
            $args[]  = (string)$_POST['source_app'];
        }
        if (!empty($_POST['test_status'])) {
            $where[] = "test_status = ?";
            $args[]  = (string)$_POST['test_status'];
        }
        if (!empty($_POST['test_category'])) {
            $where[] = "test_category = ?";
            $args[]  = (string)$_POST['test_category'];
        }
        if (!empty($_POST['search'])) {
            $where[]  = "(slug LIKE ? OR name LIKE ? OR description LIKE ?)";
            $like     = '%' . $_POST['search'] . '%';
            $args[]   = $like;
            $args[]   = $like;
            $args[]   = $like;
        }
        $sql_where = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $cr = db_query_prepared("SELECT COUNT(*) AS c FROM use_case $sql_where", $args);
        $total = $cr ? (int)$cr->fetch(PDO::FETCH_ASSOC)['c'] : 0;

        // Page of items
        $sql = "SELECT use_case_id, source_app, slug, name, description,
                       requires_login, starting_page, ending_action,
                       test_category, test_status, derived_from_log_count,
                       last_seen_at, last_test_run_id, created, updated
                FROM use_case
                $sql_where
                ORDER BY source_app ASC, test_status ASC, slug ASC
                LIMIT $per_page OFFSET $offset";
        $r = db_query_prepared($sql, $args);
        $items = [];
        if ($r) {
            while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
                $items[] = $row;
            }
        }

        // Stats — by status, then by source_app
        $stats = [
            'total'    => 0,
            'pending'  => 0,
            'passing'  => 0,
            'failing'  => 0,
            'flaky'    => 0,
            'disabled' => 0,
        ];
        $sr = db_query("SELECT test_status, COUNT(*) AS c FROM use_case GROUP BY test_status");
        if ($sr) {
            while ($row = $sr->fetch(PDO::FETCH_ASSOC)) {
                $stats[$row['test_status']] = (int)$row['c'];
                $stats['total'] += (int)$row['c'];
            }
        }
        $apps = [];
        $ar = db_query("SELECT DISTINCT source_app FROM use_case ORDER BY source_app ASC");
        if ($ar) {
            while ($row = $ar->fetch(PDO::FETCH_ASSOC)) {
                $apps[] = $row['source_app'];
            }
        }

        $data['items']   = $items;
        $data['total']   = $total;
        $data['stats']   = $stats;
        $data['apps']    = $apps;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'getUseCaseDetail') {
    $errs = array();
    if (!$_SESSION['user_id'])         { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))            { $errs['role'] = 'Admin access required.'; }
    if (empty($_POST['use_case_id']))  { $errs['id']   = 'use_case_id is required.'; }

    if (count($errs) <= 0) {
        $uid = (int)$_POST['use_case_id'];

        $r = db_query_prepared(
            "SELECT * FROM use_case WHERE use_case_id = ?",
            [$uid]
        );
        $uc = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;

        if (!$uc) {
            $_SESSION['error'] = 'Use case not found.';
        } else {
            $rr = db_query_prepared(
                "SELECT run_id, run_at, permutation, status, duration_ms, fail_reason
                 FROM use_case_test_run
                 WHERE use_case_id = ?
                 ORDER BY run_id DESC
                 LIMIT 20",
                [$uid]
            );
            $runs = [];
            if ($rr) {
                while ($row = $rr->fetch(PDO::FETCH_ASSOC)) {
                    $runs[] = $row;
                }
            }
            $data['use_case'] = $uc;
            $data['runs']     = $runs;
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ─── refreshUseCases — admin trigger for derive + spec generation ──────────
//
// Replaces the "SSH in and run admin/scripts/derive_use_cases.py" empty-state
// hint with an in-UI button. Runs the same wrapper the daily cron uses
// (refresh_use_case_specs_all.sh), which loops every configured deployment
// and re-derives use_cases from each test user's action log.
//
// Admin only. shell_exec'd with a 5-minute cap (the script is normally
// fast — slow only if first-time npm install hits).
if (($_POST['action'] ?? '') == 'refreshUseCases') {
    if (!has_role('admin')) {
        $_SESSION['error'] = 'Admin access required.';
    } else {
        $script = '/mnt/data/nokemo/scripts/refresh_use_case_specs_all.sh';
        if (!is_file($script) || !is_executable($script)) {
            $_SESSION['error'] = "refresh script not found or not executable at $script";
        } elseif (!function_exists('shell_exec')) {
            $_SESSION['error'] = 'shell_exec is disabled on this host';
        } else {
            $started = microtime(true);
            // Cap at 300s; capture both stdout + stderr for the operator.
            $cmd = sprintf('timeout 300 %s 2>&1', escapeshellarg($script));
            $out = shell_exec($cmd);
            $elapsed_ms = (int) ((microtime(true) - $started) * 1000);
            $tail_lines = array_slice(preg_split("/\r?\n/", trim((string)$out)), -40);

            $data['elapsed_ms'] = $elapsed_ms;
            $data['output_tail'] = $tail_lines;
            $data['output_full_length'] = strlen((string)$out);
            $_SESSION['success'] = 'Refresh complete in ' . round($elapsed_ms / 1000, 1) . 's. See output_tail for details.';
        }
    }
}

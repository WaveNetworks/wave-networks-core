<?php
/**
 * useCaseAssetAdminActions.php
 * AJAX actions for the "Graphics" panel on views/use_cases.php. Admin-only.
 *
 *   getUseCaseAssets     linked graphics for a use case, each with live drift
 *                        status + the latest passing run's screenshots (side by side)
 *   linkUseCaseAsset     attach a media_asset to the use case
 *   unlinkUseCaseAsset   remove a link
 *   approveUseCaseAsset  bless the current run_id + action_path + screenshot phash
 *   replaceUseCaseAsset  point the link at a different media_asset, reset to pending
 *   listMediaForLink     media library picker for the "add graphic" modal
 */

function _uca_admin_guard(&$errs)
{
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }
}

// ── Linked graphics for a use case, with drift + run screenshots ─────────────
if (($_POST['action'] ?? '') == 'getUseCaseAssets') {
    $errs = array();
    _uca_admin_guard($errs);
    if (empty($_POST['use_case_id'])) { $errs['id'] = 'use_case_id is required.'; }

    if (count($errs) <= 0) {
        ensure_use_case_asset_table();
        $ucid = (int)$_POST['use_case_id'];

        $ucr = db_query_prepared("SELECT * FROM use_case WHERE use_case_id = ?", [$ucid]);
        $uc  = $ucr ? $ucr->fetch(PDO::FETCH_ASSOC) : null;
        if (!$uc) {
            $_SESSION['error'] = 'Use case not found.';
        } else {
            $latest_run = use_case_latest_passing_run($ucid);
            // Screenshots of the latest passing run, served the same way the runs
            // table does (use_case_screenshot.php).
            $run_shots = [];
            if ($latest_run) {
                $paths = json_decode($latest_run['screenshot_paths'] ?? '[]', true) ?: [];
                foreach ($paths as $p) {
                    $name = basename((string)$p);
                    if (preg_match('/\.png$/i', $name)) {
                        $run_shots[] = [
                            'name' => $name,
                            'url'  => '../use_case_screenshot.php?run_id=' . (int)$latest_run['run_id']
                                      . '&f=' . rawurlencode($name),
                        ];
                    }
                }
            }

            $r = db_query_prepared(
                "SELECT la.*, m.filename, m.original_name, m.title, m.mime_type,
                        m.ext, m.width, m.height
                 FROM use_case_asset la
                 JOIN media_asset m ON m.asset_id = la.asset_id
                 WHERE la.use_case_id = ?
                 ORDER BY la.role ASC, la.variant ASC",
                [$ucid]
            );
            $assets = [];
            if ($r) {
                while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
                    $eval = use_case_asset_eval_drift($row, $uc, $latest_run);
                    $row['live_status']   = $eval['status'];
                    $row['live_reason']   = $eval['reason'];
                    $row['phash_distance']= $eval['distance'];
                    $row['asset_url']     = media_public_url($row['filename']);
                    $assets[] = $row;
                }
            }

            $data['use_case']   = ['use_case_id' => $ucid, 'slug' => $uc['slug'],
                                   'name' => $uc['name'], 'test_status' => $uc['test_status']];
            $data['latest_run'] = $latest_run ? [
                'run_id' => (int)$latest_run['run_id'],
                'run_at' => $latest_run['run_at'],
            ] : null;
            $data['run_screenshots'] = $run_shots;
            $data['assets']          = $assets;
            $data['roles']           = use_case_asset_roles();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Media picker for the "add graphic" modal ─────────────────────────────────
if (($_POST['action'] ?? '') == 'listMediaForLink') {
    $errs = array();
    _uca_admin_guard($errs);
    if (count($errs) <= 0) {
        if (function_exists('ensure_media_table')) { ensure_media_table(); }
        $where = "WHERE mime_type LIKE 'image/%' OR mime_type = 'video/mp4'";
        if (!empty($_POST['search'])) {
            $s = sanitize($_POST['search'], SQL);
            $where .= " AND (title LIKE '%$s%' OR original_name LIKE '%$s%')";
        }
        $r = db_query("SELECT asset_id, filename, original_name, title, mime_type, ext
                       FROM media_asset $where ORDER BY created DESC LIMIT 60");
        $rows = $r ? db_fetch_all($r) : [];
        foreach ($rows as &$row) {
            $row['asset_id'] = (int)$row['asset_id'];
            $row['url']      = media_public_url($row['filename']);
        }
        unset($row);
        $data['assets'] = $rows;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Link ─────────────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') == 'linkUseCaseAsset') {
    $errs = array();
    _uca_admin_guard($errs);
    $ucid    = (int)($_POST['use_case_id'] ?? 0);
    $asset   = (int)($_POST['asset_id'] ?? 0);
    $role    = trim((string)($_POST['role'] ?? 'vignette'));
    $variant = trim((string)($_POST['variant'] ?? ''));
    if ($ucid <= 0)  { $errs['uc']    = 'use_case_id is required.'; }
    if ($asset <= 0) { $errs['asset'] = 'Pick a media asset.'; }
    if (!in_array($role, use_case_asset_roles(), true)) { $errs['role'] = 'Invalid role.'; }

    if (count($errs) <= 0) {
        ensure_use_case_asset_table();
        db_query_prepared(
            "INSERT INTO use_case_asset
               (use_case_id, asset_id, role, variant, review_status, created, updated)
             VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
             ON DUPLICATE KEY UPDATE updated = NOW()",
            [$ucid, $asset, $role, $variant]
        );
        $_SESSION['success'] = 'Graphic linked.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Unlink ───────────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') == 'unlinkUseCaseAsset') {
    $errs = array();
    _uca_admin_guard($errs);
    $link_id = (int)($_POST['link_id'] ?? 0);
    if ($link_id <= 0) { $errs['id'] = 'link_id is required.'; }
    if (count($errs) <= 0) {
        db_query_prepared("DELETE FROM use_case_asset WHERE link_id = ?", [$link_id]);
        $_SESSION['success'] = 'Graphic unlinked.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Approve: record the current run + action_path + screenshot phash ─────────
if (($_POST['action'] ?? '') == 'approveUseCaseAsset') {
    $errs = array();
    _uca_admin_guard($errs);
    $link_id = (int)($_POST['link_id'] ?? 0);
    if ($link_id <= 0) { $errs['id'] = 'link_id is required.'; }

    if (count($errs) <= 0) {
        $lr = db_query_prepared(
            "SELECT la.*, uc.action_path
             FROM use_case_asset la JOIN use_case uc ON uc.use_case_id = la.use_case_id
             WHERE la.link_id = ?",
            [$link_id]
        );
        $link = $lr ? $lr->fetch(PDO::FETCH_ASSOC) : null;
        if (!$link) {
            $_SESSION['error'] = 'Link not found.';
        } else {
            $latest_run = use_case_latest_passing_run((int)$link['use_case_id']);
            $run_id = $latest_run ? (int)$latest_run['run_id'] : null;
            $phash  = $run_id ? use_case_run_phash($run_id) : null;
            $path_hash = use_case_action_path_hash($link['action_path'] ?? '');
            db_query_prepared(
                "UPDATE use_case_asset
                 SET review_status = 'approved', review_reason = NULL,
                     approved_run_id = ?, approved_path_hash = ?, approved_phash = ?,
                     last_reviewed_at = NOW(), updated = NOW()
                 WHERE link_id = ?",
                [$run_id, $path_hash, $phash, $link_id]
            );
            $data['approved_run_id'] = $run_id;
            $data['approved_phash']  = $phash;
            $_SESSION['success'] = $run_id
                ? "Approved against run #$run_id."
                : 'Approved (no passing run with a screenshot yet — action_path recorded).';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Replace: point the link at a different media asset, reset to pending ──────
if (($_POST['action'] ?? '') == 'replaceUseCaseAsset') {
    $errs = array();
    _uca_admin_guard($errs);
    $link_id   = (int)($_POST['link_id'] ?? 0);
    $new_asset = (int)($_POST['asset_id'] ?? 0);
    if ($link_id <= 0)   { $errs['id']    = 'link_id is required.'; }
    if ($new_asset <= 0) { $errs['asset'] = 'Pick a replacement media asset.'; }
    if (count($errs) <= 0) {
        $chk = db_query_prepared("SELECT asset_id FROM media_asset WHERE asset_id = ?", [$new_asset]);
        if (!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['error'] = 'Replacement asset not found.';
        } else {
            db_query_prepared(
                "UPDATE use_case_asset
                 SET asset_id = ?, review_status = 'pending', review_reason = NULL,
                     approved_run_id = NULL, approved_path_hash = NULL, approved_phash = NULL,
                     last_reviewed_at = NOW(), updated = NOW()
                 WHERE link_id = ?",
                [$new_asset, $link_id]
            );
            $_SESSION['success'] = 'Graphic replaced — review it against the latest run and Approve.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

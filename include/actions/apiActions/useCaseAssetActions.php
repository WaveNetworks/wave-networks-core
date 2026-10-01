<?php
/**
 * Use-case ↔ Media asset API Actions.
 *
 *   apiLinkUseCaseAsset     link a media_asset to a use_case     (scope tests:write)
 *   apiUnlinkUseCaseAsset   remove a link                        (scope tests:write)
 *   apiListUseCaseAssets    list linked graphics for a use case  (read scope)
 *   apiGetUseCaseAssetReview  drift sweep + rows needing review  (scope monitoring:read)
 *
 * Reads accept any of actions:read / tests:read / media:read so a repo build step
 * (which already holds media:read to upload graphics) can attach them, and so the
 * monitoring key can poll drift. See useCaseAssetFunctions.php for the link model.
 *
 * A build step uploads a graphic via apiUploadMedia (media:write), gets back an
 * asset_id, then calls apiLinkUseCaseAsset to attach it to the use case it depicts.
 */

if (in_array(($action ?? null),
    ['apiLinkUseCaseAsset', 'apiUnlinkUseCaseAsset', 'apiListUseCaseAssets', 'apiGetUseCaseAssetReview'], true)) {
    if (function_exists('ensure_use_case_asset_table')) { ensure_use_case_asset_table(); }
    if (function_exists('ensure_media_table'))          { ensure_media_table(); }
}

/** Read gate: any of actions:read / tests:read / media:read. */
function _uca_require_read_scope(): bool
{
    global $_SERVICE_API_KEY;
    $scopes = $_SERVICE_API_KEY ? (json_decode($_SERVICE_API_KEY['scopes'] ?? '[]', true) ?: []) : [];
    foreach (['actions:read', 'tests:read', 'media:read'] as $s) {
        if (in_array($s, $scopes, true)) { return true; }
    }
    // No matching scope — emit the standard (never-silent) refusal.
    return require_api_scope('tests:read');
}

// ── Link a media asset to a use case ─────────────────────────────────────────
if (($action ?? null) == 'apiLinkUseCaseAsset') {
    if (require_api_scope('tests:write')) {
        $errs = [];
        $ucid    = (int)($_POST['use_case_id'] ?? 0);
        $asset   = (int)($_POST['asset_id'] ?? 0);
        $role    = trim((string)($_POST['role'] ?? 'vignette'));
        $variant = trim((string)($_POST['variant'] ?? ''));
        $shash   = trim((string)($_POST['source_hash'] ?? ''));

        if ($ucid <= 0)  { $errs[] = 'use_case_id is required.'; }
        if ($asset <= 0) { $errs[] = 'asset_id is required.'; }
        if (!in_array($role, use_case_asset_roles(), true)) {
            $errs[] = 'role must be one of: ' . implode(', ', use_case_asset_roles());
        }
        if (strlen($variant) > 50) { $errs[] = 'variant max 50 chars.'; }

        if (empty($errs)) {
            $uc = db_query_prepared("SELECT use_case_id FROM use_case WHERE use_case_id = ?", [$ucid]);
            if (!$uc || !$uc->fetch(PDO::FETCH_ASSOC)) { $errs[] = "use_case_id $ucid not found."; }
            $ma = db_query_prepared("SELECT asset_id FROM media_asset WHERE asset_id = ?", [$asset]);
            if (!$ma || !$ma->fetch(PDO::FETCH_ASSOC)) { $errs[] = "asset_id $asset not found in media library."; }
        }

        if (empty($errs)) {
            db_query_prepared(
                "INSERT INTO use_case_asset
                   (use_case_id, asset_id, role, variant, source_hash,
                    review_status, created, updated)
                 VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    source_hash = VALUES(source_hash),
                    updated = NOW()",
                [$ucid, $asset, $role, $variant, $shash ?: null]
            );
            $r = db_query_prepared(
                "SELECT * FROM use_case_asset
                 WHERE use_case_id = ? AND asset_id = ? AND role = ? AND variant = ?",
                [$ucid, $asset, $role, $variant]
            );
            $data['link'] = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = implode('<br>', $errs);
        }
    }
}

// ── Unlink ───────────────────────────────────────────────────────────────────
if (($action ?? null) == 'apiUnlinkUseCaseAsset') {
    if (require_api_scope('tests:write')) {
        $link_id = (int)($_POST['link_id'] ?? 0);
        $ucid    = (int)($_POST['use_case_id'] ?? 0);
        $asset   = (int)($_POST['asset_id'] ?? 0);
        $role    = trim((string)($_POST['role'] ?? ''));
        $variant = trim((string)($_POST['variant'] ?? ''));

        if ($link_id > 0) {
            db_query_prepared("DELETE FROM use_case_asset WHERE link_id = ?", [$link_id]);
            $data['deleted'] = $link_id;
            $_SESSION['success'] = 'OK';
        } elseif ($ucid > 0 && $asset > 0 && $role !== '') {
            db_query_prepared(
                "DELETE FROM use_case_asset
                 WHERE use_case_id = ? AND asset_id = ? AND role = ? AND variant = ?",
                [$ucid, $asset, $role, $variant]
            );
            $data['deleted'] = true;
            $_SESSION['success'] = 'OK';
        } else {
            $_SESSION['error'] = 'link_id, or use_case_id + asset_id + role, is required.';
        }
    }
}

// ── List linked graphics for a use case (or a whole app) ─────────────────────
if (($action ?? null) == 'apiListUseCaseAssets') {
    if (_uca_require_read_scope()) {
        $ucid = (int)($_POST['use_case_id'] ?? 0);
        $app  = trim((string)($_POST['source_app'] ?? ''));

        $where = [];
        $args  = [];
        if ($ucid > 0)   { $where[] = 'la.use_case_id = ?'; $args[] = $ucid; }
        if ($app !== '') { $where[] = 'uc.source_app = ?';  $args[] = $app; }
        if (empty($where)) {
            $_SESSION['error'] = 'use_case_id or source_app is required.';
        } else {
            $sql = "SELECT la.link_id, la.use_case_id, la.asset_id, la.role, la.variant,
                           la.source_hash, la.review_status, la.review_reason,
                           la.approved_run_id, la.last_reviewed_at, la.created, la.updated,
                           uc.source_app, uc.slug, uc.name AS use_case_name, uc.test_status,
                           m.filename, m.original_name, m.title, m.mime_type, m.ext,
                           m.width, m.height
                    FROM use_case_asset la
                    JOIN use_case uc ON uc.use_case_id = la.use_case_id
                    JOIN media_asset m ON m.asset_id = la.asset_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY la.use_case_id ASC, la.role ASC, la.variant ASC";
            $r = db_query_prepared($sql, $args);
            $items = [];
            if ($r) {
                while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
                    $row['asset_url'] = media_public_url($row['filename']);
                    $items[] = $row;
                }
            }
            $data['items'] = $items;
            $data['count'] = count($items);
            $_SESSION['success'] = 'OK';
        }
    }
}

// ── Drift report for the monitoring poll (nokemo files a task off this) ───────
if (($action ?? null) == 'apiGetUseCaseAssetReview') {
    if (require_api_scope('monitoring:read')) {
        $count = use_case_asset_review_sweep();
        $r = db_query(
            "SELECT la.link_id, la.use_case_id, la.asset_id, la.role, la.variant,
                    la.review_status, la.review_reason, la.last_reviewed_at,
                    uc.source_app, uc.slug, uc.name AS use_case_name,
                    m.original_name, m.title
             FROM use_case_asset la
             JOIN use_case uc ON uc.use_case_id = la.use_case_id
             JOIN media_asset m ON m.asset_id = la.asset_id
             WHERE la.review_status = 'needs_review'
             ORDER BY la.last_reviewed_at DESC
             LIMIT 200"
        );
        $items = [];
        if ($r) {
            while ($row = $r->fetch(PDO::FETCH_ASSOC)) { $items[] = $row; }
        }
        $data['needs_review_count'] = $count;
        $data['items']              = $items;
        $_SESSION['success'] = 'OK';
    }
}

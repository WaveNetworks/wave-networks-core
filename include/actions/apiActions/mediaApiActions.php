<?php
/**
 * Media Library API Actions
 * Actions: apiListMedia, apiGetMedia  (scope: media:read)
 *
 * Read-only programmatic access for the builder / AI agents that need to discover
 * and embed media assets uploaded into this app's admin media library.
 */

if (in_array(($action ?? null), ['apiListMedia', 'apiGetMedia'], true)) {
    if (function_exists('ensure_media_table')) { ensure_media_table(); }
}

// ── List media assets (with optional filters) ────────────────────────────────
if (($action ?? null) == 'apiListMedia') {
    if (require_api_scope('media:read')) {
        $page     = max(1, (int) ($_POST['page'] ?? 1));
        $per_page = min(100, max(1, (int) ($_POST['per_page'] ?? 50)));
        $offset   = ($page - 1) * $per_page;

        $where = "WHERE 1=1";
        if (!empty($_POST['mime_prefix'])) {
            $where .= " AND mime_type LIKE '" . sanitize($_POST['mime_prefix'], SQL) . "%'";
        }
        if (!empty($_POST['ext'])) {
            $where .= " AND ext = '" . sanitize($_POST['ext'], SQL) . "'";
        }
        if (!empty($_POST['search'])) {
            $s = sanitize($_POST['search'], SQL);
            $where .= " AND (title LIKE '%" . $s . "%' OR original_name LIKE '%" . $s . "%')";
        }

        $r = db_query("SELECT asset_id, filename, original_name, title, mime_type, ext,
                              file_size, width, height, created
                       FROM media_asset $where
                       ORDER BY created DESC LIMIT $per_page OFFSET $offset");
        $rows = $r ? db_fetch_all($r) : array();
        foreach ($rows as &$row) {
            $row['asset_id']  = (int) $row['asset_id'];
            $row['file_size'] = (int) $row['file_size'];
            if ($row['width']  !== null) { $row['width']  = (int) $row['width']; }
            if ($row['height'] !== null) { $row['height'] = (int) $row['height']; }
            $row['url'] = media_public_url($row['filename']);
        }
        unset($row);

        $cnt   = db_query("SELECT COUNT(*) AS c FROM media_asset $where");
        $crows = $cnt ? db_fetch_all($cnt) : array();
        $total = $crows ? (int) $crows[0]['c'] : 0;

        $data['assets']   = $rows;
        $data['total']    = $total;
        $data['page']     = $page;
        $data['per_page'] = $per_page;
        $_SESSION['success'] = 'OK';
    }
}

// ── Get a single media asset by id ───────────────────────────────────────────
if (($action ?? null) == 'apiGetMedia') {
    if (require_api_scope('media:read')) {
        $asset_id = (int) ($_POST['asset_id'] ?? 0);
        if (!$asset_id) {
            $_SESSION['error'] = 'asset_id is required.';
        } else {
            $r = db_query("SELECT asset_id, filename, original_name, title, mime_type, ext,
                                  file_size, width, height, created
                           FROM media_asset WHERE asset_id = $asset_id LIMIT 1");
            $rows = $r ? db_fetch_all($r) : array();
            if (!$rows) {
                $_SESSION['error'] = 'Asset not found.';
            } else {
                $row = $rows[0];
                $row['asset_id']  = (int) $row['asset_id'];
                $row['file_size'] = (int) $row['file_size'];
                if ($row['width']  !== null) { $row['width']  = (int) $row['width']; }
                if ($row['height'] !== null) { $row['height'] = (int) $row['height']; }
                $row['url'] = media_public_url($row['filename']);
                $data['asset'] = $row;
                $_SESSION['success'] = 'OK';
            }
        }
    }
}

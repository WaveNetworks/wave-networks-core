<?php
/**
 * Media Library API Actions
 * Actions: apiListMedia, apiGetMedia  (scope: media:read)
 *          apiUploadMedia               (scope: media:write)
 *
 * Read-only programmatic access for the builder / AI agents that need to discover
 * and embed media assets uploaded into this app's admin media library.
 */

if (in_array(($action ?? null), ['apiListMedia', 'apiGetMedia', 'apiUploadMedia'], true)) {
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

// ── Upload a media asset (base64) ────────────────────────────────────────────
// The agent counterpart of the admin UI's uploadMedia. Without it, anything an
// agent needed in the media library (archived citation PDFs, generated images)
// became an "owner step" even though no owner judgement was involved.
// Params: content_b64 (required), original_name (required), title (optional).
// The type is sniffed from the bytes, never taken from the caller.
if (($action ?? null) == 'apiUploadMedia') {
    if (require_api_scope('media:write')) {
        $errs  = array();
        $name  = trim((string) ($_POST['original_name'] ?? ''));
        $bytes = base64_decode((string) ($_POST['content_b64'] ?? ''), true);
        $max   = 25 * 1024 * 1024;

        if ($name === '')                          { $errs['original_name'] = 'original_name is required.'; }
        if ($bytes === false || $bytes === '')      { $errs['content_b64'] = 'content_b64 must be non-empty base64.'; }
        elseif (strlen($bytes) > $max)              { $errs['content_b64'] = 'File must be under 25 MB.'; }

        $allowed = media_allowed_types();
        $type = '';
        if (count($errs) <= 0) {
            $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
            // finfo reports SVG as image/svg or text/xml depending on libmagic.
            if (in_array($type, ['image/svg', 'text/xml', 'application/xml', 'text/plain'], true)
                && stripos($bytes, '<svg') !== false) {
                $type = 'image/svg+xml';
            }
            if (!isset($allowed[$type])) {
                $errs['content_b64'] = 'Unsupported type (' . $type . '). Allowed: ' . implode(', ', array_keys($allowed)) . '.';
            }
        }

        if (count($errs) <= 0) {
            $stored = bin2hex(random_bytes(8)) . '.' . $allowed[$type];
            $dest   = media_dir() . '/' . $stored;
            if (@file_put_contents($dest, $bytes, LOCK_EX) === false) {
                $errs['media'] = 'Failed to store the file.';
            } else {
                @chmod($dest, 0644);
                $w = 'NULL';
                $h = 'NULL';
                $info = @getimagesize($dest);
                if ($info) { $w = (int) $info[0]; $h = (int) $info[1]; }
                $title     = trim((string) ($_POST['title'] ?? ''));
                $title_sql = $title !== '' ? "'" . sanitize(mb_substr($title, 0, 255), SQL) . "'" : 'NULL';

                db_query(
                    "INSERT INTO media_asset
                        (filename, original_name, title, mime_type, ext, file_size, width, height, uploaded_by, created)
                     VALUES (
                        '" . sanitize($stored, SQL) . "',
                        '" . sanitize(mb_substr($name, 0, 255), SQL) . "',
                        " . $title_sql . ",
                        '" . sanitize($type, SQL) . "',
                        '" . sanitize($allowed[$type], SQL) . "',
                        " . strlen($bytes) . ",
                        " . $w . ", " . $h . ",
                        NULL, NOW())"
                );
                $data['asset_id'] = (int) db_insert_id();
                $data['filename'] = $stored;
                $data['url']      = media_public_url($stored);
                $data['mime_type'] = $type;
                $_SESSION['success'] = 'Media uploaded.';
            }
        }
        if (count($errs) > 0) {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

<?php
/**
 * Media Library Actions
 * Actions: uploadMedia, deleteMedia  (admin-only)
 */

if (in_array(($action ?? null), ['uploadMedia', 'replaceMedia', 'deleteMedia'], true)) {
    ensure_media_table();
}

// ── Upload a media asset ─────────────────────────────────────────────────────
if (($action ?? null) == 'uploadMedia') {
    $errs = array();

    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))          { $errs['auth'] = 'Admin access required.'; }

    if (empty($_FILES['media']['name']) || ($_FILES['media']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $errs['media'] = 'Choose a file to upload.';
    }

    $allowed = media_allowed_types();
    $max     = 25 * 1024 * 1024; // 25 MB

    if (count($errs) <= 0) {
        $type = $_FILES['media']['type'];
        if (!isset($allowed[$type])) {
            $errs['media'] = 'Unsupported type. Allowed: PNG, JPG, GIF, WebP, SVG, ICO, PDF, MP4.';
        } elseif ($_FILES['media']['size'] > $max) {
            $errs['media'] = 'File must be under 25 MB.';
        }
    }

    if (count($errs) <= 0) {
        $ext    = $allowed[$type];
        $stored = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest   = media_dir() . '/' . $stored;

        if (!move_uploaded_file($_FILES['media']['tmp_name'], $dest)) {
            $errs['media'] = 'Failed to store the uploaded file.';
        } else {
            @chmod($dest, 0644);
            $w = 'NULL';
            $h = 'NULL';
            $info = @getimagesize($dest);
            if ($info) { $w = (int) $info[0]; $h = (int) $info[1]; }

            $title    = trim($_POST['title'] ?? '');
            $title_sql = $title !== '' ? "'" . sanitize(mb_substr($title, 0, 255), SQL) . "'" : 'NULL';

            db_query(
                "INSERT INTO media_asset
                    (filename, original_name, title, mime_type, ext, file_size, width, height, uploaded_by, created)
                 VALUES (
                    '" . sanitize($stored, SQL) . "',
                    '" . sanitize(mb_substr($_FILES['media']['name'], 0, 255), SQL) . "',
                    " . $title_sql . ",
                    '" . sanitize($type, SQL) . "',
                    '" . sanitize($ext, SQL) . "',
                    " . (int) $_FILES['media']['size'] . ",
                    " . $w . ", " . $h . ",
                    " . (int) $_SESSION['user_id'] . ", NOW())"
            );
            $_SESSION['success'] = 'Media uploaded.';
            $data['filename'] = $stored;
            $data['url']      = media_public_url($stored);
        }
    }

    if (count($errs) > 0) { $_SESSION['error'] = implode('<br>', $errs); }
}

// ── Replace a media asset's file (keeps the same URL — "update a revision") ──
if (($action ?? null) == 'replaceMedia') {
    $errs = array();

    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))          { $errs['auth'] = 'Admin access required.'; }

    $asset_id = (int) ($_POST['asset_id'] ?? 0);
    if (!$asset_id) { $errs['id'] = 'Invalid asset.'; }

    if (empty($_FILES['media']['name']) || ($_FILES['media']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $errs['media'] = 'Choose a replacement file.';
    }

    $allowed = media_allowed_types();
    $max     = 25 * 1024 * 1024; // 25 MB

    if (count($errs) <= 0) {
        $type = $_FILES['media']['type'];
        if (!isset($allowed[$type])) {
            $errs['media'] = 'Unsupported type. Allowed: PNG, JPG, GIF, WebP, SVG, ICO, PDF, MP4.';
        } elseif ($_FILES['media']['size'] > $max) {
            $errs['media'] = 'File must be under 25 MB.';
        }
    }

    $asset = null;
    if (count($errs) <= 0) {
        $r    = db_query("SELECT asset_id, filename, ext FROM media_asset WHERE asset_id = " . $asset_id);
        $rows = $r ? db_fetch_all($r) : array();
        if (!$rows) {
            $errs['id'] = 'Asset not found.';
        } else {
            $asset = $rows[0];
            // Same URL must keep serving the right content-type, and media.php
            // derives the type from the filename extension — so the replacement
            // must be the same type. To change format, upload a new asset.
            if ($allowed[$type] !== strtolower($asset['ext'])) {
                $errs['media'] = 'Replacement must be the same file type (.' . h($asset['ext'])
                    . '). To change format, upload a new asset instead.';
            } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $asset['filename'])) {
                $errs['media'] = 'Stored filename is invalid; cannot replace in place.';
            }
        }
    }

    if (count($errs) <= 0) {
        $dest = media_dir() . '/' . $asset['filename'];
        if (!move_uploaded_file($_FILES['media']['tmp_name'], $dest)) {
            $errs['media'] = 'Failed to store the replacement file.';
        } else {
            @chmod($dest, 0644);
            $w = 'NULL';
            $h = 'NULL';
            $info = @getimagesize($dest);
            if ($info) { $w = (int) $info[0]; $h = (int) $info[1]; }

            db_query(
                "UPDATE media_asset SET
                    original_name = '" . sanitize(mb_substr($_FILES['media']['name'], 0, 255), SQL) . "',
                    mime_type     = '" . sanitize($type, SQL) . "',
                    file_size     = " . (int) $_FILES['media']['size'] . ",
                    width         = " . $w . ",
                    height        = " . $h . "
                 WHERE asset_id = " . $asset_id
            );
            $_SESSION['success'] = 'Media replaced — the existing URL now serves the updated file.';
            $data['filename'] = $asset['filename'];
            $data['url']      = media_public_url($asset['filename']);
        }
    }

    if (count($errs) > 0) { $_SESSION['error'] = implode('<br>', $errs); }
}

// ── Delete a media asset ─────────────────────────────────────────────────────
if (($action ?? null) == 'deleteMedia') {
    $errs = array();

    if (!has_role('admin')) { $errs['auth'] = 'Admin access required.'; }
    $asset_id = (int) ($_POST['asset_id'] ?? 0);
    if (!$asset_id) { $errs['id'] = 'Invalid asset.'; }

    if (count($errs) <= 0) {
        $r    = db_query("SELECT filename FROM media_asset WHERE asset_id = " . $asset_id);
        $rows = $r ? db_fetch_all($r) : array();
        if (!$rows) {
            $errs['id'] = 'Asset not found.';
        } else {
            $fn = $rows[0]['filename'];
            if (preg_match('/^[A-Za-z0-9._-]+$/', $fn)) { @unlink(media_dir() . '/' . $fn); }
            db_query("DELETE FROM media_asset WHERE asset_id = " . $asset_id);
            $_SESSION['success'] = 'Media deleted.';
        }
    }

    if (count($errs) > 0) { $_SESSION['error'] = implode('<br>', $errs); }
}

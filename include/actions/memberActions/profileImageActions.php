<?php
/**
 * memberActions/profileImageActions.php
 * Upload, serve, and delete profile photos.
 *
 * Actions:
 *   uploadProfileImage   (POST)  — upload and process a new profile photo
 *   deleteProfileImage   (POST)  — remove the profile photo
 *   serveProfileImage    (GET)   — stream the image file to the browser
 */

// ── Upload profile image ─────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'uploadProfileImage') {
    $errs = [];
    $uid  = (int) ($_SESSION['user_id'] ?? 0);
    $sid  = $_SESSION['shard_id'] ?? '';

    if (!$uid) { $errs['auth'] = 'Login required.'; }

    if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $errs['file'] = 'Please select an image to upload.';
    }

    if (count($errs) <= 0) {
        $result = upload_profile_image($uid, $sid, $_FILES['profile_image']);
        if ($result['success']) {
            $_SESSION['success']         = 'Profile photo updated.';
            $_SESSION['profile_success'] = 'Profile photo updated.';
        } else {
            $_SESSION['error']         = $result['error'];
            $_SESSION['profile_error'] = $result['error'];
        }
    } else {
        $msg = implode('<br>', $errs);
        $_SESSION['error']         = $msg;
        $_SESSION['profile_error'] = $msg;
    }
}

// ── Delete profile image ─────────────────────────────────────────────────────

if (($_POST['action'] ?? '') == 'deleteProfileImage') {
    $errs = [];
    $uid  = (int) ($_SESSION['user_id'] ?? 0);
    $sid  = $_SESSION['shard_id'] ?? '';

    if (!$uid) { $errs['auth'] = 'Login required.'; }

    if (count($errs) <= 0) {
        if (delete_profile_image($uid, $sid)) {
            $_SESSION['success']         = 'Profile photo removed.';
            $_SESSION['profile_success'] = 'Profile photo removed.';
        } else {
            $_SESSION['error']         = 'Could not remove profile photo.';
            $_SESSION['profile_error'] = 'Could not remove profile photo.';
        }
    } else {
        $msg = implode('<br>', $errs);
        $_SESSION['error']         = $msg;
        $_SESSION['profile_error'] = $msg;
    }
}

// ── Serve profile image (GET) ────────────────────────────────────────────────

if (($_GET['action'] ?? '') == 'serveProfileImage') {
    $uid = (int) ($_GET['uid'] ?? $_SESSION['user_id'] ?? 0);
    $sid = $_SESSION['shard_id'] ?? '';

    // For now, only allow serving the current user's own image
    // (extend later for team/admin views by looking up shard_id from user table)
    if ($uid && $sid) {
        serve_profile_image($uid, $sid);
    }
    http_response_code(404);
    exit;
}

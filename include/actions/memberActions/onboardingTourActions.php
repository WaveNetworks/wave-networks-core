<?php
/**
 * onboardingTourActions.php
 * Member actions for onboarding tour lifecycle + admin CRUD.
 */

if (empty($_POST['action'])) return;

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$shard_id = $_SESSION['shard_id'] ?? '';

/* ---------- per-user lifecycle ---------- */

if ($_POST['action'] == 'tourStart') {
    $errs = [];
    if (!$user_id || !$shard_id) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['tour_slug'])) { $errs['slug'] = 'tour_slug required.'; }
    if (count($errs) <= 0) {
        start_tour($user_id, $shard_id, $_POST['tour_slug']);
        $_SESSION['success'] = 'Tour started.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if ($_POST['action'] == 'tourAdvance') {
    $errs = [];
    if (!$user_id || !$shard_id) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['tour_slug'])) { $errs['slug'] = 'tour_slug required.'; }
    if (count($errs) <= 0) {
        advance_tour($user_id, $shard_id, $_POST['tour_slug'], (int)($_POST['step'] ?? 0));
        $data['ok'] = 1;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if ($_POST['action'] == 'tourSkip') {
    $errs = [];
    if (!$user_id || !$shard_id) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['tour_slug'])) { $errs['slug'] = 'tour_slug required.'; }
    if (count($errs) <= 0) {
        skip_tour($user_id, $shard_id, $_POST['tour_slug']);
        $data['ok'] = 1;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if ($_POST['action'] == 'tourComplete') {
    $errs = [];
    if (!$user_id || !$shard_id) { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['tour_slug'])) { $errs['slug'] = 'tour_slug required.'; }
    if (count($errs) <= 0) {
        complete_tour($user_id, $shard_id, $_POST['tour_slug']);
        $data['ok'] = 1;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if ($_POST['action'] == 'tourRestart') {
    $errs = [];
    if (!$user_id || !$shard_id) { $errs['auth'] = 'Login required.'; }
    $slug = $_POST['tour_slug'] ?? '';
    if ($slug === '') {
        $first = db_fetch(db_query(
            "SELECT slug FROM onboarding_tour WHERE is_active = 1 ORDER BY tour_id ASC LIMIT 1"
        ));
        $slug = $first['slug'] ?? '';
    }
    if ($slug === '') { $errs['slug'] = 'No active tour to restart.'; }
    if (count($errs) <= 0) {
        restart_tour($user_id, $shard_id, $slug);
        $_SESSION['success'] = 'Tour will restart on your next page load.';
        header('Location: index.php?page=dashboard');
        exit;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

/* ---------- admin CRUD ---------- */

if ($_POST['action'] == 'saveOnboardingTour') {
    $errs = [];
    if (!has_role('admin')) { $errs['auth'] = 'Admin access required.'; }
    $slug = trim($_POST['slug'] ?? '');
    if ($slug === '') { $errs['slug'] = 'Slug is required.'; }
    if ($slug !== '' && !preg_match('/^[a-z0-9_-]+$/', $slug)) {
        $errs['slug'] = 'Slug may contain only lowercase letters, numbers, dashes and underscores.';
    }

    // Optional welcome video: a direct URL/YouTube/Drive link, or an uploaded file.
    $video_url = trim($_POST['welcome_video_url'] ?? '');
    if (!empty($_FILES['welcome_video_file']['name']) && $_FILES['welcome_video_file']['error'] === UPLOAD_ERR_OK) {
        $allowed_video_types = ['video/mp4', 'video/webm', 'video/ogg'];
        $allowed_video_exts  = ['mp4', 'webm', 'ogg', 'ogv'];
        $max_video_size = 100 * 1024 * 1024; // 100 MB (subject to PHP upload limits)
        $vext = strtolower(pathinfo($_FILES['welcome_video_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($_FILES['welcome_video_file']['type'], $allowed_video_types, true) || !in_array($vext, $allowed_video_exts, true)) {
            $errs['welcome_video_file'] = 'Video must be an MP4, WebM, or Ogg file.';
        } elseif ($_FILES['welcome_video_file']['size'] > $max_video_size) {
            $errs['welcome_video_file'] = 'Uploaded video must be under 100 MB.';
        } elseif ($slug !== '' && !isset($errs['slug'])) {
            $media_dir = rtrim($files_location, '/') . '/tour_media';
            if (!is_dir($media_dir)) { mkdir($media_dir, 0755, true); }
            $fname = 'tour_' . $slug . '_welcome.' . $vext;
            foreach (glob($media_dir . '/tour_' . $slug . '_welcome.*') as $old) { @unlink($old); }
            if (move_uploaded_file($_FILES['welcome_video_file']['tmp_name'], $media_dir . '/' . $fname)) {
                $video_url = '/admin/tour_media/' . $fname;
            } else {
                $errs['welcome_video_file'] = 'Could not save the uploaded video.';
            }
        }
    }

    if (count($errs) <= 0) {
        $config = [
            'name'                 => $_POST['name'] ?? $slug,
            'welcome_title'        => $_POST['welcome_title'] ?? '',
            'welcome_body_md'      => $_POST['welcome_body_md'] ?? '',
            'welcome_video_url'    => $video_url,
            'welcome_cta_primary'  => $_POST['welcome_cta_primary'] ?? 'Take the tour',
            'welcome_cta_secondary'=> $_POST['welcome_cta_secondary'] ?? 'Explore on my own',
            'is_active'            => !empty($_POST['is_active']) ? 1 : 0,
            'created_by_app'       => 'core',
        ];

        $steps = [];
        $count = isset($_POST['step_selector']) ? count($_POST['step_selector']) : 0;
        for ($i = 0; $i < $count; $i++) {
            $sel = trim($_POST['step_selector'][$i] ?? '');
            $title = trim($_POST['step_title'][$i] ?? '');
            if ($sel === '' && $title === '') continue;
            $steps[] = [
                'selector'        => $sel,
                'title'           => $title,
                'body_md'         => $_POST['step_body'][$i] ?? '',
                'position'        => $_POST['step_position'][$i] ?? 'bottom',
                'action'          => $_POST['step_action'][$i] ?? null,
                'visible_if_role' => $_POST['step_role'][$i] ?? null,
            ];
        }

        register_onboarding_tour($slug, $config, $steps);
        $_SESSION['success'] = 'Tour saved.';
        header('Location: index.php?page=onboarding_tours');
        exit;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if ($_POST['action'] == 'deleteOnboardingTour') {
    $errs = [];
    if (!has_role('admin')) { $errs['auth'] = 'Admin access required.'; }
    $tour_id = (int)($_POST['tour_id'] ?? 0);
    if ($tour_id <= 0) { $errs['tour_id'] = 'tour_id required.'; }
    if (count($errs) <= 0) {
        $s_id = sanitize($tour_id, SQL);
        db_query("DELETE FROM onboarding_tour_step WHERE tour_id = '$s_id'");
        db_query("DELETE FROM onboarding_tour WHERE tour_id = '$s_id'");
        $_SESSION['success'] = 'Tour deleted.';
        header('Location: index.php?page=onboarding_tours');
        exit;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

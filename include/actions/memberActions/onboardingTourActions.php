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
    if (count($errs) <= 0) {
        $config = [
            'name'                 => $_POST['name'] ?? $slug,
            'welcome_title'        => $_POST['welcome_title'] ?? '',
            'welcome_body_md'      => $_POST['welcome_body_md'] ?? '',
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

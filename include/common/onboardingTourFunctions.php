<?php
/**
 * onboardingTourFunctions.php
 * Onboarding tour framework — DB-editable steps, per-user state, lifecycle events.
 *
 * Tour definitions live on the main DB (onboarding_tour, onboarding_tour_step).
 * Per-user state lives on the user's shard (onboarding_tour_state).
 */

/**
 * Return the first active tour the user has not yet completed/skipped, with
 * its steps and current state. Returns null if nothing pending.
 *
 * @param int    $user_id
 * @param string $shard_id
 * @return array|null
 */
function get_active_tour_for_user($user_id, $shard_id) {
    global $db;

    $user_id = (int)$user_id;
    if ($user_id <= 0 || empty($shard_id)) return null;

    $tours = db_fetch_all(db_query(
        "SELECT * FROM onboarding_tour WHERE is_active = 1 ORDER BY tour_id ASC"
    ));
    if (!$tours) return null;

    prime_shard($shard_id);

    foreach ($tours as $tour) {
        $s_slug = sanitize($tour['slug'], SQL);
        $stateRow = db_query_shard($shard_id,
            "SELECT * FROM onboarding_tour_state
              WHERE user_id = '$user_id' AND tour_slug = '$s_slug' LIMIT 1"
        );
        $state = $stateRow ? $stateRow->fetch(PDO::FETCH_ASSOC) : false;
        $status = $state['status'] ?? 'not_started';
        if (in_array($status, ['skipped', 'completed'], true)) continue;

        $tour_id = (int)$tour['tour_id'];
        $steps = db_fetch_all(db_query(
            "SELECT * FROM onboarding_tour_step
              WHERE tour_id = '$tour_id' ORDER BY step_order ASC, step_id ASC"
        ));

        $userRoles = (array)($_SESSION['roles'] ?? []);
        $filteredSteps = [];
        foreach ($steps as $step) {
            $needRole = $step['visible_if_role'];
            if ($needRole !== null && $needRole !== '' && !in_array($needRole, $userRoles, true)) {
                continue;
            }
            $filteredSteps[] = $step;
        }

        return [
            'tour'  => $tour,
            'steps' => $filteredSteps,
            'state' => $state ?: ['status' => 'not_started', 'current_step' => 0],
        ];
    }

    return null;
}

/**
 * Upsert a state row to in_progress and fire tour_started event.
 */
function start_tour($user_id, $shard_id, $tour_slug) {
    $user_id = (int)$user_id;
    $s_slug  = sanitize($tour_slug, SQL);
    db_query_shard($shard_id,
        "INSERT INTO onboarding_tour_state (user_id, tour_slug, status, current_step, started_at)
         VALUES ('$user_id', '$s_slug', 'in_progress', 0, NOW())
         ON DUPLICATE KEY UPDATE status = 'in_progress',
                                 started_at = COALESCE(started_at, NOW())"
    );
    if (function_exists('fire_email_event')) {
        fire_email_event($user_id, 'tour_started');
    }
    return true;
}

function advance_tour($user_id, $shard_id, $tour_slug, $step) {
    $user_id = (int)$user_id;
    $step    = (int)$step;
    $s_slug  = sanitize($tour_slug, SQL);
    db_query_shard($shard_id,
        "INSERT INTO onboarding_tour_state (user_id, tour_slug, status, current_step, started_at)
         VALUES ('$user_id', '$s_slug', 'in_progress', '$step', NOW())
         ON DUPLICATE KEY UPDATE current_step = '$step', status = 'in_progress'"
    );
    return true;
}

function complete_tour($user_id, $shard_id, $tour_slug) {
    $user_id = (int)$user_id;
    $s_slug  = sanitize($tour_slug, SQL);
    db_query_shard($shard_id,
        "INSERT INTO onboarding_tour_state (user_id, tour_slug, status, current_step, completed_at)
         VALUES ('$user_id', '$s_slug', 'completed', 0, NOW())
         ON DUPLICATE KEY UPDATE status = 'completed', completed_at = NOW()"
    );
    if (function_exists('fire_email_event')) {
        fire_email_event($user_id, 'tour_completed');
    }
    return true;
}

function skip_tour($user_id, $shard_id, $tour_slug) {
    $user_id = (int)$user_id;
    $s_slug  = sanitize($tour_slug, SQL);
    db_query_shard($shard_id,
        "INSERT INTO onboarding_tour_state (user_id, tour_slug, status, current_step)
         VALUES ('$user_id', '$s_slug', 'skipped', 0)
         ON DUPLICATE KEY UPDATE status = 'skipped'"
    );
    if (function_exists('fire_email_event')) {
        fire_email_event($user_id, 'tour_skipped');
    }
    return true;
}

function restart_tour($user_id, $shard_id, $tour_slug) {
    $user_id = (int)$user_id;
    $s_slug  = sanitize($tour_slug, SQL);
    db_query_shard($shard_id,
        "INSERT INTO onboarding_tour_state (user_id, tour_slug, status, current_step)
         VALUES ('$user_id', '$s_slug', 'not_started', 0)
         ON DUPLICATE KEY UPDATE status = 'not_started', current_step = 0,
                                 started_at = NULL, completed_at = NULL"
    );
    return true;
}

/**
 * Idempotent upsert of a tour and its steps. Migration-time convenience —
 * the primary editing path is the admin UI.
 *
 * @param string $slug
 * @param array  $config welcome_title, welcome_body_md, welcome_cta_primary,
 *                       welcome_cta_secondary, name, is_active, created_by_app
 * @param array  $steps  list of [selector,title,body_md,position,action,visible_if_role]
 */
function register_onboarding_tour($slug, $config = [], $steps = []) {
    global $db;

    if (empty($slug)) return false;

    $s_slug    = sanitize($slug, SQL);
    $s_name    = sanitize($config['name'] ?? $slug, SQL);
    $s_title   = sanitize($config['welcome_title'] ?? '', SQL);
    $s_body    = sanitize($config['welcome_body_md'] ?? '', SQL);
    $s_cta1    = sanitize($config['welcome_cta_primary'] ?? 'Take the tour', SQL);
    $s_cta2    = sanitize($config['welcome_cta_secondary'] ?? 'Explore on my own', SQL);
    $s_active  = !empty($config['is_active']) ? 1 : (isset($config['is_active']) ? 0 : 1);
    $s_app     = sanitize($config['created_by_app'] ?? 'core', SQL);

    db_query("INSERT INTO onboarding_tour
                (slug, name, welcome_title, welcome_body_md,
                 welcome_cta_primary, welcome_cta_secondary,
                 is_active, created_by_app)
              VALUES
                ('$s_slug', '$s_name', '$s_title', '$s_body',
                 '$s_cta1', '$s_cta2', '$s_active', '$s_app')
              ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                welcome_title = VALUES(welcome_title),
                welcome_body_md = VALUES(welcome_body_md),
                welcome_cta_primary = VALUES(welcome_cta_primary),
                welcome_cta_secondary = VALUES(welcome_cta_secondary),
                is_active = VALUES(is_active)");

    $row = db_fetch(db_query("SELECT tour_id FROM onboarding_tour WHERE slug = '$s_slug'"));
    if (!$row) return false;
    $tour_id = (int)$row['tour_id'];

    if (!empty($steps)) {
        db_query("DELETE FROM onboarding_tour_step WHERE tour_id = '$tour_id'");
        $order = 0;
        foreach ($steps as $step) {
            $order++;
            $s_sel    = sanitize($step['selector'] ?? '', SQL);
            $s_stitle = sanitize($step['title'] ?? '', SQL);
            $s_sbody  = sanitize($step['body_md'] ?? '', SQL);
            $s_pos    = sanitize($step['position'] ?? 'bottom', SQL);
            $s_act    = isset($step['action']) ? "'" . sanitize($step['action'], SQL) . "'" : 'NULL';
            $s_role   = isset($step['visible_if_role']) && $step['visible_if_role'] !== ''
                        ? "'" . sanitize($step['visible_if_role'], SQL) . "'"
                        : 'NULL';
            db_query("INSERT INTO onboarding_tour_step
                        (tour_id, step_order, selector, title, body_md, position, action, visible_if_role)
                      VALUES
                        ('$tour_id', '$order', '$s_sel', '$s_stitle', '$s_sbody', '$s_pos', $s_act, $s_role)");
        }
    }
    return $tour_id;
}

/**
 * Aggregate per-tour completion stats (across the calling shard).
 */
function get_tour_completion_stats($shard_id, $tour_slug) {
    $s_slug = sanitize($tour_slug, SQL);
    $r = db_query_shard($shard_id,
        "SELECT status, COUNT(*) AS cnt
           FROM onboarding_tour_state
          WHERE tour_slug = '$s_slug'
          GROUP BY status"
    );
    $out = ['not_started' => 0, 'in_progress' => 0, 'skipped' => 0, 'completed' => 0];
    if ($r) {
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['status']] = (int)$row['cnt'];
        }
    }
    return $out;
}

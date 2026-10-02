<?php
/**
 * useCaseRatingActions.php  (API task #2252)
 *
 * In-flow use-case rating, for the prompt an app shows a user right after they
 * complete a flow. These are member (session-authenticated) actions, so they are
 * live in every child app for free via the common.php include chain — no
 * app-specific code. The app:
 *   1. matches a completed flow to its use case (ending_action / action_path tail
 *      seen in the user's action log), then
 *   2. calls shouldPromptUseCaseRating to respect the shared daily prompt budget,
 *   3. shows its own prompt UI, and
 *   4. POSTs apiRateUseCase with the 1-5 rating + optional comment.
 */

// ── Should we prompt this user to rate this use case now? ─────────────────────
if (($_POST['action'] ?? '') == 'shouldPromptUseCaseRating') {
    $errs = array();
    if (!$_SESSION['user_id'])              { $errs['auth'] = 'Login required.'; }
    if (empty($_POST['use_case_id']))       { $errs['id']   = 'use_case_id is required.'; }

    if (count($errs) <= 0) {
        $uid   = (int)$_SESSION['user_id'];
        $ucid  = (int)$_POST['use_case_id'];
        $build = trim((string)($_POST['app_build'] ?? ''));

        // Authoritative source_app from the use case row (never trust the client).
        $r  = db_query_prepared("SELECT source_app FROM use_case WHERE use_case_id = ?", [$ucid]);
        $uc = $r ? db_fetch($r) : null;
        if (!$uc) {
            $_SESSION['error'] = 'Unknown use_case_id.';
        } else {
            $app = (string)$uc['source_app'];
            $opts = [
                'is_beta'  => !empty($_POST['is_beta']),
                'opted_in' => !empty($_POST['opted_in']),
            ];
            $decision = uc_should_prompt_rating($uid, $app, $ucid, $build, $opts);
            // Record the prompt as shown only when the caller says it will actually
            // show it (record=1), so a mere eligibility check doesn't burn budget.
            if ($decision['ok'] && !empty($_POST['record'])) {
                uc_record_prompt_shown($uid, $app, $ucid, $build);
            }
            $data['should_prompt'] = (bool)$decision['ok'];
            $data['reason']        = $decision['reason'];
            $_SESSION['success']   = 'OK';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Submit / update a rating for a use case (in-flow prompt) ──────────────────
if (($_POST['action'] ?? '') == 'apiRateUseCase') {
    $errs = array();
    if (!$_SESSION['user_id'])        { $errs['auth']   = 'Login required.'; }
    if (empty($_POST['use_case_id'])) { $errs['id']     = 'use_case_id is required.'; }
    $rating = (int)($_POST['rating'] ?? 0);
    if ($rating < 1 || $rating > 5)   { $errs['rating'] = 'rating must be 1-5.'; }

    if (count($errs) <= 0) {
        $uid     = (int)$_SESSION['user_id'];
        $ucid    = (int)$_POST['use_case_id'];
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($comment === '') { $comment = null; }
        $build   = trim((string)($_POST['app_build'] ?? ''));
        $platform= strtolower(trim((string)($_POST['platform'] ?? 'web')));
        if (!in_array($platform, ['ios','android','web'], true)) { $platform = 'web'; }

        $r  = db_query_prepared("SELECT source_app FROM use_case WHERE use_case_id = ?", [$ucid]);
        $uc = $r ? db_fetch($r) : null;
        if (!$uc) {
            $_SESSION['error'] = 'Unknown use_case_id.';
        } else {
            $app = (string)$uc['source_app'];
            db_query_prepared(
                "INSERT INTO use_case_rating
                    (use_case_id, source_app, user_id, rating, comment, app_build, platform, created, updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE rating = VALUES(rating),
                                         comment = VALUES(comment),
                                         platform = VALUES(platform),
                                         source_app = VALUES(source_app),
                                         updated = NOW()",
                [$ucid, $app, $uid, $rating, $comment, $build, $platform]
            );

            // Close out the prompt in the shared budget.
            db_query_prepared(
                "UPDATE engagement_prompt_log SET responded = 1
                 WHERE user_id = ? AND prompt_kind = 'use_case_rating' AND ref_id = ? AND app_build = ?",
                [$uid, $ucid, $build]
            );

            // Recompute health and check for a regression against the prior build.
            if (function_exists('uc_compute_health')) {
                uc_compute_health($ucid);
                uc_detect_regression($ucid);
            }

            $data['use_case_id'] = $ucid;
            $data['rating']      = $rating;
            $data['app_build']   = $build;
            $_SESSION['success'] = 'Thanks for the feedback!';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

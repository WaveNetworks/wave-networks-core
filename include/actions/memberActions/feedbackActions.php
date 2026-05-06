<?php
/**
 * Feedback Member Actions (authenticated users)
 * Actions: submitFeedback, getFeedbackData, upvoteFeedback, dismissFeedback,
 *          createChangeRequest, updateChangeRequest, groupFeedbackWithRequest,
 *          getChangeRequests
 */

// ── Submit feedback (any logged-in user) ────────────────────
if (($action ?? null) == 'submitFeedback') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    $message = trim($_POST['message'] ?? '');
    $type    = trim($_POST['feedback_type'] ?? 'general');

    if ($message === '') { $errs['message'] = 'Feedback message is required.'; }

    if (count($errs) <= 0) {
        $opts = [
            'source_app'  => $_POST['source_app'] ?? detect_source_app_from_url(),
            'page_url'    => $_POST['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => get_user_role_label(),
        ];

        // Feedback widget v2: screenshot + lightweight page context.
        // Capture columns are NULL-safe in submit_feedback() — missing fields
        // just preserve the legacy submission shape.
        if (!empty($_POST['screenshot'])) {
            $opts['screenshot_b64'] = $_POST['screenshot'];
        }
        if (!empty($_POST['context_json'])) {
            $opts['context_json'] = $_POST['context_json'];
            // Mirror viewport / capture_url out of the bundle into typed columns
            // so admin queries don't need JSON_EXTRACT for the common case.
            $ctx = json_decode($_POST['context_json'], true);
            if (is_array($ctx)) {
                if (isset($ctx['viewport_w'])) $opts['viewport_w'] = $ctx['viewport_w'];
                if (isset($ctx['viewport_h'])) $opts['viewport_h'] = $ctx['viewport_h'];
                if (!empty($ctx['url']))       $opts['capture_url'] = $ctx['url'];
            }
        }

        $fid = submit_feedback($message, $type, $opts);
        if ($fid !== false) {
            $data['feedback_id'] = $fid;
            $_SESSION['success'] = 'Thank you for your feedback!';
        } else {
            $_SESSION['error'] = 'Failed to submit feedback.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get feedback data (admin only, for admin view) ──────────
if (($action ?? null) == 'getFeedbackData') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $filters = [];
        if (!empty($_POST['feedback_type'])) $filters['feedback_type'] = $_POST['feedback_type'];
        if (!empty($_POST['source_app']))    $filters['source_app']    = $_POST['source_app'];
        if (!empty($_POST['status']))        $filters['status']        = $_POST['status'];
        if (!empty($_POST['search']))        $filters['search']        = $_POST['search'];
        if (!empty($_POST['page']))          $filters['page']          = $_POST['page'];
        if (!empty($_POST['per_page']))      $filters['per_page']      = $_POST['per_page'];
        if (!empty($_POST['change_request_id'])) $filters['change_request_id'] = $_POST['change_request_id'];

        $result = get_feedback_entries($filters);
        $data['items']       = $result['items'];
        $data['total']       = $result['total'];
        $data['page']        = $result['page'];
        $data['per_page']    = $result['per_page'];
        $data['stats']       = get_feedback_stats();
        $data['source_apps'] = get_feedback_source_apps();
        $data['user_upvotes'] = get_user_upvotes($_SESSION['user_id']);

        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Upvote feedback (admin/employee) ────────────────────────
if (($action ?? null) == 'upvoteFeedback') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('employee')) { $errs['role'] = 'Staff access required.'; }

    $fid = intval($_POST['feedback_id'] ?? 0);
    if ($fid <= 0) { $errs['id'] = 'Feedback ID required.'; }

    if (count($errs) <= 0) {
        $result = upvote_feedback($fid, $_SESSION['user_id']);
        $data['upvoted'] = $result['upvoted'];
        $data['upvotes'] = $result['upvotes'];
        $_SESSION['success'] = $result['upvoted'] ? 'Upvoted.' : 'Upvote removed.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Dismiss feedback (admin) ────────────────────────────────
if (($action ?? null) == 'dismissFeedback') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $fid = intval($_POST['feedback_id'] ?? 0);
    if ($fid <= 0) { $errs['id'] = 'Feedback ID required.'; }

    if (count($errs) <= 0) {
        $r = db_query("UPDATE feedback SET status = 'dismissed' WHERE feedback_id = '$fid'");
        if ($r) {
            $_SESSION['success'] = 'Feedback dismissed.';
        } else {
            $_SESSION['error'] = 'Failed to dismiss feedback: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Delete screenshot from a feedback row (admin) ──────────
// Lets admins purge a capture if PII slipped through, without nuking
// the feedback message itself.
if (($action ?? null) == 'deleteFeedbackScreenshot') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $fid = intval($_POST['feedback_id'] ?? 0);
    if ($fid <= 0) { $errs['id'] = 'Feedback ID required.'; }

    if (count($errs) <= 0) {
        if (delete_feedback_screenshot($fid)) {
            $_SESSION['success'] = 'Screenshot deleted.';
        } else {
            $_SESSION['error'] = 'Failed to delete screenshot.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Create change request (admin) ───────────────────────────
if (($action ?? null) == 'createChangeRequest') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type        = trim($_POST['request_type'] ?? '');
    $priority    = trim($_POST['priority'] ?? 'medium');

    if ($title === '') { $errs['title'] = 'Title required.'; }
    if (!in_array($type, ['change', 'addition'])) { $errs['type'] = 'Valid request type required.'; }

    if (count($errs) <= 0) {
        $opts = ['priority' => $priority];
        if (!empty($_POST['source_app']))  $opts['source_app']  = $_POST['source_app'];
        if (!empty($_POST['assigned_to'])) $opts['assigned_to'] = intval($_POST['assigned_to']);

        $crid = create_change_request($title, $description, $type, $_SESSION['user_id'], $opts);
        if ($crid !== false) {
            // If created from a specific feedback, group it
            if (!empty($_POST['feedback_id'])) {
                group_feedback_with_request(intval($_POST['feedback_id']), $crid);
            }
            $data['change_request_id'] = $crid;
            $_SESSION['success'] = 'Change request created.';
        } else {
            $_SESSION['error'] = 'Failed to create change request: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Update change request (admin) ───────────────────────────
if (($action ?? null) == 'updateChangeRequest') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $crid = intval($_POST['change_request_id'] ?? 0);
    if ($crid <= 0) { $errs['id'] = 'Change request ID required.'; }

    if (count($errs) <= 0) {
        $fields = [];
        if (isset($_POST['title']))        $fields['title']        = $_POST['title'];
        if (isset($_POST['description']))  $fields['description']  = $_POST['description'];
        if (isset($_POST['status']))       $fields['status']       = $_POST['status'];
        if (isset($_POST['priority']))     $fields['priority']     = $_POST['priority'];
        if (isset($_POST['assigned_to']))  $fields['assigned_to']  = $_POST['assigned_to'];
        if (isset($_POST['request_type'])) $fields['request_type'] = $_POST['request_type'];

        if (empty($fields)) {
            $_SESSION['error'] = 'No fields to update.';
        } elseif (update_change_request($crid, $fields)) {
            $_SESSION['success'] = 'Change request updated.';
        } else {
            $_SESSION['error'] = 'Failed to update change request: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Group feedback with change request (admin) ──────────────
if (($action ?? null) == 'groupFeedbackWithRequest') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    $fid  = intval($_POST['feedback_id'] ?? 0);
    $crid = intval($_POST['change_request_id'] ?? 0);

    if ($fid <= 0)  { $errs['fid'] = 'Feedback ID required.'; }
    if ($crid <= 0) { $errs['crid'] = 'Change request ID required.'; }

    if (count($errs) <= 0) {
        if (group_feedback_with_request($fid, $crid)) {
            $_SESSION['success'] = 'Feedback grouped with change request.';
        } else {
            $_SESSION['error'] = 'Failed to group feedback: ' . db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// ── Get change requests (admin, for admin view) ─────────────
if (($action ?? null) == 'getChangeRequests') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['role'] = 'Admin access required.'; }

    if (count($errs) <= 0) {
        $filters = [];
        if (!empty($_POST['status']))         $filters['status']         = $_POST['status'];
        if (!empty($_POST['exclude_status'])) $filters['exclude_status'] = $_POST['exclude_status'];
        if (!empty($_POST['request_type']))   $filters['request_type']   = $_POST['request_type'];
        if (!empty($_POST['priority']))     $filters['priority']     = $_POST['priority'];
        if (!empty($_POST['search']))       $filters['search']       = $_POST['search'];
        if (!empty($_POST['page']))         $filters['page']         = $_POST['page'];
        if (!empty($_POST['per_page']))     $filters['per_page']     = $_POST['per_page'];

        $result = get_change_requests($filters);
        $data['items']    = $result['items'];
        $data['total']    = $result['total'];
        $data['page']     = $result['page'];
        $data['per_page'] = $result['per_page'];
        $_SESSION['success'] = 'OK';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

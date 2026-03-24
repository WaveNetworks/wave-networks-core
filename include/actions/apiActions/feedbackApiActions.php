<?php
/**
 * Feedback API Actions
 * Actions: apiListFeedback, apiGetFeedback, apiSubmitFeedback, apiGetFeedbackStats,
 *          apiListChangeRequests, apiGetChangeRequest, apiCreateChangeRequest,
 *          apiUpdateChangeRequest, apiGroupFeedback, apiUpvoteFeedback
 * Authenticated via service API key (Bearer token) with scope gating.
 */

// ── List feedback (paginated, filtered) ─────────────────────
if (($action ?? null) == 'apiListFeedback') {
    if (require_api_scope('feedback:read')) {
        $filters = [];
        if (!empty($_POST['feedback_type'])) $filters['feedback_type'] = $_POST['feedback_type'];
        if (!empty($_POST['source_app']))    $filters['source_app']    = $_POST['source_app'];
        if (!empty($_POST['status']))        $filters['status']        = $_POST['status'];
        if (!empty($_POST['search']))        $filters['search']        = $_POST['search'];
        if (isset($_POST['user_id']) && $_POST['user_id'] !== '') $filters['user_id'] = $_POST['user_id'];
        if (isset($_POST['change_request_id']) && $_POST['change_request_id'] !== '') $filters['change_request_id'] = $_POST['change_request_id'];
        if (!empty($_POST['page']))          $filters['page']          = $_POST['page'];
        if (!empty($_POST['per_page']))      $filters['per_page']      = $_POST['per_page'];

        $result = get_feedback_entries($filters);
        $data['items']    = $result['items'];
        $data['total']    = $result['total'];
        $data['page']     = $result['page'];
        $data['per_page'] = $result['per_page'];
        $_SESSION['success'] = 'OK';
    }
}

// ── Get single feedback entry ───────────────────────────────
if (($action ?? null) == 'apiGetFeedback') {
    if (require_api_scope('feedback:read')) {
        $fid = intval($_POST['feedback_id'] ?? 0);
        if ($fid <= 0) {
            $_SESSION['error'] = 'feedback_id is required.';
        } else {
            $entry = get_feedback_by_id($fid);
            if ($entry) {
                $data['feedback'] = $entry;
                $_SESSION['success'] = 'OK';
            } else {
                $_SESSION['error'] = 'Feedback not found.';
            }
        }
    }
}

// ── Submit new feedback ─────────────────────────────────────
if (($action ?? null) == 'apiSubmitFeedback') {
    if (require_api_scope('feedback:write')) {
        $errs = [];
        $message = trim($_POST['message'] ?? '');
        $type    = trim($_POST['feedback_type'] ?? 'general');

        if ($message === '') { $errs['message'] = 'message is required.'; }

        if (count($errs) <= 0) {
            $opts = [];
            if (!empty($_POST['source_app']))    $opts['source_app']    = $_POST['source_app'];
            if (!empty($_POST['page_url']))      $opts['page_url']      = $_POST['page_url'];
            if (isset($_POST['user_id']) && $_POST['user_id'] !== '') $opts['user_id'] = intval($_POST['user_id']);
            if (!empty($_POST['user_role']))     $opts['user_role']     = $_POST['user_role'];
            if (!empty($_POST['context_json']))  $opts['context_json']  = $_POST['context_json'];

            $fid = submit_feedback($message, $type, $opts);
            if ($fid !== false) {
                $data['feedback_id'] = $fid;
                $_SESSION['success'] = 'Feedback submitted.';
            } else {
                $_SESSION['error'] = 'Failed to submit feedback.';
            }
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

// ── Get feedback stats ──────────────────────────────────────
if (($action ?? null) == 'apiGetFeedbackStats') {
    if (require_api_scope('feedback:read')) {
        $data['stats']       = get_feedback_stats();
        $data['source_apps'] = get_feedback_source_apps();
        $_SESSION['success'] = 'OK';
    }
}

// ── List change requests ────────────────────────────────────
if (($action ?? null) == 'apiListChangeRequests') {
    if (require_api_scope('feedback:read')) {
        $filters = [];
        if (!empty($_POST['status']))       $filters['status']       = $_POST['status'];
        if (!empty($_POST['request_type'])) $filters['request_type'] = $_POST['request_type'];
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
    }
}

// ── Get single change request with grouped feedback ─────────
if (($action ?? null) == 'apiGetChangeRequest') {
    if (require_api_scope('feedback:read')) {
        $crid = intval($_POST['change_request_id'] ?? 0);
        if ($crid <= 0) {
            $_SESSION['error'] = 'change_request_id is required.';
        } else {
            $cr = get_change_request_detail($crid);
            if ($cr) {
                $data['change_request'] = $cr;
                $_SESSION['success'] = 'OK';
            } else {
                $_SESSION['error'] = 'Change request not found.';
            }
        }
    }
}

// ── Create change request ───────────────────────────────────
if (($action ?? null) == 'apiCreateChangeRequest') {
    if (require_api_scope('feedback:admin')) {
        $errs = [];
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type        = trim($_POST['request_type'] ?? '');

        if ($title === '') { $errs['title'] = 'title is required.'; }
        if (!in_array($type, ['change', 'addition'])) { $errs['type'] = 'request_type must be change or addition.'; }

        if (count($errs) <= 0) {
            $opts = [];
            if (!empty($_POST['priority']))    $opts['priority']    = $_POST['priority'];
            if (!empty($_POST['source_app']))  $opts['source_app']  = $_POST['source_app'];
            if (!empty($_POST['assigned_to'])) $opts['assigned_to'] = intval($_POST['assigned_to']);

            // For API calls, use 0 as created_by (system/API)
            $created_by = intval($_POST['created_by'] ?? 0);

            $crid = create_change_request($title, $description, $type, $created_by, $opts);
            if ($crid !== false) {
                $data['change_request_id'] = $crid;
                $_SESSION['success'] = 'Change request created.';
            } else {
                $_SESSION['error'] = 'Failed to create change request.';
            }
        } else {
            $_SESSION['error'] = implode(' ', $errs);
        }
    }
}

// ── Update change request ───────────────────────────────────
if (($action ?? null) == 'apiUpdateChangeRequest') {
    if (require_api_scope('feedback:admin')) {
        $crid = intval($_POST['change_request_id'] ?? 0);
        if ($crid <= 0) {
            $_SESSION['error'] = 'change_request_id is required.';
        } else {
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
                $_SESSION['error'] = 'Failed to update change request.';
            }
        }
    }
}

// ── Group feedback with change request ──────────────────────
if (($action ?? null) == 'apiGroupFeedback') {
    if (require_api_scope('feedback:admin')) {
        $fid  = intval($_POST['feedback_id'] ?? 0);
        $crid = intval($_POST['change_request_id'] ?? 0);

        if ($fid <= 0 || $crid <= 0) {
            $_SESSION['error'] = 'feedback_id and change_request_id are required.';
        } elseif (group_feedback_with_request($fid, $crid)) {
            $_SESSION['success'] = 'Feedback grouped with change request.';
        } else {
            $_SESSION['error'] = 'Failed to group feedback.';
        }
    }
}

// ── Upvote feedback ─────────────────────────────────────────
if (($action ?? null) == 'apiUpvoteFeedback') {
    if (require_api_scope('feedback:write')) {
        $fid = intval($_POST['feedback_id'] ?? 0);
        $uid = intval($_POST['user_id'] ?? 0);

        if ($fid <= 0) {
            $_SESSION['error'] = 'feedback_id is required.';
        } else {
            if ($uid <= 0) $uid = 0; // API-created upvote
            $result = upvote_feedback($fid, $uid);
            $data['upvoted'] = $result['upvoted'];
            $data['upvotes'] = $result['upvotes'];
            $_SESSION['success'] = $result['upvoted'] ? 'Upvoted.' : 'Upvote removed.';
        }
    }
}

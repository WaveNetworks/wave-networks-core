<?php
/**
 * Email Drip Campaign Actions (admin only)
 * Actions: saveCampaign, deleteCampaign, saveStep, deleteStep, reorderSteps,
 *          getCampaignSteps
 */

if (($_POST['action'] ?? '') == 'saveCampaign') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    $slug        = trim($_POST['slug'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $trigger     = trim($_POST['trigger_event'] ?? '');
    $is_active   = (int)(!empty($_POST['is_active']));

    if (empty($slug)) { $errs['slug'] = 'Slug required.'; }
    if (empty($name)) { $errs['name'] = 'Name required.'; }
    if ($slug && !preg_match('/^[a-z0-9_\-]+$/', $slug)) {
        $errs['slug'] = 'Slug must be lowercase alphanumeric with - or _.';
    }

    if (count($errs) <= 0) {
        $s_slug = sanitize($slug, SQL);
        $s_name = sanitize($name, SQL);
        $s_desc = sanitize($description, SQL);
        $s_trig = sanitize($trigger, SQL);

        if ($campaign_id > 0) {
            $r = db_query("UPDATE email_drip_campaign
                              SET slug='$s_slug', name='$s_name', description='$s_desc',
                                  trigger_event='$s_trig', is_active='$is_active'
                            WHERE campaign_id='$campaign_id'");
        } else {
            $r = db_query("INSERT INTO email_drip_campaign
                              (slug, name, description, trigger_event, is_active, created_by_app, created)
                           VALUES
                              ('$s_slug', '$s_name', '$s_desc', '$s_trig', '$is_active', 'core', NOW())");
            if ($r) $campaign_id = (int)db_insert_id();
        }

        if ($r) {
            $_SESSION['success'] = 'Campaign saved.';
            $data['campaign_id'] = $campaign_id;
        } else {
            $_SESSION['error'] = 'Slug already exists or database error.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'deleteCampaign') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    if ($campaign_id <= 0) { $errs['id'] = 'Invalid campaign ID.'; }

    if (count($errs) <= 0) {
        // Find slug first so we can clean up enrollments + pending sends
        $row = db_fetch(db_query("SELECT slug FROM email_drip_campaign
                                   WHERE campaign_id='$campaign_id'"));
        if ($row) {
            $s_slug = sanitize($row['slug'], SQL);
            db_query("UPDATE email_drip_enrollment
                         SET status='unenrolled', completed_at=NOW()
                       WHERE campaign_slug='$s_slug' AND status='active'");
            db_query("UPDATE scheduled_email se
                       JOIN email_drip_enrollment e ON e.enrollment_id = se.enrollment_id
                          SET se.status='cancelled'
                       WHERE e.campaign_slug='$s_slug' AND se.status='pending'");
            db_query("DELETE FROM email_drip_step WHERE campaign_id='$campaign_id'");
            $r = db_query("DELETE FROM email_drip_campaign WHERE campaign_id='$campaign_id'");
            if ($r) {
                $_SESSION['success'] = 'Campaign deleted; active enrollments unenrolled.';
            } else {
                $_SESSION['error'] = db_error();
            }
        } else {
            $_SESSION['error'] = 'Campaign not found.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'saveStep') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $step_id       = (int)($_POST['step_id'] ?? 0);
    $campaign_id   = (int)($_POST['campaign_id'] ?? 0);
    $step_order    = (int)($_POST['step_order'] ?? 1);
    $delay_minutes = max(0, (int)($_POST['delay_minutes'] ?? 0));
    $template_slug = trim($_POST['template_slug'] ?? '');
    $skip_event    = trim($_POST['send_condition_event'] ?? '');

    if ($campaign_id <= 0)        { $errs['campaign'] = 'Campaign required.'; }
    if (empty($template_slug))    { $errs['template'] = 'Template required.'; }

    if (count($errs) <= 0) {
        $s_slug = sanitize($template_slug, SQL);
        $s_skip = $skip_event !== '' ? "'" . sanitize($skip_event, SQL) . "'" : 'NULL';

        if ($step_id > 0) {
            $r = db_query("UPDATE email_drip_step
                              SET step_order='$step_order', delay_minutes='$delay_minutes',
                                  template_slug='$s_slug', send_condition_event=$s_skip
                            WHERE step_id='$step_id'");
        } else {
            $r = db_query("INSERT INTO email_drip_step
                              (campaign_id, step_order, delay_minutes, template_slug,
                               send_condition_event, created)
                           VALUES
                              ('$campaign_id', '$step_order', '$delay_minutes',
                               '$s_slug', $s_skip, NOW())");
            if ($r) $step_id = (int)db_insert_id();
        }

        if ($r) {
            $_SESSION['success'] = 'Step saved.';
            $data['step_id'] = $step_id;
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'deleteStep') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $step_id = (int)($_POST['step_id'] ?? 0);
    if ($step_id <= 0) { $errs['id'] = 'Invalid step ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("DELETE FROM email_drip_step WHERE step_id='$step_id'");
        if ($r) {
            $_SESSION['success'] = 'Step deleted.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'reorderSteps') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $order_json = $_POST['order'] ?? '';
    $order = is_string($order_json) ? json_decode($order_json, true) : $order_json;
    if (!is_array($order)) { $errs['order'] = 'Invalid order payload.'; }

    if (count($errs) <= 0) {
        foreach ($order as $idx => $sid) {
            $sid = (int)$sid;
            $pos = (int)$idx + 1;
            if ($sid > 0) {
                db_query("UPDATE email_drip_step SET step_order='$pos' WHERE step_id='$sid'");
            }
        }
        $_SESSION['success'] = 'Step order updated.';
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'getCampaignSteps') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    if ($campaign_id <= 0) { $errs['id'] = 'Invalid campaign ID.'; }

    if (count($errs) <= 0) {
        $data['steps'] = get_email_drip_steps($campaign_id);
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

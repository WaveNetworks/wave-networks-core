<?php
/**
 * Email Template Actions (admin only)
 * Actions: saveEmailTemplate, deleteEmailTemplate, sendTestEmail, getEmailTemplate
 */

if (($_POST['action'] ?? '') == 'saveEmailTemplate') {
    $errs = array();

    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $template_id = (int)($_POST['template_id'] ?? 0);
    $slug        = trim($_POST['slug'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $subject     = trim($_POST['subject_tpl'] ?? '');
    $body        = $_POST['body_tpl'] ?? '';
    $body_format = ($_POST['body_format'] ?? 'html') === 'markdown' ? 'markdown' : 'html';

    if (empty($slug))    { $errs['slug']    = 'Slug required.'; }
    if (empty($name))    { $errs['name']    = 'Name required.'; }
    if (empty($subject)) { $errs['subject'] = 'Subject required.'; }
    if (!preg_match('/^[a-z0-9_\-]+$/', $slug)) {
        $errs['slug'] = 'Slug must be lowercase alphanumeric with - or _.';
    }

    if (count($errs) <= 0) {
        $s_slug    = sanitize($slug, SQL);
        $s_name    = sanitize($name, SQL);
        $s_subject = sanitize($subject, SQL);
        $s_body    = sanitize($body, SQL);
        $s_format  = sanitize($body_format, SQL);

        if ($template_id > 0) {
            $r = db_query("UPDATE email_template
                              SET slug='$s_slug', name='$s_name', subject_tpl='$s_subject',
                                  body_tpl='$s_body', body_format='$s_format', updated=NOW()
                            WHERE template_id='$template_id'");
        } else {
            $r = db_query("INSERT INTO email_template
                              (slug, name, subject_tpl, body_tpl, body_format, created_by_app, created, updated)
                           VALUES
                              ('$s_slug', '$s_name', '$s_subject', '$s_body', '$s_format', 'core', NOW(), NOW())");
            if ($r) $template_id = (int)db_insert_id();
        }
        if ($r) {
            $_SESSION['success'] = 'Template saved.';
            $data['template_id'] = $template_id;
        } else {
            $_SESSION['error'] = 'Slug already exists or database error.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'deleteEmailTemplate') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $template_id = (int)($_POST['template_id'] ?? 0);
    if ($template_id <= 0) { $errs['id'] = 'Invalid template ID.'; }

    if (count($errs) <= 0) {
        $r = db_query("DELETE FROM email_template WHERE template_id='$template_id'");
        if ($r) {
            $_SESSION['success'] = 'Template deleted.';
        } else {
            $_SESSION['error'] = db_error();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'getEmailTemplate') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $template_id = (int)($_POST['template_id'] ?? 0);
    if ($template_id <= 0) { $errs['id'] = 'Invalid template ID.'; }

    if (count($errs) <= 0) {
        $row = db_fetch(db_query("SELECT * FROM email_template WHERE template_id='$template_id'"));
        if ($row) {
            $data['template'] = $row;
        } else {
            $_SESSION['error'] = 'Template not found.';
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

// Render the template + queue an email to the logged-in admin so they can
// preview real output through the production SMTP path. Counts toward
// throttle just like any other queue_email() call.
if (($_POST['action'] ?? '') == 'sendTestEmail') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!has_role('admin'))    { $errs['auth'] = 'Admin access required.'; }

    $slug = trim($_POST['slug'] ?? '');
    if (empty($slug)) { $errs['slug'] = 'Template slug required.'; }

    if (count($errs) <= 0) {
        $uid = (int)$_SESSION['user_id'];
        $user = db_fetch(db_query("SELECT email, shard_id FROM user WHERE user_id='$uid'"));
        if (!$user || empty($user['email'])) {
            $_SESSION['error'] = 'Could not determine your email.';
        } else {
            $profile = function_exists('get_user_profile')
                ? get_user_profile($uid, $user['shard_id'])
                : [];

            // Demo / placeholder values for any vars the template may reference.
            $vars = [
                'first_name' => $profile['first_name'] ?? 'Test',
                'last_name'  => $profile['last_name']  ?? 'User',
                'email'      => $user['email'],
                'user_id'    => $uid,
                'app_name'   => 'Test',
                'site_name'  => function_exists('get_branding') ? (get_branding()['site_name'] ?? '') : '',
            ];
            // Allow caller to override / extend via posted JSON
            if (!empty($_POST['vars_json'])) {
                $extra = json_decode($_POST['vars_json'], true);
                if (is_array($extra)) $vars = array_merge($vars, $extra);
            }

            $rendered = render_email_template($slug, $vars);
            if (!$rendered) {
                $_SESSION['error'] = 'Template not found: ' . h($slug);
            } else {
                $body_html = $rendered['body'];
                $queue_id = queue_email(
                    $user['email'],
                    trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')),
                    '[TEST] ' . $rendered['subject'],
                    $body_html,
                    ['source_app' => 'template_test']
                );
                if ($queue_id) {
                    $_SESSION['success'] = 'Test queued for delivery to ' . h($user['email']) . '.';
                    $data['queue_id'] = $queue_id;
                } else {
                    $_SESSION['error'] = 'queue_email() rejected the message — check email settings.';
                }
            }
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

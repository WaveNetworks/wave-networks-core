<?php
/**
 * memberActions/legalActions.php — versioned Privacy Policy / Terms of Service.
 * Helpers: include/common/legalFunctions.php. Admin UI: views/legal.php.
 *
 * Admin (has_role('admin')):
 *   saveLegalDraft      — create/replace the draft of one document for one app scope
 *   discardLegalDraft   — drop that draft
 *   publishLegalVersion — publish the draft (or posted text) as a NEW immutable version
 *
 * Any signed-in user (web session or device Bearer token):
 *   getLegalStatus      — versions this user still has to accept (the in-app notice)
 *   acceptLegalUpdate   — accept exactly those versions (version_ids = what was shown)
 */

$__legal_action = $_POST['action'] ?? '';

if (($_POST['action'] ?? '') == 'saveLegalDraft' || ($_POST['action'] ?? '') == 'discardLegalDraft'
    || ($_POST['action'] ?? '') == 'publishLegalVersion') {
    $errs = array();
    $type = (string) ($_POST['consent_type'] ?? '');
    $app  = (string) ($_POST['app_slug'] ?? '');
    if (empty($_SESSION['user_id'])) { $errs['auth'] = 'Login required.'; }
    elseif (!has_role('admin'))      { $errs['auth'] = 'Admin access required.'; }
    if (!isset(wn_legal_types()[$type])) { $errs['type'] = 'Unknown document.'; }
    if ($app !== '' && !isset(wn_legal_apps()[$app])) { $errs['app'] = 'Unknown app.'; }
    $actor_id   = (int) ($_SESSION['user_id'] ?? 0);
    $actor_name = (string) ($_SESSION['email'] ?? '');
    $fields = [
        'title'                 => trim((string) ($_POST['title'] ?? '')),
        'content'               => (string) ($_POST['content'] ?? ''),
        'summary'               => trim((string) ($_POST['summary'] ?? '')),
        'requires_reacceptance' => !empty($_POST['requires_reacceptance']),
        'effective_date'        => trim((string) ($_POST['effective_date'] ?? '')),
        'version_label'         => trim((string) ($_POST['version_label'] ?? '')),
    ];

    if (count($errs) <= 0 && $__legal_action === 'saveLegalDraft') {
        if (wn_legal_save_draft($type, $app, $fields, $actor_id, $actor_name)) {
            $_SESSION['success'] = 'Draft saved. Nothing is published until you publish it.';
        } else {
            $errs['db'] = 'The draft could not be saved.';
        }
    }

    if (count($errs) <= 0 && $__legal_action === 'discardLegalDraft') {
        wn_legal_discard_draft($type, $app);
        $_SESSION['success'] = 'Draft discarded.';
    }

    if (count($errs) <= 0 && $__legal_action === 'publishLegalVersion') {
        if (trim($fields['summary']) === '') { $errs['summary'] = 'Say what changed — people see this summary.'; }
        if (count($errs) <= 0) {
            // Whatever is in the editor is what gets published; keep it as the draft first so
            // a refused publish never loses typed text.
            wn_legal_save_draft($type, $app, $fields, $actor_id, $actor_name);
            $res = wn_legal_publish($type, $app, $fields, $actor_id, $actor_name);
            if (!empty($res['errors'])) {
                $errs = $res['errors'];
            } else {
                $data['version_id']    = $res['version_id'];
                $data['version_label'] = $res['version_label'];
                $_SESSION['success'] = wn_legal_types()[$type]['title'] . ' version ' . $res['version_label'] . ' published'
                    . ($fields['effective_date'] !== '' && $fields['effective_date'] > wn_legal_today() ? ', effective ' . $fields['effective_date'] : '')
                    . ($fields['requires_reacceptance'] ? '. Signed-in users will be asked to accept it.' : '.');
            }
        } else {
            wn_legal_save_draft($type, $app, $fields, $actor_id, $actor_name);
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'getLegalStatus') {
    $errs = array();
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if (!$uid) { $errs['auth'] = 'Login required.'; }
    if (count($errs) <= 0) {
        $pending = function_exists('check_reconsent_needed') ? check_reconsent_needed($uid) : [];
        $list = [];
        foreach ($pending as $type => $v) {
            // The notice is only for a version somebody PUBLISHED here (Admin › Privacy &
            // Terms, or an app's legal.json default). The 2.5 seed rows (no text, no
            // published_at) never raise it on any app; the sign-in gate is unchanged.
            if (empty($v['published_at'])) continue;
            $list[] = [
                'type'           => $type,
                'title'          => !empty($v['title']) ? $v['title'] : wn_legal_types()[$type]['title'],
                'version_id'     => (int) $v['version_id'],
                'version_label'  => (string) $v['version_label'],
                'effective_date' => (string) $v['effective_date'],
                'summary'        => (string) ($v['summary'] ?? ''),
                'url'            => (string) ($v['url'] ?? wn_legal_public_url($type, null, null, true)),
                'kind'           => (string) ($v['kind'] ?? 'update'),
            ];
        }
        $data['pending'] = $list;
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

if (($_POST['action'] ?? '') == 'acceptLegalUpdate') {
    $errs = array();
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if (!$uid) { $errs['auth'] = 'Login required.'; }
    $ids = trim((string) ($_POST['version_ids'] ?? ''));
    if ($ids === '') { $errs['versions'] = 'Nothing to accept.'; }
    $src = (($_POST['client'] ?? '') === 'app') ? 'notice_app' : 'notice_web';
    if (count($errs) <= 0) {
        $res = wn_legal_accept($uid, $ids, $src);
        if (!empty($res['errors'])) {
            $errs = $res['errors'];
        } else {
            $data['accepted'] = $res['accepted'];
            unset($_SESSION['reconsent_needed']);
            $_SESSION['success'] = 'Thanks — you have accepted the updated terms.';
        }
    }
    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

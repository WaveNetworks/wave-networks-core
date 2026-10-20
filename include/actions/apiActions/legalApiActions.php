<?php
/**
 * legalApiActions.php — Privacy Policy / Terms of Service through a service API key (owner
 * 2026-10-20: "add it to the core": staff should only have to check a draft and click Publish).
 *
 *   apiGetLegalDocument   (legal:read)  consent_type, app_slug → the version in force (with its
 *                                       text), every published version, and the draft
 *   apiSaveLegalDraft     (legal:write) consent_type, app_slug, content, summary, title,
 *                                       requires_reacceptance, effective_date → the draft the
 *                                       admin page opens; publishing stays on that page
 *   apiResetLegalHistory  (legal:write) consent_type, app_slug, content, summary, title,
 *                                       confirm = "<consent_type>:<app_slug>" → that one
 *                                       document's history is replaced by a single 1.0
 *                                       (wn_legal_reset_history; acceptances stay)
 *
 * app_slug is the child app (e.g. contactsweep); '' = the whole-site document (read and drafts
 * only — its history is never reset). Both scopes are on the monitoring key (monitor_key_scopes).
 */

if (in_array(($action ?? null), ['apiGetLegalDocument', 'apiSaveLegalDraft', 'apiResetLegalHistory'], true)) {
    $errs = array();
    $type = (string) ($_POST['consent_type'] ?? '');
    $app  = (string) ($_POST['app_slug'] ?? '');
    $scope_ok = require_api_scope($action === 'apiGetLegalDocument' ? 'legal:read' : 'legal:write');
    if ($scope_ok) {
        if (!isset(wn_legal_types()[$type])) { $errs['type'] = 'consent_type must be one of: ' . implode(', ', array_keys(wn_legal_types())) . '.'; }
        if ($app !== '' && !isset(wn_legal_apps()[$app])) { $errs['app'] = 'Unknown app_slug.'; }
    }
    $actor_id   = (int) ($_SERVICE_API_KEY['created_by'] ?? 0) ?: null;
    $actor_name = 'api: ' . (string) ($_SERVICE_API_KEY['key_name'] ?? 'service key');
    $fields = [
        'title'                 => trim((string) ($_POST['title'] ?? '')),
        'content'               => (string) ($_POST['content'] ?? ''),
        'summary'               => trim((string) ($_POST['summary'] ?? '')),
        'requires_reacceptance' => !empty($_POST['requires_reacceptance']),
        'effective_date'        => trim((string) ($_POST['effective_date'] ?? '')),
    ];

    if ($scope_ok && count($errs) <= 0 && $action === 'apiGetLegalDocument') {
        $cur = wn_legal_current($type, $app);
        $data['current'] = $cur ? [
            'version_id' => (int) $cur['version_id'], 'version_label' => (string) $cur['version_label'],
            'app_slug' => (string) $cur['app_slug'], 'effective_date' => (string) $cur['effective_date'],
            'title' => (string) ($cur['title'] ?? ''), 'summary' => (string) ($cur['summary'] ?? ''),
            'content' => (string) ($cur['content'] ?? ''),
        ] : null;
        $data['versions'] = array_map(function ($v) {
            return ['version_id' => (int) $v['version_id'], 'version_label' => (string) $v['version_label'],
                    'effective_date' => (string) $v['effective_date'], 'summary' => (string) ($v['summary'] ?? ''),
                    'requires_reacceptance' => (int) ($v['requires_reacceptance'] ?? 0), 'published_at' => (string) ($v['published_at'] ?? '')];
        }, wn_legal_versions($type, $app));
        $dr = wn_legal_draft($type, $app);
        $data['draft'] = $dr ? ['content' => (string) $dr['content'], 'summary' => (string) ($dr['summary'] ?? ''),
                                'updated' => (string) ($dr['updated'] ?? ''), 'updated_by_name' => (string) ($dr['updated_by_name'] ?? '')] : null;
        $_SESSION['success'] = 'OK';
    }

    if ($scope_ok && count($errs) <= 0 && $action === 'apiSaveLegalDraft') {
        if (trim($fields['content']) === '') { $errs['content'] = 'content is required.'; }
        elseif (wn_legal_save_draft($type, $app, $fields, $actor_id, $actor_name)) {
            $_SESSION['success'] = 'Draft saved. Nothing is published until someone publishes it from Settings › Privacy & Terms.';
        } else {
            $errs['db'] = 'The draft could not be saved.';
        }
    }

    if ($scope_ok && count($errs) <= 0 && $action === 'apiResetLegalHistory') {
        if ((string) ($_POST['confirm'] ?? '') !== $type . ':' . $app) {
            $errs['confirm'] = 'confirm must be "' . $type . ':' . $app . '" — this deletes every published version of that document.';
        } else {
            $res = wn_legal_reset_history($type, $app, $fields, $actor_id, $actor_name);
            if (!empty($res['errors'])) {
                $errs = $res['errors'];
            } else {
                $data['version_id']    = $res['version_id'];
                $data['version_label'] = $res['version_label'];
                $data['removed']       = $res['removed'];
                $_SESSION['success'] = wn_legal_types()[$type]['title'] . ' started over as version 1.0 (' . $res['removed'] . ' earlier version(s) removed).';
            }
        }
    }

    if (count($errs) > 0) {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}

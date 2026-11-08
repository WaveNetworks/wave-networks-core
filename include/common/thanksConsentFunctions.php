<?php
/**
 * thanksConsentFunctions.php — may we thank someone by first name for a request we built? (task #2725)
 *
 * Owner 2026-11-08: "it also may be a cool idea to thank people by first name for feature requests
 * that make it in the app." Opt-in and off by default. Asked on the feedback form here and on the
 * beta-testing and help-desk forms, which share these functions. One row per email in this app's
 * own admin database. The story-card check reads the CURRENT value, so withdrawing removes the
 * name from anything not yet published.
 */

if (!function_exists('wn_thanks_question')) {
    function wn_thanks_question() {
        return 'If we build something you asked for, may we thank you by first name in our videos and posts?';
    }

    /** One plain first name, or '' when what we have is not usable as one. */
    function wn_thanks_first_name($name) {
        $first = preg_split('/\s+/u', trim((string) $name))[0] ?? '';
        if ($first === '' || mb_strlen($first) > 40) return '';
        return preg_match("/^\p{L}[\p{L}\p{M}'’-]*$/u", $first) ? $first : '';
    }

    /** The stored row for an email, or null. */
    function wn_thanks_get($email) {
        $r = db_query_prepared('SELECT * FROM thanks_consent WHERE email = ? LIMIT 1', [strtolower(trim((string) $email))]);
        $row = $r ? db_fetch($r) : null;
        return $row ?: null;
    }

    function wn_thanks_allowed($email) {
        $row = wn_thanks_get($email);
        return (bool) ($row && (int) $row['allowed'] === 1);
    }

    /** Record a decision. A first name already held is kept when this form did not supply one. */
    function wn_thanks_set($email, $allowed, $first_name, $where, $user_id = null) {
        $email = strtolower(trim((string) $email));
        if (!valid_email($email)) return false;
        $first = wn_thanks_first_name($first_name);
        return (bool) db_query_prepared(
            'INSERT INTO thanks_consent (email, user_id, first_name, allowed, decided_where) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), decided_where = VALUES(decided_where),
                 user_id = COALESCE(VALUES(user_id), user_id), first_name = COALESCE(VALUES(first_name), first_name)',
            [$email, $user_id ? (int) $user_id : null, $first !== '' ? $first : null, $allowed ? 1 : 0, substr((string) $where, 0, 40)]);
    }

    /**
     * Apply a submitted form. Nothing changes unless the box differs from how it was shown, so an
     * unticked box on a later form can never read as a withdrawal the person did not make.
     */
    function wn_thanks_apply_post($email, $first_name, $where, $user_id = null) {
        if (!isset($_POST['thanks_was'])) return false;
        $was = $_POST['thanks_was'] === '1';
        $now = !empty($_POST['thanks_consent']);
        return $was === $now ? false : wn_thanks_set($email, $now, $first_name, $where, $user_id);
    }

    /**
     * A yes from a form with no sign-in behind it (the public beta signup). Anyone can type any
     * address there, so it waits, and never touches a decision already on file.
     */
    function wn_thanks_set_pending($email, $first_name, $where) {
        $email = strtolower(trim((string) $email));
        if (!valid_email($email)) return false;
        $first = wn_thanks_first_name($first_name);
        return (bool) db_query_prepared('INSERT IGNORE INTO thanks_consent (email, first_name, allowed, decided_where) VALUES (?, ?, 0, ?)',
            [$email, $first !== '' ? $first : null, 'pending:' . substr((string) $where, 0, 30)]);
    }

    /** They opened a link sent to that address, so the pending yes was theirs. */
    function wn_thanks_confirm_pending($email) {
        return (bool) db_query_prepared("UPDATE thanks_consent SET allowed = 1, decided_where = SUBSTRING(decided_where, 9) WHERE email = ? AND decided_where LIKE 'pending:%'",
            [strtolower(trim((string) $email))]);
    }

    /** The question as a Bootstrap checkbox. Pass '' as the email for someone we do not know yet. */
    function wn_thanks_checkbox_html($email, $id = 'wnThanksConsent') {
        $on = $email !== '' && wn_thanks_allowed($email);
        return '<div class="form-check mb-3"><input type="hidden" name="thanks_was" value="' . ($on ? '1' : '0') . '">'
             . '<input class="form-check-input" type="checkbox" name="thanks_consent" value="1" id="' . h($id) . '"' . ($on ? ' checked' : '') . '>'
             . '<label class="form-check-label" for="' . h($id) . '">' . h(wn_thanks_question()) . '</label></div>';
    }
}

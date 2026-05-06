<?php
/**
 * emailSchedulingFunctions.php
 * Scheduled email sends, drip campaigns, and reusable templates on top of
 * the existing email_queue / queue_email() pipeline. Auto-included via
 * common.php glob — available to all apps.
 *
 * The cron at admin/cron/minutes/5/process_scheduled_emails.php drains
 * scheduled_email rows whose scheduled_for has passed, renders the named
 * email_template with the row's context_json, calls queue_email(), and
 * advances any drip enrollment that owns the row.
 */

/**
 * Render a Mustache-style template by slug, returning subject + body.
 * Variable interpolation supports `{{ key }}` and `{{key}}` (whitespace
 * tolerant) for top-level scalar values in $vars. Missing keys render
 * as the empty string.
 *
 * @param string $slug
 * @param array  $vars
 * @return array|false  ['subject'=>..., 'body'=>..., 'body_format'=>'html'|'markdown'] or false on missing template
 */
function render_email_template($slug, $vars = []) {
    global $db;

    $s_slug = sanitize($slug, SQL);
    try {
        $r = $db->query("SELECT subject_tpl, body_tpl, body_format
                           FROM email_template WHERE slug = '$s_slug' LIMIT 1");
        $row = $r->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$row) return false;

    $subject = _interpolate_email_template($row['subject_tpl'] ?? '', $vars);
    $body    = _interpolate_email_template($row['body_tpl'] ?? '', $vars);

    return [
        'subject'     => $subject,
        'body'        => $body,
        'body_format' => $row['body_format'] ?? 'html',
    ];
}

/**
 * Internal: replace {{ key }} tokens with $vars[key]. Non-scalar values
 * are stringified to ''. Used by render_email_template().
 *
 * @param string $tpl
 * @param array  $vars
 * @return string
 */
function _interpolate_email_template($tpl, $vars) {
    if ($tpl === '' || $tpl === null) return '';
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function($m) use ($vars) {
        $key = $m[1];
        $val = $vars[$key] ?? '';
        if (is_array($val) || is_object($val)) return '';
        return (string)$val;
    }, $tpl);
}

/**
 * Queue an email to be rendered + sent at $when.
 * $when accepts DateTime, DateTimeImmutable, a timestamp, or any
 * strtotime()-parsable string ("+10 minutes", "2026-05-10 09:00", etc.).
 *
 * @param int          $user_id
 * @param string       $template_slug
 * @param mixed        $when
 * @param array        $context
 * @param array        $opts  Optional: enrollment_id, drip_step_id, skip_event
 * @return int|false   scheduled_id on success
 */
function schedule_email($user_id, $template_slug, $when, $context = [], $opts = []) {
    global $db;

    $user_id = (int)$user_id;
    if ($user_id <= 0) return false;
    if (empty($template_slug)) return false;

    $when_ts = _normalize_email_schedule_time($when);
    if ($when_ts === false) return false;

    $s_slug    = sanitize($template_slug, SQL);
    $s_when    = sanitize(date('Y-m-d H:i:s', $when_ts), SQL);
    $s_context = sanitize(json_encode($context ?: new stdClass()), SQL);

    $enrollment_id = isset($opts['enrollment_id']) ? (int)$opts['enrollment_id'] : 0;
    $drip_step_id  = isset($opts['drip_step_id'])  ? (int)$opts['drip_step_id']  : 0;
    $skip_event    = sanitize($opts['skip_event'] ?? '', SQL);

    $enrollment_sql = $enrollment_id > 0 ? "'$enrollment_id'" : 'NULL';
    $step_sql       = $drip_step_id > 0  ? "'$drip_step_id'"  : 'NULL';
    $skip_sql       = $skip_event !== '' ? "'$skip_event'"    : 'NULL';

    $sql = "INSERT INTO scheduled_email
            (user_id, template_slug, scheduled_for, context_json,
             enrollment_id, drip_step_id, skip_event, status, created)
            VALUES
            ('$user_id', '$s_slug', '$s_when', '$s_context',
             $enrollment_sql, $step_sql, $skip_sql, 'pending', NOW())";

    $r = db_query($sql);
    if ($r) return (int)db_insert_id();
    return false;
}

/**
 * Internal: normalize a $when argument into a unix timestamp.
 *
 * @param mixed $when
 * @return int|false
 */
function _normalize_email_schedule_time($when) {
    if ($when instanceof DateTimeInterface) return $when->getTimestamp();
    if (is_int($when))                       return $when;
    if (is_numeric($when))                   return (int)$when;
    if (is_string($when) && $when !== '') {
        $ts = strtotime($when);
        if ($ts !== false) return $ts;
    }
    return false;
}

/**
 * Enrol a user in a drip campaign. Idempotent — if the user is already
 * actively enrolled, returns the existing enrollment_id without scheduling
 * a duplicate first step.
 *
 * @param int    $user_id
 * @param string $campaign_slug
 * @param array  $context  Vars merged into every step's render context
 * @return int|false
 */
function enroll_in_drip($user_id, $campaign_slug, $context = []) {
    global $db;

    $user_id = (int)$user_id;
    if ($user_id <= 0 || empty($campaign_slug)) return false;

    $s_slug = sanitize($campaign_slug, SQL);

    // Resolve campaign and its first step
    try {
        $r = $db->query("SELECT campaign_id, is_active FROM email_drip_campaign
                          WHERE slug = '$s_slug' LIMIT 1");
        $campaign = $r->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$campaign || !$campaign['is_active']) return false;

    $campaign_id = (int)$campaign['campaign_id'];

    // Already enrolled?
    try {
        $r2 = $db->query("SELECT enrollment_id, status FROM email_drip_enrollment
                           WHERE user_id = '$user_id' AND campaign_slug = '$s_slug' LIMIT 1");
        $existing = $r2->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if ($existing && $existing['status'] === 'active') {
        return (int)$existing['enrollment_id'];
    }

    // Insert / reactivate enrollment
    $s_context = sanitize(json_encode($context ?: new stdClass()), SQL);
    if ($existing) {
        $eid = (int)$existing['enrollment_id'];
        db_query("UPDATE email_drip_enrollment
                     SET status = 'active', current_step = 0, completed_at = NULL,
                         context_json = '$s_context', enrolled_at = NOW()
                   WHERE enrollment_id = '$eid'");
    } else {
        db_query("INSERT INTO email_drip_enrollment
                     (user_id, campaign_slug, current_step, status, context_json, enrolled_at)
                  VALUES
                     ('$user_id', '$s_slug', 0, 'active', '$s_context', NOW())");
        $eid = (int)db_insert_id();
    }

    // Schedule step 1
    advance_drip_enrollment($eid);

    return $eid;
}

/**
 * Schedule the next pending step for an enrollment, or mark it completed
 * if no further steps exist. Called once on enroll, and once after each
 * scheduled_email row owned by this enrollment is sent.
 *
 * @param int $enrollment_id
 * @return bool
 */
function advance_drip_enrollment($enrollment_id) {
    global $db;

    $enrollment_id = (int)$enrollment_id;
    if ($enrollment_id <= 0) return false;

    try {
        $r = $db->query("SELECT * FROM email_drip_enrollment
                          WHERE enrollment_id = '$enrollment_id' LIMIT 1");
        $enrollment = $r->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$enrollment || $enrollment['status'] !== 'active') return false;

    $next_step = (int)$enrollment['current_step'] + 1;
    $s_camp = sanitize($enrollment['campaign_slug'], SQL);

    // Find campaign id for the step lookup
    try {
        $rc = $db->query("SELECT campaign_id FROM email_drip_campaign
                           WHERE slug = '$s_camp' LIMIT 1");
        $campaign = $rc->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$campaign) return false;
    $campaign_id = (int)$campaign['campaign_id'];

    try {
        $rs = $db->query("SELECT * FROM email_drip_step
                           WHERE campaign_id = '$campaign_id' AND step_order = '$next_step'
                           LIMIT 1");
        $step = $rs->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }

    if (!$step) {
        // No more steps — mark complete
        db_query("UPDATE email_drip_enrollment
                     SET status = 'completed', completed_at = NOW(), next_send_at = NULL
                   WHERE enrollment_id = '$enrollment_id'");
        return true;
    }

    $context = json_decode($enrollment['context_json'] ?? '{}', true) ?: [];
    $delay   = (int)$step['delay_minutes'];
    $when_ts = time() + ($delay * 60);

    schedule_email(
        (int)$enrollment['user_id'],
        $step['template_slug'],
        $when_ts,
        $context,
        [
            'enrollment_id' => $enrollment_id,
            'drip_step_id'  => (int)$step['step_id'],
            'skip_event'    => $step['send_condition_event'] ?? '',
        ]
    );

    $s_when = sanitize(date('Y-m-d H:i:s', $when_ts), SQL);
    db_query("UPDATE email_drip_enrollment
                 SET current_step = '$next_step', next_send_at = '$s_when'
               WHERE enrollment_id = '$enrollment_id'");

    return true;
}

/**
 * Unenrol a user from a campaign. Cancels any pending scheduled_email rows
 * tied to the enrollment. Used by unsubscribe links and admin opt-out.
 *
 * @param int    $user_id
 * @param string $campaign_slug
 * @return bool
 */
function cancel_drip_enrollment($user_id, $campaign_slug) {
    global $db;

    $user_id = (int)$user_id;
    if ($user_id <= 0 || empty($campaign_slug)) return false;

    $s_slug = sanitize($campaign_slug, SQL);

    try {
        $r = $db->query("SELECT enrollment_id FROM email_drip_enrollment
                          WHERE user_id = '$user_id' AND campaign_slug = '$s_slug' LIMIT 1");
        $row = $r->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$row) return false;

    $eid = (int)$row['enrollment_id'];

    db_query("UPDATE email_drip_enrollment
                 SET status = 'unenrolled', next_send_at = NULL, completed_at = NOW()
               WHERE enrollment_id = '$eid'");

    db_query("UPDATE scheduled_email
                 SET status = 'cancelled'
               WHERE enrollment_id = '$eid' AND status = 'pending'");

    return true;
}

/**
 * Mark any pending scheduled_email rows for this user where skip_event
 * matches $event_slug as cancelled. The 'skip if user has done X' logic.
 * Call this from your action code when a relevant user event happens
 * (e.g. fire_email_event($uid, 'coach_added')).
 *
 * @param int    $user_id
 * @param string $event_slug
 * @return int   number of rows cancelled
 */
function fire_email_event($user_id, $event_slug) {
    global $db;

    $user_id = (int)$user_id;
    if ($user_id <= 0 || empty($event_slug)) return 0;

    $s_event = sanitize($event_slug, SQL);

    try {
        db_query("UPDATE scheduled_email
                     SET status = 'cancelled'
                   WHERE user_id = '$user_id'
                     AND skip_event = '$s_event'
                     AND status = 'pending'");
        return (int)$db->query("SELECT ROW_COUNT() as cnt")->fetch(PDO::FETCH_ASSOC)['cnt'];
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Idempotent insert into the email_trigger_event reference table. Child
 * apps call this at bootstrap to register app-specific events that the
 * admin UI then exposes as drip triggers / step skip-conditions.
 *
 * @param string $slug
 * @param string $label
 * @param array  $opts  Optional: description, created_by_app
 * @return bool
 */
function add_email_trigger_event($slug, $label, $opts = []) {
    global $db;

    if (empty($slug)) return false;

    $s_slug  = sanitize($slug, SQL);
    $s_label = sanitize($label ?: $slug, SQL);
    $s_desc  = sanitize($opts['description'] ?? '', SQL);
    $s_app   = sanitize($opts['created_by_app'] ?? 'core', SQL);

    try {
        db_query("INSERT INTO email_trigger_event (slug, label, description, created_by_app, created)
                  VALUES ('$s_slug', '$s_label', '$s_desc', '$s_app', NOW())
                  ON DUPLICATE KEY UPDATE label = VALUES(label),
                                          description = VALUES(description)");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * List all registered trigger events (for the admin UI dropdowns).
 *
 * @return array
 */
function list_email_trigger_events() {
    global $db;
    try {
        $r = $db->query("SELECT slug, label, description, created_by_app
                           FROM email_trigger_event ORDER BY slug ASC");
        return $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Pending scheduled_email rows + active drip enrollments for a single user,
 * for the user_edit "Email schedule" support card.
 *
 * @param int $user_id
 * @return array  ['scheduled' => [...], 'enrollments' => [...]]
 */
function get_user_email_schedule($user_id) {
    global $db;

    $user_id = (int)$user_id;
    $out = ['scheduled' => [], 'enrollments' => []];
    if ($user_id <= 0) return $out;

    try {
        $r = $db->query("SELECT scheduled_id, template_slug, scheduled_for, status,
                                enrollment_id, skip_event, created
                           FROM scheduled_email
                          WHERE user_id = '$user_id' AND status = 'pending'
                          ORDER BY scheduled_for ASC LIMIT 50");
        $out['scheduled'] = $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    try {
        $r2 = $db->query("SELECT enrollment_id, campaign_slug, current_step, next_send_at,
                                 status, enrolled_at
                            FROM email_drip_enrollment
                           WHERE user_id = '$user_id'
                             AND status IN ('active','paused')
                           ORDER BY enrolled_at DESC LIMIT 20");
        $out['enrollments'] = $r2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    return $out;
}

/**
 * List campaigns with their step + active-enrollment counts (admin UI).
 *
 * @return array
 */
function list_email_drip_campaigns() {
    global $db;
    try {
        $r = $db->query("SELECT c.*,
                                (SELECT COUNT(*) FROM email_drip_step s
                                  WHERE s.campaign_id = c.campaign_id) AS step_count,
                                (SELECT COUNT(*) FROM email_drip_enrollment e
                                  WHERE e.campaign_slug = c.slug AND e.status = 'active') AS active_enrollments
                           FROM email_drip_campaign c
                          ORDER BY c.created DESC");
        return $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @return array
 */
function list_email_templates() {
    global $db;
    try {
        $r = $db->query("SELECT template_id, slug, name, body_format, created_by_app, created, updated
                           FROM email_template ORDER BY name ASC");
        return $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @param int $campaign_id
 * @return array
 */
function get_email_drip_steps($campaign_id) {
    global $db;
    $campaign_id = (int)$campaign_id;
    if ($campaign_id <= 0) return [];
    try {
        $r = $db->query("SELECT * FROM email_drip_step
                          WHERE campaign_id = '$campaign_id'
                          ORDER BY step_order ASC, step_id ASC");
        return $r->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

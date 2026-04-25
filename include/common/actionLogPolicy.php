<?php
/**
 * Action logging policy — default-deny param allowlist + noisy-endpoint deny list.
 *
 * WHY DEFAULT-DENY MATTERS FOR GDPR
 * ---------------------------------
 * user_action_log / device_action_log are retention-limited telemetry, not a
 * content store. If a new action ships and we forget to update policy, the
 * fail-safe is to log the action name + metadata but drop ALL params — never
 * the other way around. That prevents message bodies, passwords, PII, or
 * secrets from ending up in logs by accident. Opting a param IN is a
 * deliberate choice; opting IN by default is how leaks happen.
 *
 * HOW TO ADD A NEW ALLOWLIST ENTRY
 * --------------------------------
 * 1. Confirm the param is safe to retain (not a secret, not freeform PII,
 *    not a message body). When in doubt, leave it out — the log still
 *    captures the action name, user, device, timestamp, and result.
 * 2. Add a row to ACTION_LOG_PARAM_ALLOWLIST keyed by the action name,
 *    with an array of exact param keys you want kept. Everything else is
 *    dropped (not redacted in-place — the key is removed entirely).
 * 3. Deploy. New logs respect the update immediately.
 *
 * HOW TO TEST
 * -----------
 * - Submit the action with a superset of params (include a field you
 *   expect to be dropped, e.g. a fake "secret" key).
 * - Query the log: SELECT params_json FROM user_action_log
 *   WHERE action = '<name>' ORDER BY created DESC LIMIT 1;
 * - Confirm only allowlisted keys are present.
 *
 * DENY LIST
 * ---------
 * ACTION_LOG_DENY is for high-frequency endpoints (heartbeat, polling)
 * that would flood the log without telling us anything useful. They are
 * dropped entirely — no row is inserted.
 */

const ACTION_LOG_PARAM_ALLOWLIST = [
    'addUser'        => ['email', 'role'],           // not password
    'updateProfile'  => ['display_name', 'theme'],   // not phone, address
    'createApiKey'   => ['key_name'],                // not the key itself
    'sendFeedback'   => ['feedback_type'],           // not the message body
    'login'          => ['email'],                   // not password, not 2fa code
    // Add more here as actions ship.
];

const ACTION_LOG_DENY = [
    'heartbeat',
    'ping',
    'csrf_refresh',
    'notification_poll',
    'email_queue_status',
    'session_keepalive',
    'pollNotifications',
    // 2026-04-25: bs-init.js / notifications.js fire these on a 60s
    // timer regardless of user activity — they were swamping the log
    // at ~99% of rows for any active session, drowning out the real
    // action sequences the use-case derivation needs.
    'getNotifications',
    'checkSession',
];

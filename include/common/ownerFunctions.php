<?php
/**
 * ownerFunctions.php — who is the owner, and who is a human (task #2779).
 *
 * user.is_owner and user.is_agent (main/7.2) are written ONLY by scripts/set-owner.php,
 * run over SSH. Nothing in the admin UI or the API sets either, so an admin cannot make
 * themselves owner and an API key cannot mint one.
 *
 * wn_is_owner_session()  the signed-in person is the owner (fresh from the DB, not the session)
 * wn_is_human_session()  a signed-in person, not an API/service key and not an agent account
 *                        — what any "a person approves this" gate should require.
 */

/**
 * The pure rule, so it can be tested without a DB.
 *   $ctx = ['user_id' => int, 'service_key' => bool, 'row' => ['is_owner'=>…, 'is_agent'=>…] | null]
 * $need 'human' or 'owner'.
 */
function wn_session_gate_decision(array $ctx, string $need = 'owner'): bool {
    if ((int) ($ctx['user_id'] ?? 0) <= 0) { return false; }
    if (!empty($ctx['service_key'])) { return false; }      // API-key request: never a person
    $row = $ctx['row'] ?? null;
    if (!is_array($row)) { return false; }
    if (!empty($row['is_agent'])) { return false; }        // agent accounts never pass
    if ($need === 'human') { return true; }
    return (int) ($row['is_owner'] ?? 0) === 1;
}

/** The current request's context: session user, service key, the user's row from the DB. */
function wn_session_gate_context(): array {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $ctx = ['user_id' => $uid, 'service_key' => !empty($GLOBALS['_SERVICE_API_KEY']), 'row' => null];
    global $db;
    if ($uid <= 0 || $ctx['service_key'] || !($db instanceof PDO)) { return $ctx; }
    try {
        $st = $db->prepare("SELECT is_owner, is_agent FROM user WHERE user_id = ?");
        $st->execute([$uid]);
        $ctx['row'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('wn_session_gate_context: ' . $e->getMessage());   // fail closed
    }
    return $ctx;
}

function wn_is_owner_session(): bool {
    return wn_session_gate_decision(wn_session_gate_context(), 'owner');
}

function wn_is_human_session(): bool {
    return wn_session_gate_decision(wn_session_gate_context(), 'human');
}

<?php
/**
 * owner-gate-test.php — include/common/ownerFunctions.php without a DB or web server (task #2779).
 * php tests/owner-gate-test.php   (exit 0 = all passed)
 *
 * The point: an API-key (service key) request, or an agent account, never passes the owner
 * gate or a human-approval gate, whatever its session says.
 */
require __DIR__ . '/../include/common/ownerFunctions.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
}
$owner = ['is_owner' => 1, 'is_agent' => 0];
$admin = ['is_owner' => 0, 'is_agent' => 0];
$agent = ['is_owner' => 1, 'is_agent' => 1];   // even if someone set both

check('owner, signed in',                 wn_session_gate_decision(['user_id' => 2, 'row' => $owner]), true);
check('owner, but via service key',       wn_session_gate_decision(['user_id' => 2, 'service_key' => true, 'row' => $owner]), false);
check('admin is not owner',               wn_session_gate_decision(['user_id' => 3, 'row' => $admin]), false);
check('agent flagged owner -> refused',    wn_session_gate_decision(['user_id' => 4, 'row' => $agent]), false);
check('no session user',                  wn_session_gate_decision(['user_id' => 0, 'row' => $owner]), false);
check('user row missing (fail closed)',   wn_session_gate_decision(['user_id' => 2, 'row' => null]), false);
check('human: admin signed in',           wn_session_gate_decision(['user_id' => 3, 'row' => $admin], 'human'), true);
check('human: service key',               wn_session_gate_decision(['user_id' => 3, 'service_key' => true, 'row' => $admin], 'human'), false);
check('human: agent account',             wn_session_gate_decision(['user_id' => 4, 'row' => ['is_owner' => 0, 'is_agent' => 1]], 'human'), false);

// The live entry points read the request: a Bearer service key wins over any session.
$_SESSION = ['user_id' => 2, 'is_owner' => 1, 'is_admin' => 1];
$GLOBALS['_SERVICE_API_KEY'] = ['key_id' => 1, 'scopes' => ['*']];
check('wn_is_owner_session with service key', wn_is_owner_session(), false);
check('wn_is_human_session with service key', wn_is_human_session(), false);
$GLOBALS['_SERVICE_API_KEY'] = null;
check('no DB -> fail closed',                 wn_is_owner_session(), false);

echo $fail ? "$fail FAILED\n" : "all passed\n";
exit($fail ? 1 : 0);

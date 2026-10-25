<?php
/**
 * proxy-functions-test.php — include/common/proxyFunctions.php without a DB or web server.
 * php tests/proxy-functions-test.php   (exit 0 = all passed)
 */
function valid_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
require __DIR__ . '/../include/common/proxyFunctions.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  got ' . var_export($got, true) . ' want ' . var_export($want, true)) . "\n";
}
function request(array $server, array $config = []) {
    foreach (['trusted_proxies', 'proxy_auth_user_header', 'proxy_auth_email_header', 'proxy_auth_required'] as $k) { unset($GLOBALS[$k]); }
    foreach ($config as $k => $v) { $GLOBALS[$k] = $v; }
    $_SERVER = $server;
    wn_proxy_normalize_request();
}

// CIDR matching
check('v4 in /24',        wn_ip_in_cidr('10.159.250.43', '10.159.250.0/24'), true);
check('v4 outside /24',   wn_ip_in_cidr('10.159.251.43', '10.159.250.0/24'), false);
check('v4 /20 boundary',  wn_ip_in_cidr('172.28.127.255', '172.28.112.0/20'), true);
check('v4 bare address',  wn_ip_in_cidr('10.0.0.1', '10.0.0.1'), true);
check('v6 in /64',        wn_ip_in_cidr('2607:4000::5', '2607:4000::/64'), true);
check('v4 vs v6 list',    wn_ip_in_cidr('10.0.0.1', '::1/128'), false);
check('garbage',          wn_ip_in_cidr('nope', '10.0.0.0/8'), false);

$fwd = ['REMOTE_ADDR' => '10.1.1.5', 'HTTP_HOST' => 'backend:8444', 'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'tru.rit.uw.edu', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 128.95.1.2', 'HTTP_X_FORWARDED_PORT' => '443'];

// Off by default: headers ignored
request($fwd);
check('off: REMOTE_ADDR kept',  $_SERVER['REMOTE_ADDR'], '10.1.1.5');
check('off: no HTTPS',          isset($_SERVER['HTTPS']), false);
check('off: host kept',         $_SERVER['HTTP_HOST'], 'backend:8444');

// From a trusted proxy: applied; the visitor is the right-most untrusted hop, not the spoofable left one
request($fwd, ['trusted_proxies' => '10.1.1.0/24']);
check('trusted: HTTPS on',      $_SERVER['HTTPS'] ?? null, 'on');
check('trusted: host',          $_SERVER['HTTP_HOST'], 'tru.rit.uw.edu');
check('trusted: port',          $_SERVER['SERVER_PORT'], '443');
check('trusted: visitor IP',    $_SERVER['REMOTE_ADDR'], '128.95.1.2');
check('trusted: peer kept',     $_SERVER['WN_PEER_ADDR'], '10.1.1.5');
check('trusted: via proxy',     $_SERVER['WN_VIA_PROXY'], true);

// Two proxies in the chain, both trusted
request(['REMOTE_ADDR' => '10.1.1.5', 'HTTP_X_FORWARDED_FOR' => '128.95.1.2, 10.1.1.9'], ['trusted_proxies' => ['10.1.1.0/24']]);
check('chain: skips inner proxy', $_SERVER['REMOTE_ADDR'], '128.95.1.2');

// Untrusted peer sending the same headers
request($fwd, ['trusted_proxies' => '10.2.0.0/16']);
check('untrusted: IP kept',     $_SERVER['REMOTE_ADDR'], '10.1.1.5');
check('untrusted: no HTTPS',    isset($_SERVER['HTTPS']), false);

// Hostile host header from a trusted proxy is not applied
request(['REMOTE_ADDR' => '10.1.1.5', 'HTTP_HOST' => 'a', 'HTTP_X_FORWARDED_HOST' => 'evil.com/<x>'], ['trusted_proxies' => '10.1.1.5']);
check('bad forwarded host ignored', $_SERVER['HTTP_HOST'], 'a');

// CLI / cron: nothing to do
request([], ['trusted_proxies' => '10.1.1.0/24']);
check('cli untouched', isset($_SERVER['WN_PEER_ADDR']), false);

// ── Identity ──
$shib = ['REMOTE_ADDR' => '10.1.1.5', 'HTTP_EDUPERSONPRINCIPALNAME' => 'pat@uw.edu;pat2@uw.edu'];

request($shib, ['trusted_proxies' => '10.1.1.0/24', 'proxy_auth_user_header' => 'EduPersonPrincipalName']);
$who = wn_proxy_auth_identity();
check('header from proxy: id (first of multi)', $who['id'] ?? null, 'pat@uw.edu');
check('eppn doubles as email', $who['email'] ?? null, 'pat@uw.edu');

request($shib, ['proxy_auth_user_header' => 'EduPersonPrincipalName']);
check('header from non-proxy: refused', wn_proxy_auth_identity(), null);

request(['REMOTE_ADDR' => '127.0.0.1', 'eppn' => 'local@uw.edu'], ['proxy_auth_user_header' => 'eppn']);
check('same-box SP env var',   wn_proxy_auth_identity()['id'] ?? null, 'local@uw.edu');
request(['REMOTE_ADDR' => '127.0.0.1', 'REDIRECT_eppn' => 'r@uw.edu'], ['proxy_auth_user_header' => 'eppn']);
check('REDIRECT_ env var',     wn_proxy_auth_identity()['id'] ?? null, 'r@uw.edu');
request(['REMOTE_ADDR' => '10.1.1.5', 'HTTP_EPPN' => '(null)'], ['trusted_proxies' => '10.1.1.5', 'proxy_auth_user_header' => 'eppn']);
check('(null) = no identity',  wn_proxy_auth_identity(), null);

request([], []);
check('disabled: not required', wn_proxy_auth_required(), false);
request([], ['proxy_auth_user_header' => 'eppn']);
check('enabled: required by default', wn_proxy_auth_required(), true);
request([], ['proxy_auth_user_header' => 'eppn', 'proxy_auth_required' => '0']);
check('required can be turned off', wn_proxy_auth_required(), false);

// Session made by someone else
request($shib, ['trusted_proxies' => '10.1.1.0/24', 'proxy_auth_user_header' => 'EduPersonPrincipalName']);
$_SESSION = ['user_id' => 7, 'proxy_auth_id' => 'other@uw.edu'];
check('mismatch detected', wn_proxy_auth_session_mismatch(), true);
$_SESSION['proxy_auth_id'] = 'pat@uw.edu';
check('same person ok',    wn_proxy_auth_session_mismatch(), false);
$_SESSION = ['user_id' => 7];
check('password session untouched', wn_proxy_auth_session_mismatch(), false);

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);

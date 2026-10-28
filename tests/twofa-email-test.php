<?php
/**
 * twofa-email-test.php — the email last-resort second factor (task #2660) without a DB or
 * web server: include/common/twofaEmailFunctions.php against an in-memory twofa_email_code,
 * twofa_enrol_blocker(), and the disable2FA action.
 * php tests/twofa-email-test.php   (exit 0 = all passed)
 *
 * Covers the API rules #2658 asks to be tested: email fallback rate limits; a sign-in that
 * started with the emailed sign-in code cannot finish with an email code; an unverified email
 * gets no code and cannot enable 2FA; removing the last factor needs the password.
 */
define('SQL', 'sql');
define('WN_2FA_EMAIL', true);
$_SESSION = [];
$_SERVER  = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'test'];

// ── in-memory twofa_email_code ───────────────────────────────────────────────
$ROWS = []; $NEXT = 1; $SENT = []; $LOGINS = []; $USERS = [];
class FakeStmt { public $rows; public $n; function __construct($rows, $n = 0) { $this->rows = $rows; $this->n = $n; } function rowCount() { return $this->n; } }
function sanitize($v, $t = null) { return addslashes((string)$v); }
function db_fetch($st) { return $st && $st->rows ? array_shift($st->rows) : null; }
function db_query($sql) {
    global $ROWS, $NEXT, $USERS;
    $sql = preg_replace('/\s+/', ' ', $sql);
    if (preg_match("/SELECT COUNT\(\*\) AS n FROM twofa_email_code WHERE user_id = '(\d+)'/", $sql, $m)) {
        return new FakeStmt([['n' => count(array_filter($ROWS, function ($r) use ($m) { return $r['user_id'] == $m[1]; }))]]);
    }
    if (preg_match("/SELECT COUNT\(\*\) AS n FROM twofa_email_code WHERE ip_address = '([^']*)'/", $sql, $m)) {
        return new FakeStmt([['n' => count(array_filter($ROWS, function ($r) use ($m) { return $r['ip_address'] === $m[1]; }))]]);
    }
    if (preg_match("/UPDATE twofa_email_code SET used = 1 WHERE user_id = '(\d+)' AND used = 0/", $sql, $m)) {
        foreach ($ROWS as &$r) { if ($r['user_id'] == $m[1]) $r['used'] = 1; } unset($r); return new FakeStmt([]);
    }
    if (preg_match("/INSERT INTO twofa_email_code .* VALUES \('(\d+)', '(.*?)', '([^']*)', '([^']*)'/", $sql, $m)) {
        $ROWS[$NEXT] = ['twofa_email_code_id' => $NEXT, 'user_id' => (int)$m[1], 'code_hash' => stripslashes($m[2]), 'ip_address' => $m[3], 'attempts' => 0, 'used' => 0];
        $NEXT++; return new FakeStmt([]);
    }
    if (preg_match("/SELECT \* FROM twofa_email_code WHERE user_id = '(\d+)' AND used = 0/", $sql, $m)) {
        $live = array_values(array_filter($ROWS, function ($r) use ($m) { return $r['user_id'] == $m[1] && !$r['used']; }));
        return new FakeStmt($live ? [end($live)] : []);
    }
    if (preg_match("/SET attempts = attempts \+ 1 WHERE twofa_email_code_id = '(\d+)'/", $sql, $m)) { $ROWS[$m[1]]['attempts']++; return new FakeStmt([]); }
    if (preg_match("/SET used = 1 WHERE twofa_email_code_id = '(\d+)' AND used = 0/", $sql, $m)) {
        if ($ROWS[$m[1]]['used']) return new FakeStmt([], 0);
        $ROWS[$m[1]]['used'] = 1; return new FakeStmt([], 1);
    }
    if (preg_match("/UPDATE user SET totp_enabled = 0/", $sql)) { $GLOBALS['DISABLED'] = true; return new FakeStmt([]); }
    throw new Exception('unexpected SQL: ' . $sql);
}
function send_email_now($to, $n, $subject, $body, $o = []) { global $SENT; $SENT[] = ['to' => $to, 'subject' => $subject, 'body' => $body]; return true; }
function record_login($uid, $method, $status) { global $LOGINS; $LOGINS[] = "$uid:$method:$status"; }
function get_user($id) { global $USERS; return $USERS[(int)$id] ?? null; }
function verify_password($p, $h) { return $p === $h; }

require __DIR__ . '/../include/common/twofaEmailFunctions.php';
require __DIR__ . '/../include/common/totpFunctions.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  got ' . var_export($got, true) . ' want ' . var_export($want, true)) . "\n";
}
function code_in_last_mail() { global $SENT; $m = end($SENT); return preg_match('/^(\d{6}) /', $m['subject'], $x) ? $x[1] : ''; }

$USERS = [
    1 => ['user_id' => 1, 'email' => 'twofa-a@nokemo.com', 'is_confirmed' => 1, 'shard_id' => 's1', 'password' => 'pw', 'totp_enabled' => 1],
    2 => ['user_id' => 2, 'email' => 'twofa-b@nokemo.com', 'is_confirmed' => 0, 'shard_id' => 's1', 'password' => 'pw', 'totp_enabled' => 0],
    3 => ['user_id' => 3, 'email' => 'twofa-c@nokemo.com', 'is_confirmed' => 1, 'shard_id' => 's1', 'password' => 'pw', 'totp_enabled' => 1],
];

// The rule: only after a password (or a passkey).
check('password first step: allowed',           wn_2fa_email_refusal($USERS[1], 'password'), '');
check('passkey first step: allowed',            wn_2fa_email_refusal($USERS[1], 'passkey'), '');
check('email sign-in code + email 2FA refused', (bool) preg_match('/code from your email/', wn_2fa_email_refusal($USERS[1], 'email_code')), true);
check('Google sign-in: refused',                wn_2fa_email_refusal($USERS[1], 'oauth_google') !== '', true);
check('no first step recorded: refused',        wn_2fa_email_refusal($USERS[1], '') !== '', true);
check('unverified email: refused',              (bool) preg_match('/not verified/', wn_2fa_email_refusal($USERS[2], 'password')), true);

// Refused requests never send and never write a code.
$n = count($SENT);
check('email_code request refused',             wn_2fa_email_request($USERS[1], 'email_code') !== '', true);
check('unverified request refused',             wn_2fa_email_request($USERS[2], 'password') !== '', true);
check('…and nothing was sent',                  count($SENT), $n);
check('…and no code row exists',                count($ROWS), 0);

// The happy path: send, verify once, spent.
check('send to verified email',                 wn_2fa_email_request($USERS[1], 'password'), '');
check('sent to the account address',            end($SENT)['to'], 'twofa-a@nokemo.com');
$code = code_in_last_mail();
check('stored hashed, never plain',             strpos($ROWS[1]['code_hash'], $code) === false && password_verify($code, $ROWS[1]['code_hash']), true);
$wrong = $code === '000000' ? '111111' : '000000';
check('wrong code refused',                     isset(wn_2fa_email_verify(1, $wrong)['error']), true);
check('wrong try recorded in login history',    end($LOGINS), '1:2fa_email:failed');
check('right code accepted',                    wn_2fa_email_verify(1, $code), ['ok' => true]);
check('code is single use',                     isset(wn_2fa_email_verify(1, $code)['error']), true);
check('another user cannot use it',             isset(wn_2fa_email_verify(3, $code)['error']), true);

// A newer code retires the older one.
wn_2fa_email_request($USERS[1], 'password'); $old = code_in_last_mail();
wn_2fa_email_request($USERS[1], 'password'); $new = code_in_last_mail();
check('older code retired by a newer one',      $old === $new ? true : isset(wn_2fa_email_verify(1, $old)['error']), true);

// Tries per code.
$ROWS = []; wn_2fa_email_request($USERS[3], 'password'); $c3 = code_in_last_mail();
$w3 = $c3 === '000000' ? '111111' : '000000';
for ($i = 0; $i < WN_2FA_EMAIL_MAX_TRIES; $i++) wn_2fa_email_verify(3, $w3);
check('after ' . WN_2FA_EMAIL_MAX_TRIES . ' wrong tries, the right code is dead', isset(wn_2fa_email_verify(3, $c3)['error']), true);

// Rate limit per account.
$ROWS = [];
for ($i = 0; $i < WN_2FA_EMAIL_PER_USER_HR; $i++) wn_2fa_email_request($USERS[1], 'password');
check('rate limit per account',                 (bool) preg_match('/Too many/', wn_2fa_email_request($USERS[1], 'password')), true);
// Rate limit per IP, across accounts.
$ROWS = [];
for ($i = 0; $i < WN_2FA_EMAIL_PER_IP_HR; $i++) { $ROWS[] = ['twofa_email_code_id' => 1000 + $i, 'user_id' => 900 + $i, 'code_hash' => 'x', 'ip_address' => '203.0.113.9', 'attempts' => 0, 'used' => 1]; }
check('rate limit per IP',                      (bool) preg_match('/Too many/', wn_2fa_email_request($USERS[3], 'password')), true);

// Notice after use: an email that names what it replaced.
$n = count($SENT);
wn_2fa_email_used_notice($USERS[1]);
check('notice email sent',                      count($SENT), $n + 1);
check('notice names the factor',                (bool) preg_match('/instead of your authenticator app/', end($SENT)['body']), true);

// Off unless the app opts in: a separate process, since the constant is set here.
$off = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('define("SQL",1); require "' . __DIR__ . '/../include/common/twofaEmailFunctions.php"; echo wn_2fa_email_enabled() ? "on" : "off";'));
check('off unless the app defines WN_2FA_EMAIL', $off, 'off');

// Unverified email cannot enable 2FA (#2658's twofa_enrol_blocker).
check('unverified email cannot enable 2FA',     twofa_enrol_blocker(2) !== '', true);
check('verified email can enable 2FA',          twofa_enrol_blocker(1), '');

// Removing the last factor needs re-authentication (disable2FA asks for the password).
foreach ([['', false], ['nope', false], ['pw', true]] as [$pw, $want]) {
    $GLOBALS['DISABLED'] = false; $_SESSION = ['user_id' => 1, 'error' => null]; $_POST = ['password' => $pw]; $action = 'disable2FA';
    include __DIR__ . '/../include/actions/memberActions/twoFactorActions.php';
    check('disable 2FA with password "' . $pw . '"', $GLOBALS['DISABLED'], $want);
}

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);

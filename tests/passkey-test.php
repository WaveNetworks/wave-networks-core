<?php
/**
 * passkey-test.php — passkeys (task #2661) without a DB, web server or browser:
 * include/common/passkeyFunctions.php against an in-memory user_passkey, with a SOFTWARE
 * AUTHENTICATOR (P-256, 'none' attestation) standing in for Face ID / fingerprint, so the real
 * lbuchs/webauthn verification runs on real signatures.
 * php tests/passkey-test.php   (exit 0 = all passed; needs composer install)
 *
 * Covers: enrol only with a verified email and only after the device proved it; UV is required
 * (a touch without face/fingerprint/PIN is refused); a replayed or foreign challenge is refused;
 * password-less sign-in finds the account from the passkey; the second step only accepts THAT
 * account's passkey; rename/remove are per-owner; add + remove send a notice.
 */
define('SQL', 'sql');
define('WN_PASSKEY', true);
require __DIR__ . '/../vendor/autoload.php';
$_SESSION = [];
$_SERVER  = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)', 'HTTP_HOST' => 'contactsweep.com'];

// ── in-memory user_passkey ───────────────────────────────────────────────────
$PK = []; $NEXT = 1; $SENT = []; $NOTES = []; $LOGINS = []; $USERS = [];
class FakeStmt { public $rows; public $n; function __construct($rows, $n = 0) { $this->rows = $rows; $this->n = $n; } function rowCount() { return $this->n; } }
function sanitize($v, $t = null) { return addslashes((string)$v); }
function db_fetch($st) { return $st && $st->rows ? array_shift($st->rows) : null; }
function db_insert_id() { global $NEXT; return $NEXT - 1; }
function pk_where($uid = null, $cred = null, $pid = null) {
    global $PK;
    return array_values(array_filter($PK, function ($r) use ($uid, $cred, $pid) {
        return ($uid === null || $r['user_id'] == $uid) && ($cred === null || $r['credential_id'] === $cred) && ($pid === null || $r['passkey_id'] == $pid);
    }));
}
function db_query($sql) {
    global $PK, $NEXT;
    $raw = $sql;   // the PEM's newlines must survive
    $sql = preg_replace('/\s+/', ' ', $sql);
    if (preg_match("/SELECT COUNT\(\*\) AS n FROM user_passkey WHERE user_id = '(\d+)'/", $sql, $m)) { return new FakeStmt([['n' => count(pk_where($m[1]))]]); }
    if (preg_match("/SELECT credential_id FROM user_passkey WHERE user_id = '(\d+)'/", $sql, $m)) { return new FakeStmt(pk_where($m[1])); }
    if (preg_match("/SELECT passkey_id FROM user_passkey WHERE credential_id = '([^']*)'/", $sql, $m)) { return new FakeStmt(pk_where(null, $m[1])); }
    if (preg_match("/SELECT \* FROM user_passkey WHERE credential_id = '([^']*)'/", $sql, $m)) { return new FakeStmt(pk_where(null, $m[1])); }
    if (preg_match("/SELECT passkey_id, name, .* WHERE user_id = '(\d+)'/", $sql, $m)) { return new FakeStmt(array_reverse(pk_where($m[1]))); }
    if (preg_match("/SELECT name FROM user_passkey WHERE passkey_id = '(\d+)' AND user_id = '(\d+)'/", $sql, $m)) { return new FakeStmt(pk_where($m[2], null, $m[1])); }
    if (preg_match("/INSERT INTO user_passkey .*? VALUES \('(\d+)', '([^']*)', '([^']*)', '(.*?)', '(\d+)', '([^']*)', (NULL|'[^']*'), '(\d)'/s", $raw, $m)) {
        $PK[$NEXT] = ['passkey_id' => $NEXT, 'user_id' => (int)$m[1], 'credential_id' => $m[2], 'user_handle' => $m[3], 'public_key' => stripslashes($m[4]),
                      'sign_count' => (int)$m[5], 'name' => stripslashes($m[6]), 'aaguid' => trim($m[7], "'"), 'backed_up' => (int)$m[8], 'created' => '2026-10-28 00:00:00', 'last_used' => null];
        $NEXT++; return new FakeStmt([], 1);
    }
    if (preg_match("/UPDATE user_passkey SET last_used = NOW\(\)(?:, sign_count = '(\d+)')? WHERE passkey_id = '(\d+)'/", $sql, $m)) {
        $PK[$m[2]]['last_used'] = 'now'; if ($m[1] !== '') { $PK[$m[2]]['sign_count'] = (int)$m[1]; } return new FakeStmt([], 1);
    }
    if (preg_match("/UPDATE user_passkey SET name = '([^']*)' WHERE passkey_id = '(\d+)' AND user_id = '(\d+)'/", $sql, $m)) {
        if (!pk_where($m[3], null, $m[2])) { return new FakeStmt([], 0); }
        $PK[$m[2]]['name'] = stripslashes($m[1]); return new FakeStmt([], 1);
    }
    if (preg_match("/DELETE FROM user_passkey WHERE passkey_id = '(\d+)' AND user_id = '(\d+)'/", $sql, $m)) {
        if (pk_where($m[2], null, $m[1])) { unset($PK[$m[1]]); } return new FakeStmt([], 1);
    }
    throw new Exception('unexpected SQL: ' . $sql);
}
function send_email_now($to, $n, $subject, $body, $o = []) { global $SENT; $SENT[] = $subject; return true; }
function send_notification($uid, $shard, $cat, $title, $body, $o = []) { global $NOTES; $NOTES[] = "$uid:$title"; }
function record_login($uid, $method, $status) { global $LOGINS; $LOGINS[] = "$uid:$method:$status"; }
function get_user($id) { global $USERS; return $USERS[(int)$id] ?? null; }
function get_branding() { return ['site_name' => 'ContactSwipe']; }

require __DIR__ . '/../include/common/totpFunctions.php';
require __DIR__ . '/../include/common/passkeyFunctions.php';

$fail = 0;
function check($label, $ok, $detail = '') {
    global $fail;
    if (!$ok) { $fail++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : '  (' . $detail . ')') . "\n";
}

// ── a software authenticator ─────────────────────────────────────────────────
function cbor_head($major, $n) {
    if ($n < 24) return chr(($major << 5) | $n);
    if ($n < 256) return chr(($major << 5) | 24) . chr($n);
    return chr(($major << 5) | 25) . pack('n', $n);
}
function cbor($v) {
    if (is_int($v)) { return $v >= 0 ? cbor_head(0, $v) : cbor_head(1, -1 - $v); }
    if (is_array($v) && isset($v['__bytes'])) { return cbor_head(2, strlen($v['__bytes'])) . $v['__bytes']; }
    if (is_string($v)) { return cbor_head(3, strlen($v)) . $v; }
    if (is_array($v)) { $o = cbor_head(5, count($v)); foreach ($v as $k => $x) { $o .= cbor(is_int($k) ? $k : (string)$k) . cbor($x); } return $o; }
    throw new Exception('cbor');
}
class SoftKey {
    public $key; public $cred; public $handle; public $count = 0; public $origin;
    function __construct($origin = 'https://contactsweep.com') {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->cred = random_bytes(32); $this->origin = $origin;
    }
    function create($options, $flags = 0x45) {   // UP|UV|AT
        $pk = $options->publicKey;
        $this->handle = wn_b64url_decode(json_decode(json_encode($pk->user->id)));
        $d = openssl_pkey_get_details($this->key)['ec'];
        $cose = cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['__bytes' => $d['x']], -3 => ['__bytes' => $d['y']]]);
        $auth = hash('sha256', $pk->rp->id, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($this->cred)) . $this->cred . $cose;
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => json_decode(json_encode($pk->challenge)), 'origin' => $this->origin]);
        $att = cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__bytes' => $auth]]);
        // empty attStmt must be a map, not an array: patch the CBOR (0x80 empty array -> 0xa0 empty map)
        $att = str_replace(cbor('attStmt') . "\x80", cbor('attStmt') . "\xa0", $att);
        return [wn_b64url_encode($client), wn_b64url_encode($att)];
    }
    function get($options, $flags = 0x05, $challenge = null) {
        $pk = $options->publicKey;
        $this->count++;
        $auth = hash('sha256', $pk->rpId, true) . chr($flags) . pack('N', $this->count);
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge ?? json_decode(json_encode($pk->challenge)), 'origin' => $this->origin]);
        openssl_sign($auth . hash('sha256', $client, true), $sig, $this->key, OPENSSL_ALGO_SHA256);
        return ['credential_id' => wn_b64url_encode($this->cred), 'client_data' => wn_b64url_encode($client),
                'authenticator_data' => wn_b64url_encode($auth), 'signature' => wn_b64url_encode($sig), 'user_handle' => wn_b64url_encode($this->handle)];
    }
}

$USERS = [
    1 => ['user_id' => 1, 'email' => 'passkey-a@nokemo.com', 'is_confirmed' => 1, 'shard_id' => 's1', 'first_name' => 'Pat', 'last_name' => 'Key'],
    2 => ['user_id' => 2, 'email' => 'passkey-b@nokemo.com', 'is_confirmed' => 0, 'shard_id' => 's1'],
    3 => ['user_id' => 3, 'email' => 'passkey-c@nokemo.com', 'is_confirmed' => 1, 'shard_id' => 's1'],
];

// Enrol: an unverified email gets no options.
$r = wn_passkey_enrol_options($USERS[2]);
check('unverified email cannot enrol', !empty($r['error']) && strpos($r['error'], 'Verify your email') === 0);

// Options ask for a discoverable, user-verified, platform passkey.
$r = wn_passkey_enrol_options($USERS[1]);
$o = json_decode(json_encode($r['options']), true)['publicKey'];
check('options: UV required', ($o['authenticatorSelection']['userVerification'] ?? '') === 'required');
check('options: resident key required', ($o['authenticatorSelection']['residentKey'] ?? '') === 'required');
check('options: platform authenticator', ($o['authenticatorSelection']['authenticatorAttachment'] ?? '') === 'platform');
check('options: rp id is the host', $o['rp']['id'] === 'contactsweep.com');
check('options: user handle is not the user id', wn_b64url_decode($o['user']['id']) !== '1' && strlen(wn_b64url_decode($o['user']['id'])) === 16);

// A device that did not verify the person (UP only) is refused, nothing saved.
$a = new SoftKey();
[$c, $att] = $a->create($r['options'], 0x41);
$f = wn_passkey_enrol_finish($USERS[1], $c, $att, 'No UV');
check('enrol without user verification refused', !empty($f['error']) && !$PK, $f['error'] ?? '');

// Wrong origin refused.
$r = wn_passkey_enrol_options($USERS[1]);
$evil = new SoftKey('https://contactsweep.com.evil.test');
[$c, $att] = $evil->create($r['options']);
$f = wn_passkey_enrol_finish($USERS[1], $c, $att, 'Evil');
check('enrol from another origin refused', !empty($f['error']) && !$PK, $f['error'] ?? '');

// The real thing.
$r = wn_passkey_enrol_options($USERS[1]);
$a = new SoftKey();
[$c, $att] = $a->create($r['options']);
$SENT = []; $NOTES = [];
$f = wn_passkey_enrol_finish($USERS[1], $c, $att, '  My <b>iPhone</b> ');
check('enrol saves after proof', !empty($f['ok']) && count($PK) === 1, json_encode($f));
$row = reset($PK);
check('saved name is cleaned', ($row['name'] ?? '') === 'My iPhone', $row['name'] ?? '');
check('saved public key is PEM', strpos($row['public_key'] ?? '', 'BEGIN PUBLIC KEY') !== false);
check('enrol sends a notice email + notification', count($SENT) === 1 && count($NOTES) === 1);
check('enrol challenge is single use', !empty(wn_passkey_enrol_finish($USERS[1], $c, $att, 'again')['error']));

// Password-less sign-in.
$opts = wn_passkey_signin_options(null);
$ans = $a->get($opts);
$v = wn_passkey_signin_verify($ans, 0);
check('password-less sign-in finds the account', ($v['user_id'] ?? 0) === 1, json_encode($v));
check('sign count stored', reset($PK)['sign_count'] === 1);
check('sign-in challenge is single use', !empty(wn_passkey_signin_verify($ans, 0)['error']));

// UV required at sign-in.
$opts = wn_passkey_signin_options(null);
$v = wn_passkey_signin_verify($a->get($opts, 0x01), 0);
check('sign-in without user verification refused', !empty($v['error']));

// A stale challenge (another page's) is refused.
$opts = wn_passkey_signin_options(null);
$old = $a->get($opts);
$opts2 = wn_passkey_signin_options(null);
$v = wn_passkey_signin_verify($old, 0);
check('answer to an older challenge refused', !empty($v['error']));

// Second step: only the account's own passkey.
check('no passkey, no second-step option', wn_passkey_signin_options($USERS[3]) === null);
$opts = wn_passkey_signin_options($USERS[1]);
$allow = json_decode(json_encode($opts), true)['publicKey']['allowCredentials'] ?? [];
check('second step lists the account\'s passkey', count($allow) === 1);
$v = wn_passkey_signin_verify($a->get($opts), 1);
check('second step with own passkey', ($v['user_id'] ?? 0) === 1, json_encode($v));
$_SESSION['wn_pk_signin'] = ['challenge' => wn_b64url_encode('x'), 'user_id' => 3, 'at' => time()];
$opts = (object)['publicKey' => (object)['rpId' => 'contactsweep.com', 'challenge' => wn_b64url_encode('x')]];
$v = wn_passkey_signin_verify($a->get($opts), 3);
check('second step for another account refuses this passkey', !empty($v['error']));

// Rename / remove: owner only.
$pid = (int)reset($PK)['passkey_id'];
check('another user cannot rename', !wn_passkey_rename(3, $pid, 'Mine'));
check('owner renames', wn_passkey_rename(1, $pid, 'Work phone') && reset($PK)['name'] === 'Work phone');
check('another user cannot remove', wn_passkey_remove(3, $pid) === '' && count($PK) === 1);
$SENT = [];
check('owner removes', wn_passkey_remove(1, $pid) === 'Work phone' && !$PK);
check('remove sends a notice', count($SENT) === 1);
$opts = wn_passkey_signin_options(null);
$v = wn_passkey_signin_verify($a->get($opts), 0);
check('removed passkey no longer signs in', !empty($v['error']));

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);

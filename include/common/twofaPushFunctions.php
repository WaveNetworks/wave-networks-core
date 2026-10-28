<?php
/**
 * twofaPushFunctions.php
 * "Approve on your phone" as the second step after a password (task #2662).
 *
 * auth/2fa.php offers it when the account has a phone with push. Starting it writes a
 * login_approval row (kind 'push', main/7.0) already tied to the account, shows the
 * browser a 2-digit number and pushes "Signing in on <browser>, <place>?" to the
 * account's phones. The phone shows three numbers; only the right one approves. A
 * wrong number or "Not me" denies it, and the account gets a security notice either
 * way. The app also asks for a pending request whenever it opens (twofaPushPending),
 * so a missed or blocked push still gets answered.
 *
 * Same guarantees as the QR flow (loginApproveFunctions.php): random token and cookie
 * nonce stored only as sha256, two minutes, single use, only the browser holding the
 * nonce collects, rate limited per IP. The phone's answer counts only from a session
 * signed in as the same account.
 *
 * OPT-IN PER APP: define WN_2FA_PUSH (with WN_LOGIN_APPROVE) in the app's
 * include/common_auth.php, common_api.php and common.php, include
 * snippets/twofa_push.php on its auth/2fa.php, and answer it in the app.
 */

const WN_2FA_PUSH_COOKIE = 'wn_2fa_push';

function wn_2fa_push_enabled() {
    return defined('WN_2FA_PUSH') && WN_2FA_PUSH && function_exists('wn_login_approve_enabled');
}

/** True when the account has at least one phone that can receive the push. */
function wn_2fa_push_available($user) {
    if (!wn_2fa_push_enabled() || empty($user['user_id']) || empty($user['shard_id'])) { return false; }
    $uid = (int)$user['user_id'];
    prime_shard($user['shard_id']);
    $r = db_fetch(db_query_shard($user['shard_id'],
        "SELECT COUNT(*) AS n FROM push_subscription WHERE user_id = '$uid' AND endpoint LIKE 'native:%' AND approve_signin = 1"));
    return (int)($r['n'] ?? 0) > 0;
}

/** The account's phones (native push rows) for Sign-in & security: id, label, dates, approve_signin. */
function wn_2fa_push_phones($user_id, $shard_id) {
    if (!wn_2fa_push_enabled() || !$shard_id) { return []; }
    $uid = (int)$user_id;
    prime_shard($shard_id);
    $rows = db_fetch_all(db_query_shard($shard_id,
        "SELECT subscription_id, user_agent, created, last_used, approve_signin FROM push_subscription
         WHERE user_id = '$uid' AND endpoint LIKE 'native:%' ORDER BY COALESCE(last_used, created) DESC LIMIT 20")) ?: [];
    foreach ($rows as &$r) {
        $ua = (string)$r['user_agent'];
        $r['label'] = stripos($ua, 'iPhone') !== false ? 'iPhone' : (stripos($ua, 'iPad') !== false ? 'iPad'
                    : (stripos($ua, 'Android') !== false ? 'Android phone' : 'Phone'));
    }
    return $rows;
}

/** Revoke (0) or allow again (1) one phone approving sign-ins. Only the account's own row. */
function wn_2fa_push_set_phone($user_id, $shard_id, $subscription_id, $on) {
    $uid = (int)$user_id; $sid = (int)$subscription_id; $v = $on ? 1 : 0;
    prime_shard($shard_id);
    $r = db_query_shard($shard_id, "UPDATE push_subscription SET approve_signin = '$v'
                                    WHERE subscription_id = '$sid' AND user_id = '$uid' AND endpoint LIKE 'native:%'");
    return $r && $r->rowCount() > 0;
}

/**
 * Bind a phone's device key (task #2662, shard/1.6). The app keeps a random key in the
 * Keychain/Keystore behind biometrics and sends it with its push token on the first approval;
 * only sha256 is stored, and only on that token's row when it has none yet (never overwritten,
 * so a stolen session cannot re-key a phone). True when bound now.
 */
function wn_2fa_push_bind_key($user_id, $shard_id, $push_token, $key) {
    if (strlen((string)$key) < 32 || strlen((string)$push_token) < 8) { return false; }
    $uid = (int)$user_id;
    $s_ep = sanitize('native:' . $push_token, SQL);
    $hash = hash('sha256', (string)$key);
    prime_shard($shard_id);
    $r = db_query_shard($shard_id, "UPDATE push_subscription SET device_key_hash = '$hash'
                                    WHERE user_id = '$uid' AND endpoint = '$s_ep' AND device_key_hash IS NULL");
    return $r && $r->rowCount() > 0;
}

/**
 * May this phone approve? Once any phone on the account holds a device key, only the key of a
 * phone still allowed (approve_signin = 1) counts. Before any key is bound (older app builds)
 * the session alone decides, as before. Returns '' or the refusal text.
 */
function wn_2fa_push_key_check($user_id, $shard_id, $key) {
    $uid = (int)$user_id;
    prime_shard($shard_id);
    $hash = $key !== '' ? hash('sha256', (string)$key) : '';
    $rows = db_fetch_all(db_query_shard($shard_id, "SELECT device_key_hash, approve_signin FROM push_subscription
        WHERE user_id = '$uid' AND endpoint LIKE 'native:%' AND device_key_hash IS NOT NULL")) ?: [];
    if (!$rows) { return ''; }
    foreach ($rows as $r) {
        if ($hash !== '' && hash_equals((string)$r['device_key_hash'], $hash)) {
            return (int)$r['approve_signin'] === 1 ? '' : 'This phone was removed from approving sign-ins. Allow it again on Sign-in & security, or use another way on the computer.';
        }
    }
    return 'This phone cannot approve sign-ins for this account. Use a phone listed on Sign-in & security, or use another way on the computer.';
}

/** Three numbers for the phone: the right one plus two others, stable for the row. */
function wn_2fa_push_choices($row) {
    $right = (int)$row['match_number'];
    $seed  = hexdec(substr((string)$row['token_hash'], 0, 8));
    $out   = [$right];
    for ($i = 0; count($out) < 3 && $i < 50; $i++) {
        $n = 10 + (($seed + $i * 37) % 90);
        if (!in_array($n, $out, true)) { $out[] = $n; }
    }
    // Where the right one sits also comes from the seed, so it is not always first.
    $pos = $seed % 3;
    if ($pos) { [$out[0], $out[$pos]] = [$out[$pos], $out[0]]; }
    return $out;
}

/** Start a push approval for the account waiting on 2fa.php. Returns ['number' => n] or ['error' => …]. */
function wn_2fa_push_start($user) {
    if (!wn_2fa_push_available($user)) { return ['error' => 'There is no phone with notifications on this account.']; }
    $ip   = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $s_ip = sanitize($ip, SQL);
    $n = db_fetch(db_query("SELECT COUNT(*) AS n FROM login_approval WHERE ip_address = '$s_ip' AND created > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"));
    if ((int)($n['n'] ?? 0) >= WN_LOGIN_APPROVE_PER_IP_10) {
        return ['error' => 'Too many sign-in requests from here. Please wait a few minutes.'];
    }

    $uid   = (int)$user['user_id'];
    // A new request replaces this account's earlier unanswered ones.
    db_query("UPDATE login_approval SET status = 'denied', deny_reason = 'replaced'
              WHERE user_id = '$uid' AND kind = 'push' AND status = 'pending'");

    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $nonce = bin2hex(random_bytes(32));
    $num   = random_int(10, 99);
    $s_t   = sanitize(hash('sha256', $token), SQL);
    $s_n   = sanitize(hash('sha256', $nonce), SQL);
    $s_ua  = sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512), SQL);
    $s_cc  = sanitize(preg_match('/^[A-Z]{2}$/', $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '') ? $_SERVER['HTTP_CF_IPCOUNTRY'] : '', SQL);
    $ttl   = (int)WN_LOGIN_APPROVE_TTL_SEC;
    db_query("INSERT INTO login_approval (kind, match_number, token_hash, nonce_hash, user_id, ip_address, user_agent, country, created, expires)
              VALUES ('push', '$num', '$s_t', '$s_n', '$uid', '$s_ip', '$s_ua', '$s_cc', NOW(), DATE_ADD(NOW(), INTERVAL $ttl SECOND))");
    $id = (int)db_insert_id();

    setcookie(WN_2FA_PUSH_COOKIE, $nonce, [
        'expires' => time() + $ttl + 60, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[WN_2FA_PUSH_COOKIE] = $nonce;
    $_SESSION['2fa_push_id'] = $id;

    $d     = wn_login_approve_describe(['user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'country' => $s_cc, 'ip_address' => $ip]);
    $where = $d['browser'] . ($d['location'] ? ', ' . $d['location'] : '');
    if (function_exists('send_push_to_user')) {
        send_push_to_user($uid, $user['shard_id'], 'Signing in on ' . $where . '?',
            'Tap to approve. Pick the number shown on the screen.',
            ['action_url' => '../app/?page=webapprove&push=' . $id, 'tag' => 'wn-2fa-push-' . $id, 'signin_approvers_only' => 1]);
    }
    return ['number' => $num, 'id' => $id];
}

/** This browser's push request for the account on 2fa.php (by its cookie nonce), or null. */
function wn_2fa_push_mine($user_id) {
    $nonce = $_COOKIE[WN_2FA_PUSH_COOKIE] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) { return null; }
    $s_n = sanitize(hash('sha256', $nonce), SQL);
    $uid = (int)$user_id;
    $row = db_fetch(db_query("SELECT *, (expires > NOW()) AS live FROM login_approval
                              WHERE nonce_hash = '$s_n' AND kind = 'push' AND user_id = '$uid'
                              ORDER BY login_approval_id DESC LIMIT 1"));
    return $row ?: null;
}

/** The newest live push request waiting for this account (what the app shows on open), or null. */
function wn_2fa_push_pending($user_id, $id = 0) {
    $uid = (int)$user_id;
    $and = $id ? " AND login_approval_id = '" . (int)$id . "'" : '';
    $row = db_fetch(db_query("SELECT * FROM login_approval
                              WHERE user_id = '$uid' AND kind = 'push' AND status = 'pending' AND expires > NOW()$and
                              ORDER BY login_approval_id DESC LIMIT 1"));
    return $row ?: null;
}

/** What the phone shows for a pending row. */
function wn_2fa_push_view($row) {
    return wn_login_approve_describe($row) + [
        'id'         => (int)$row['login_approval_id'],
        'choices'    => wn_2fa_push_choices($row),
        'expires_in' => max(0, strtotime($row['expires'] . ' UTC') - time()),
    ];
}

/**
 * The phone's answer: $decision 'approve' with the $number picked, or 'deny'.
 * $user_id is the app session's account. Returns ['status' => approved|denied] or ['error' => …].
 */
function wn_2fa_push_decide($id, $user_id, $decision, $number = 0) {
    $row = wn_2fa_push_pending($user_id, (int)$id);
    if (!$row) { return ['error' => 'This sign-in request has expired or was already answered.']; }
    $rid = (int)$row['login_approval_id'];

    if ($decision === 'approve' && (int)$number === (int)$row['match_number']) {
        $st = 'approved'; $why = '';
    } else {
        $st = 'denied'; $why = $decision === 'approve' ? 'wrong_number' : 'not_me';
    }
    $s_ip = sanitize(substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), SQL);
    $r = db_query("UPDATE login_approval SET status = '$st', deny_reason = '$why', decided = NOW(), decided_ip = '$s_ip'
                   WHERE login_approval_id = '$rid' AND status = 'pending' AND expires > NOW()");
    if (!$r || $r->rowCount() < 1) { return ['error' => 'This sign-in request has expired or was already answered.']; }

    $user = get_user((int)$user_id);
    $d    = wn_login_approve_describe($row);
    if ($user && function_exists('send_notification')) {
        if ($st === 'approved') {
            send_notification($user['user_id'], $user['shard_id'], 'security', 'Sign-in approved on your phone',
                $d['browser'] . ' at IP address ' . $d['ip'] . ' signed in with your password and your phone\'s approval at '
                . gmdate('H:i') . ' UTC.', ['source_app' => 'core']);
        } else {
            send_notification($user['user_id'], $user['shard_id'], 'security', 'Sign-in blocked',
                'Someone signed in with your password on ' . $d['browser'] . ' at IP address ' . $d['ip']
                . ($why === 'wrong_number' ? ' and the wrong number was picked on your phone' : ' and you answered "Not me"')
                . ', so it was blocked. If that was not you, change your password now in Account security.',
                ['source_app' => 'core']);
        }
    }
    return ['status' => $st];
}

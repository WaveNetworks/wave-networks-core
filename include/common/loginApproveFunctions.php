<?php
/**
 * loginApproveFunctions.php
 * "Sign in with your phone" — a web sign-in page shows a QR; the signed-in phone app
 * opens it, shows which browser is asking, and Approve signs that browser in as the
 * phone's account (ContactSwipe task #2391).
 *
 * Security: the QR token and the browser's cookie nonce are random and stored only as
 * sha256. Two minutes, single use, and only the browser holding the nonce can collect
 * the sign-in. Requests are rate limited per IP. The approver is whoever is signed in
 * on the app (its device session), never a parameter. Each approval is written to the
 * account's sign-in history and sent as a security notification to the user's devices.
 *
 * OPT-IN PER APP: define WN_LOGIN_APPROVE before the bootstrap (the app's
 * include/common_auth.php and include/common_api.php), plus a screen in the app that
 * calls loginApproveGet / loginApproveDecide. Tables: login_approval (main/5.8).
 */

const WN_LOGIN_APPROVE_TTL_SEC   = 120;
const WN_LOGIN_APPROVE_PER_IP_10 = 15;
const WN_LOGIN_APPROVE_COOKIE    = 'wn_login_approve';

function wn_login_approve_enabled() {
    return defined('WN_LOGIN_APPROVE') && WN_LOGIN_APPROVE;
}

/** The universal link the QR carries; the app's deep-link router opens it on the approve screen. */
function wn_login_approve_link($token) {
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/auth/x.php')), '/');
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . $base . '/app/?page=webapprove&t=' . rawurlencode($token);
}

/** The browser's current request (by its cookie nonce), or null. */
function wn_login_approve_mine() {
    $nonce = $_COOKIE[WN_LOGIN_APPROVE_COOKIE] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) { return null; }
    $s_n = sanitize(hash('sha256', $nonce), SQL);
    $row = db_fetch(db_query("SELECT *, (expires > NOW()) AS live FROM login_approval WHERE nonce_hash = '$s_n'
                              ORDER BY login_approval_id DESC LIMIT 1"));
    return $row ?: null;
}

/**
 * Start a request for this browser. Returns ['token' => …] or ['error' => …].
 * The token lives in this browser's session (to redraw the QR on each refresh);
 * the database keeps only hashes.
 */
function wn_login_approve_start() {
    $ip   = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $s_ip = sanitize($ip, SQL);
    $n = db_fetch(db_query("SELECT COUNT(*) AS n FROM login_approval WHERE ip_address = '$s_ip' AND created > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"));
    if ((int)($n['n'] ?? 0) >= WN_LOGIN_APPROVE_PER_IP_10) {
        return ['error' => 'Too many sign-in requests from here. Please wait a few minutes.'];
    }

    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $nonce = bin2hex(random_bytes(32));
    $s_t   = sanitize(hash('sha256', $token), SQL);
    $s_n   = sanitize(hash('sha256', $nonce), SQL);
    $s_ua  = sanitize(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512), SQL);
    // Rough location: the country the CDN edge resolved for this IP, when there is one.
    $s_cc  = sanitize(preg_match('/^[A-Z]{2}$/', $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '') ? $_SERVER['HTTP_CF_IPCOUNTRY'] : '', SQL);
    $ttl   = (int)WN_LOGIN_APPROVE_TTL_SEC;
    db_query("INSERT INTO login_approval (token_hash, nonce_hash, ip_address, user_agent, country, created, expires)
              VALUES ('$s_t', '$s_n', '$s_ip', '$s_ua', '$s_cc', NOW(), DATE_ADD(NOW(), INTERVAL $ttl SECOND))");

    setcookie(WN_LOGIN_APPROVE_COOKIE, $nonce, [
        'expires' => time() + $ttl + 60, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[WN_LOGIN_APPROVE_COOKIE] = $nonce;
    $_SESSION['login_approve_token'] = $token;
    return ['token' => $token];
}

/** Where the request comes from, in words: browser + rough location (IP country when the edge tells us). */
function wn_login_approve_describe($row) {
    $browser = function_exists('parse_browser_name') ? parse_browser_name($row['user_agent'] ?? '') : '';
    $country = $row['country'] ?? '';
    return [
        'browser'  => ($browser && $browser !== 'Other') ? $browser : 'A web browser',
        'ip'       => $row['ip_address'] ?? '',
        'location' => $country,
        'created'  => $row['created'] ?? '',
    ];
}

/** A pending, live request by its QR token, or null. */
function wn_login_approve_by_token($token) {
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', (string)$token)) { return null; }
    $s_t = sanitize(hash('sha256', $token), SQL);
    $row = db_fetch(db_query("SELECT * FROM login_approval WHERE token_hash = '$s_t' AND status = 'pending' AND expires > NOW()"));
    return $row ?: null;
}

/**
 * The phone's answer. $user_id is the app's signed-in account. Returns '' or an error.
 */
function wn_login_approve_decide($token, $user_id, $approve) {
    $row = wn_login_approve_by_token($token);
    if (!$row) { return 'This sign-in request has expired or was already answered. Start again on the computer.'; }
    $id   = (int)$row['login_approval_id'];
    $uid  = (int)$user_id;
    $st   = $approve ? 'approved' : 'denied';
    $s_ip = sanitize(substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), SQL);
    $r = db_query("UPDATE login_approval SET status = '$st', user_id = '$uid', decided = NOW(), decided_ip = '$s_ip'
                   WHERE login_approval_id = '$id' AND status = 'pending' AND expires > NOW()");
    if (!$r || $r->rowCount() < 1) { return 'This sign-in request has expired or was already answered.'; }

    if ($approve) {
        $user = get_user($uid);
        $d    = wn_login_approve_describe($row);
        if ($user && function_exists('send_notification')) {
            send_notification($uid, $user['shard_id'], 'security', 'Signed in on a computer',
                $d['browser'] . ' at IP address ' . $d['ip'] . ' was signed in to your account with your approval at '
                . gmdate('H:i') . ' UTC. If that was not you, sign out of all sessions in Account security.',
                ['source_app' => 'core']);
        }
    }
    return '';
}

/**
 * The waiting browser collects an approved request: exactly once, and only with its
 * own nonce cookie. Signs in and redirects (never returns) when approved.
 */
function wn_login_approve_collect($row) {
    if (!$row || ($row['status'] ?? '') !== 'approved') { return; }
    $id = (int)$row['login_approval_id'];
    $r  = db_query("UPDATE login_approval SET status = 'used' WHERE login_approval_id = '$id' AND status = 'approved'");
    if (!$r || $r->rowCount() < 1) { return; }

    $user = get_user((int)$row['user_id']);
    if (!$user) { return; }
    unset($_SESSION['login_approve_token']);
    setcookie(WN_LOGIN_APPROVE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    // The phone is already a signed-in second device, so this does not ask for 2FA again.
    wn_login_finish($user, 'phone_approve', false);
}

<?php
/**
 * passkeyFunctions.php
 * Passkeys (WebAuthn) — ContactSwipe task #2661, part 3 of #2658. Library: lbuchs/webauthn.
 *
 * Two uses, both with user verification REQUIRED (Face ID / fingerprint / device PIN):
 *   - password-less sign-in: "Sign in with a passkey" on the sign-in page. Discoverable
 *     credentials, so the person picks their account on the device; no email typed.
 *     A passkey with user verification is already two factors (the device + the person),
 *     so it finishes the sign-in without the authenticator code (login_method 'passkey').
 *   - second factor: on auth/2fa.php after a password, "Use a passkey" (login_method
 *     '2fa_passkey') instead of the 6-digit code.
 * Enrol / rename / remove on the app's account security page (snippets/passkey_manage.php).
 * Enrolling needs a verified email (twofa_enrol_blocker) and a passkey is only saved after
 * the device proved it (the attestation verified against the challenge we issued).
 * Every add and remove: a notice email + a security notification.
 *
 * OPT-IN PER APP, like WN_LOGIN_CODE: the app defines WN_PASSKEY in its bootstraps
 * (common_auth.php for the sign-in pages, common.php + common_api.php for account security).
 * The relying party id is this request's host (passkeys made on contactsweep.com work only
 * there); WN_PASSKEY_RP_ID overrides it, e.g. to share across subdomains.
 *
 * Platform authenticators only (the phone or computer itself, synced by iCloud Keychain /
 * Google Password Manager), not USB keys: the owner's spec.
 */

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

const WN_PASSKEY_TIMEOUT_S   = 120;
const WN_PASSKEY_MAX_PER_USER = 20;

function wn_passkey_enabled() {
    return defined('WN_PASSKEY') && WN_PASSKEY && class_exists(WebAuthn::class);
}

function wn_passkey_rp_id() {
    if (defined('WN_PASSKEY_RP_ID') && WN_PASSKEY_RP_ID) { return (string)WN_PASSKEY_RP_ID; }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return preg_replace('/:\d+$/', '', $host);
}

function wn_passkey_rp() {
    $b = function_exists('get_branding') ? get_branding() : [];
    $name = trim((string)($b['site_name'] ?? '')) ?: wn_passkey_rp_id();
    // Base64url so the options go to the browser as plain JSON (assets/js/passkey.js decodes).
    return new WebAuthn($name, wn_passkey_rp_id(), null, true);
}

function wn_b64url_encode($bin) { return rtrim(strtr(base64_encode((string)$bin), '+/', '-_'), '='); }
function wn_b64url_decode($s) {
    $s = strtr((string)$s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) { $s .= str_repeat('=', 4 - $pad); }
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

/** The person's passkeys, newest first (never the public key). */
function wn_passkey_list($user_id) {
    $uid = (int)$user_id;
    $out = [];
    $r = db_query("SELECT passkey_id, name, backed_up, created, last_used FROM user_passkey WHERE user_id = '$uid' ORDER BY passkey_id DESC");
    while ($r && ($row = db_fetch($r))) { $out[] = $row; }
    return $out;
}

function wn_passkey_count($user_id) {
    $uid = (int)$user_id;
    $row = db_fetch(db_query("SELECT COUNT(*) AS n FROM user_passkey WHERE user_id = '$uid'"));
    return (int)($row['n'] ?? 0);
}

/** A name a person reads ("iPhone", "Chrome on Windows"), from this browser. */
function wn_passkey_default_name() {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android phone', 'Macintosh' => 'Mac', 'Windows' => 'Windows PC', 'CrOS' => 'Chromebook', 'Linux' => 'Linux computer'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { return $name; }
    }
    return 'Passkey';
}

function wn_passkey_clean_name($name) {
    $name = trim(preg_replace('/\s+/', ' ', strip_tags((string)$name)));
    return mb_substr($name, 0, 80) ?: wn_passkey_default_name();
}

/**
 * Options for navigator.credentials.create(). Remembers the challenge in the session.
 * Returns ['options' => obj] or ['error' => msg].
 */
function wn_passkey_enrol_options($user) {
    if (!wn_passkey_enabled()) { return ['error' => 'Passkeys are not offered here.']; }
    $uid = (int)($user['user_id'] ?? 0);
    if (($why = twofa_enrol_blocker($uid)) !== '') { return ['error' => $why]; }
    if (wn_passkey_count($uid) >= WN_PASSKEY_MAX_PER_USER) { return ['error' => 'You have the most passkeys an account can hold. Remove one first.']; }

    $exclude = [];
    $r = db_query("SELECT credential_id FROM user_passkey WHERE user_id = '$uid'");
    while ($r && ($row = db_fetch($r))) { $exclude[] = wn_b64url_decode($row['credential_id']); }

    $handle = random_bytes(16);   // what the device stores for "which account": never the user id or email
    $rp = wn_passkey_rp();
    // resident key required (discoverable), UV required, platform authenticator (false = not cross-platform)
    $args = $rp->getCreateArgs($handle, (string)$user['email'], trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: (string)$user['email'],
                               WN_PASSKEY_TIMEOUT_S, true, true, false, $exclude);
    $_SESSION['wn_pk_enrol'] = [
        'challenge' => wn_b64url_encode($rp->getChallenge()->getBinaryString()),
        'handle'    => wn_b64url_encode($handle),
        'user_id'   => $uid,
        'at'        => time(),
    ];
    return ['options' => $args];
}

/**
 * Verify the device's answer and save the passkey. Nothing is saved unless it verifies.
 * Inputs are base64url strings from assets/js/passkey.js. Returns ['ok' => passkey_id] or ['error' => msg].
 */
function wn_passkey_enrol_finish($user, $client_data_b64, $attestation_b64, $name) {
    $pending = $_SESSION['wn_pk_enrol'] ?? null;
    unset($_SESSION['wn_pk_enrol']);
    $uid = (int)($user['user_id'] ?? 0);
    if (!wn_passkey_enabled()) { return ['error' => 'Passkeys are not offered here.']; }
    if (!$pending || (int)$pending['user_id'] !== $uid || time() - (int)$pending['at'] > WN_PASSKEY_TIMEOUT_S + 60) {
        return ['error' => 'That took too long. Try adding the passkey again.'];
    }
    if (($why = twofa_enrol_blocker($uid)) !== '') { return ['error' => $why]; }

    try {
        $data = wn_passkey_rp()->processCreate(wn_b64url_decode($client_data_b64), wn_b64url_decode($attestation_b64),
                                               wn_b64url_decode($pending['challenge']), true, true, false);
    } catch (WebAuthnException $e) {
        return ['error' => 'Your device did not confirm the passkey (' . $e->getMessage() . '). Try again.'];
    }

    $cred   = sanitize(wn_b64url_encode($data->credentialId), SQL);
    $handle = sanitize($pending['handle'], SQL);
    $pem    = sanitize((string)$data->credentialPublicKey, SQL);
    $count  = (int)($data->signatureCounter ?? 0);
    $s_name = sanitize(wn_passkey_clean_name($name), SQL);
    $aaguid = '';
    if (!empty($data->AAGUID)) {
        $hex = bin2hex(is_object($data->AAGUID) ? $data->AAGUID->getBinaryString() : (string)$data->AAGUID);
        if (strlen($hex) === 32) { $aaguid = implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)]); }
    }
    $s_aaguid = $aaguid !== '' ? "'" . sanitize($aaguid, SQL) . "'" : 'NULL';
    $backed = !empty($data->isBackedUp) ? 1 : 0;

    $dupe = db_fetch(db_query("SELECT passkey_id FROM user_passkey WHERE credential_id = '$cred'"));
    if ($dupe) { return ['error' => 'That passkey is already saved.']; }
    $ok = db_query("INSERT INTO user_passkey (user_id, credential_id, user_handle, public_key, sign_count, name, aaguid, backed_up, created)
                    VALUES ('$uid', '$cred', '$handle', '$pem', '$count', '$s_name', $s_aaguid, '$backed', NOW())");
    if (!$ok) { return ['error' => 'The passkey could not be saved. Try again.']; }
    $id = (int)db_insert_id();
    wn_passkey_notice($user, 'added', wn_passkey_clean_name($name));
    return ['ok' => $id];
}

/** Rename one of the person's own passkeys. */
function wn_passkey_rename($user_id, $passkey_id, $name) {
    $uid = (int)$user_id; $pid = (int)$passkey_id;
    $s_name = sanitize(wn_passkey_clean_name($name), SQL);
    $r = db_query("UPDATE user_passkey SET name = '$s_name' WHERE passkey_id = '$pid' AND user_id = '$uid'");
    return $r && $r->rowCount() > 0;
}

/** Remove one of the person's own passkeys ($by = 'user' or 'support'). Returns the removed name or ''. */
function wn_passkey_remove($user_id, $passkey_id, $by = 'user') {
    $uid = (int)$user_id; $pid = (int)$passkey_id;
    $row = db_fetch(db_query("SELECT name FROM user_passkey WHERE passkey_id = '$pid' AND user_id = '$uid'"));
    if (!$row) { return ''; }
    db_query("DELETE FROM user_passkey WHERE passkey_id = '$pid' AND user_id = '$uid'");
    $user = get_user($uid);
    if ($user) { wn_passkey_notice($user, $by === 'support' ? 'removed by support' : 'removed', $row['name']); }
    return (string)$row['name'];
}

/**
 * Options for navigator.credentials.get(). $user = the account a second step is for (its
 * passkeys only), or null for password-less sign-in (any discoverable passkey for this site).
 */
function wn_passkey_signin_options($user = null) {
    $ids = [];
    if ($user) {
        $uid = (int)$user['user_id'];
        $r = db_query("SELECT credential_id FROM user_passkey WHERE user_id = '$uid'");
        while ($r && ($row = db_fetch($r))) { $ids[] = wn_b64url_decode($row['credential_id']); }
        if (!$ids) { return null; }
    }
    $rp = wn_passkey_rp();
    // usb/nfc/ble off (platform passkeys only); hybrid on, so a phone can sign in a computer; UV required
    $args = $rp->getGetArgs($ids, WN_PASSKEY_TIMEOUT_S, false, false, false, true, true, true);
    $_SESSION['wn_pk_signin'] = [
        'challenge' => wn_b64url_encode($rp->getChallenge()->getBinaryString()),
        'user_id'   => $user ? (int)$user['user_id'] : 0,
        'at'        => time(),
    ];
    return $args;
}

/**
 * Check a sign-in answer. Returns ['user_id' => N] (the challenge is spent) or ['error' => msg].
 * $expect_user_id: the second step must be done with THAT account's passkey.
 */
function wn_passkey_signin_verify($post, $expect_user_id = 0) {
    $generic = 'That passkey did not work here. Try again or use another way to sign in.';
    $pending = $_SESSION['wn_pk_signin'] ?? null;
    unset($_SESSION['wn_pk_signin']);
    if (!wn_passkey_enabled()) { return ['error' => 'Passkeys are not offered here.']; }
    if (!$pending || time() - (int)$pending['at'] > WN_PASSKEY_TIMEOUT_S + 60) {
        return ['error' => 'That took too long. Try the passkey again.'];
    }
    if ((int)$pending['user_id'] !== (int)$expect_user_id) { return ['error' => $generic]; }

    $cred = wn_b64url_encode(wn_b64url_decode($post['credential_id'] ?? ''));
    if ($cred === '') { return ['error' => $generic]; }
    $s_cred = sanitize($cred, SQL);
    $row = db_fetch(db_query("SELECT * FROM user_passkey WHERE credential_id = '$s_cred'"));
    if (!$row) { return ['error' => 'This passkey is not saved on any account here. It may have been removed.']; }
    if ($expect_user_id && (int)$row['user_id'] !== (int)$expect_user_id) { return ['error' => $generic]; }
    $handle = (string)($post['user_handle'] ?? '');
    if ($handle !== '' && !hash_equals((string)$row['user_handle'], wn_b64url_encode(wn_b64url_decode($handle)))) {
        return ['error' => $generic];
    }

    $rp = wn_passkey_rp();
    try {
        $rp->processGet(wn_b64url_decode($post['client_data'] ?? ''), wn_b64url_decode($post['authenticator_data'] ?? ''),
                        wn_b64url_decode($post['signature'] ?? ''), $row['public_key'], wn_b64url_decode($pending['challenge']),
                        (int)$row['sign_count'] ?: null, true, true);
    } catch (WebAuthnException $e) {
        if (function_exists('record_login')) { record_login((int)$row['user_id'], $expect_user_id ? '2fa_passkey' : 'passkey', 'failed'); }
        return ['error' => $generic];
    }
    $pid   = (int)$row['passkey_id'];
    $count = (int)($rp->getSignatureCounter() ?? 0);
    db_query("UPDATE user_passkey SET last_used = NOW()" . ($count > 0 ? ", sign_count = '$count'" : '') . " WHERE passkey_id = '$pid'");
    return ['user_id' => (int)$row['user_id']];
}

/** "A passkey was added/removed" — email + security notification, so a change nobody made is seen. */
function wn_passkey_notice($user, $what, $name) {
    $b    = function_exists('get_branding') ? get_branding() : [];
    $site = $b['site_name'] ?? 'your account';
    $e    = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $who  = function_exists('wn_2fa_email_who') ? wn_2fa_email_who() : ('IP address ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $when = gmdate('j M Y, H:i') . ' UTC';
    $line = 'A passkey ("' . $name . '") was ' . $what . ' on your ' . $site . ' account.';
    $body = '<h2>' . $e($line) . '</h2>'
          . '<p>' . ($what === 'added' ? 'From ' . $e($who) . ' on ' . $e($when) . '.' : 'On ' . $e($when) . '.') . '</p>'
          . '<p style="color:#666">If this wasn\'t you, change your password now and sign out of all sessions.</p>';
    $text = $line . "\n\n" . ($what === 'added' ? 'From ' . $who . ' on ' . $when . '.' : 'On ' . $when . '.')
          . "\n\nIf this wasn't you, change your password now and sign out of all sessions.\n";
    $subject = 'Passkey ' . $what . ' on ' . $site;
    if (!empty($user['email'])) {
        if (function_exists('send_email_now')) {
            send_email_now($user['email'], '', $subject, $body, ['source_app' => 'core', 'alt_body' => $text]);
        } elseif (function_exists('send_email')) {
            send_email($user['email'], '', $subject, $body);
        }
    }
    if (function_exists('send_notification')) {
        send_notification((int)$user['user_id'], $user['shard_id'] ?? null, 'security', 'Passkey ' . $what,
            $line . ' If that was not you, change your password.',
            array_filter(['source_app' => 'core', 'action_url' => function_exists('wn_2fa_email_review_url') ? (wn_2fa_email_review_url(false) ?: null) : null,
                          'action_label' => 'Review your sign-in methods']));
    }
}

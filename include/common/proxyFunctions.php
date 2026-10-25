<?php
/**
 * proxyFunctions.php
 * Running behind a reverse proxy: TLS ends at the proxy, and optionally sign-in does too
 * (a Shibboleth SP on the proxy, as UW/ITHS run TRU and RISE).
 *
 * Both halves are OFF until configured, so a host without a proxy is unchanged.
 *
 * 1. TRUSTED PROXIES — $trusted_proxies in config.php, or env TRUSTED_PROXIES
 *    (comma-separated IPs/CIDRs of the proxy as THIS server sees it).
 *    A request whose peer is in that list has its X-Forwarded-* headers applied to $_SERVER
 *    in bootstrap, before anything reads it. Everything that already builds URLs from
 *    $_SERVER['HTTPS'] / HTTP_HOST (OAuth + SAML redirect URIs, email links, media URLs,
 *    cookie Secure flags) and everything that records REMOTE_ADDR (login history, rate
 *    limits, consent, error log) then sees the visitor's real scheme, host and IP instead of
 *    the proxy's. Without it, behind a proxy that talks plain http to the app, every
 *    redirect URI comes out http:// (the IdP refuses it) and every visitor shares the
 *    proxy's IP (one person's failed logins throttle everyone).
 *    Headers from any other peer are ignored — anyone can send X-Forwarded-For.
 *
 * 2. PROXY SIGN-IN — $proxy_auth_user_header (env PROXY_AUTH_USER_HEADER), e.g.
 *    'EduPersonPrincipalName' or 'eppn'. The proxy authenticates the person and passes who
 *    they are; the app signs in the matching user (by email) without its own login form.
 *    Optional: $proxy_auth_email_header / _first_name_header / _last_name_header,
 *    $proxy_auth_logout_url (e.g. '/Shibboleth.sso/Logout?return=https://idp/logout'),
 *    $proxy_auth_autocreate (create unknown users, honouring registration_mode),
 *    $proxy_auth_required (default on: no identity = no way in, no password form).
 *
 * What we learned doing this for TRU (crctest/crcdev/tru.rit.uw.edu, 2026-10):
 *  - The same attribute arrives under different names depending on WHERE the SP is.
 *    SP on this server: an Apache env var ('eppn'). SP on a proxy: a request header, which
 *    PHP sees as HTTP_<NAME> ('RequestHeader set EduPersonPrincipalName ...' →
 *    HTTP_EDUPERSONPRINCIPALNAME). wn_proxy_attr() looks in all places, but trusts the
 *    HTTP_ form only from a trusted proxy — a header is something any client can send.
 *  - A copied vhost with the wrong ServerName sends one hostname to another app's
 *    backend, and the wrong Shib applicationId shares sessions between them. So a session
 *    remembers whose identity made it, and is dropped when the proxy says someone else.
 *  - Logging out of the app alone is useless: the proxy's SSO session signs you straight
 *    back in. Logout goes on to the proxy's logout URL.
 *  - The backend port is often reachable without the proxy (host:8444). With proxy sign-in
 *    required, a request that arrives without an identity gets no login form to try.
 *  - Shib passes multi-valued attributes joined with ';'; the first value is the one used.
 *
 * Proxy sign-in skips core's own TOTP step: the organisation's IdP owns MFA for these users.
 */

/** A proxy setting: config.php variable first, then the environment. */
function wn_proxy_setting($name, $default = '') {
    if (isset($GLOBALS[$name]) && $GLOBALS[$name] !== '') {
        return $GLOBALS[$name];
    }
    $env = getenv(strtoupper($name));
    return ($env !== false && $env !== '') ? $env : $default;
}

/** Truthy setting ('1', 'true', 'yes', 'on', true). */
function wn_proxy_flag($name, $default = false) {
    $v = wn_proxy_setting($name, $default ? '1' : '0');
    if (is_bool($v)) { return $v; }
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
}

/** The configured proxy addresses/CIDRs as a list. */
function wn_trusted_proxies() {
    $v = wn_proxy_setting('trusted_proxies', '');
    $list = is_array($v) ? $v : explode(',', (string) $v);
    return array_values(array_filter(array_map('trim', $list), 'strlen'));
}

/** Is $ip inside $cidr (IPv4 or IPv6; a bare address is a /32 or /128)? */
function wn_ip_in_cidr($ip, $cidr) {
    $parts = explode('/', $cidr, 2);
    $net  = @inet_pton($parts[0]);
    $addr = @inet_pton($ip);
    if ($net === false || $addr === false || strlen($net) !== strlen($addr)) { return false; }

    $bits = isset($parts[1]) ? (int) $parts[1] : strlen($net) * 8;
    $bytes = intdiv($bits, 8);
    if (strncmp($net, $addr, $bytes) !== 0) { return false; }
    $rem = $bits % 8;
    if ($rem === 0) { return true; }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($net[$bytes]) & $mask) === (ord($addr[$bytes]) & $mask);
}

function wn_ip_in_list($ip, array $list) {
    foreach ($list as $cidr) {
        if (wn_ip_in_cidr($ip, $cidr)) { return true; }
    }
    return false;
}

/** First value of a comma-separated forwarded header, lower-cased and trimmed. */
function wn_forwarded_first($key) {
    $v = (string) ($_SERVER[$key] ?? '');
    return strtolower(trim(explode(',', $v)[0]));
}

/**
 * Apply a trusted proxy's X-Forwarded-* to $_SERVER. Called once from bootstrap.php.
 * Always records the real peer in WN_PEER_ADDR and whether it was a trusted proxy in
 * WN_VIA_PROXY, so later code can tell even after REMOTE_ADDR is rewritten.
 */
function wn_proxy_normalize_request() {
    // No REMOTE_ADDR = cron/CLI, nothing to normalise; WN_PEER_ADDR set = already done.
    if (!isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['WN_PEER_ADDR'])) { return; }

    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $_SERVER['WN_PEER_ADDR'] = $peer;
    $_SERVER['WN_VIA_PROXY'] = false;

    $proxies = wn_trusted_proxies();
    if (!$proxies || $peer === '' || !wn_ip_in_list($peer, $proxies)) { return; }
    $_SERVER['WN_VIA_PROXY'] = true;

    $proto = wn_forwarded_first('HTTP_X_FORWARDED_PROTO');
    if ($proto === '' && wn_forwarded_first('HTTP_X_FORWARDED_SSL') === 'on') { $proto = 'https'; }
    if ($proto === 'https') {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REQUEST_SCHEME'] = 'https';
    } elseif ($proto === 'http') {
        unset($_SERVER['HTTPS']);
        $_SERVER['REQUEST_SCHEME'] = 'http';
    }

    $host = wn_forwarded_first('HTTP_X_FORWARDED_HOST');
    if ($host !== '' && preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $_SERVER['HTTP_HOST']   = $host;
        $_SERVER['SERVER_NAME'] = explode(':', $host)[0];
    }

    $port = wn_forwarded_first('HTTP_X_FORWARDED_PORT');
    if (ctype_digit($port)) {
        $_SERVER['SERVER_PORT'] = $port;
    } elseif ($proto !== '') {
        $_SERVER['SERVER_PORT'] = $proto === 'https' ? '443' : '80';
    }

    // The visitor is the right-most address the proxies did not add themselves.
    $chain = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($chain as $hop) {
        if ($hop === '' || @inet_pton($hop) === false) { break; }
        if (!wn_ip_in_list($hop, $proxies)) {
            $_SERVER['REMOTE_ADDR'] = $hop;
            break;
        }
    }
}

// ─── Proxy sign-in ────────────────────────────────────────────────────────────

function wn_proxy_auth_enabled() {
    return wn_proxy_setting('proxy_auth_user_header', '') !== '';
}

/**
 * An attribute the proxy (or a Shib SP on this server) passed in, or '' if none.
 * Server env forms (eppn, REDIRECT_eppn) cannot come from the client, so they are always
 * read; the request-header form (HTTP_EPPN) only from a trusted proxy.
 */
function wn_proxy_attr($name) {
    if ($name === '') { return ''; }
    $hdr = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $candidates = [$name, 'REDIRECT_' . $name];
    if (!empty($_SERVER['WN_VIA_PROXY'])) { $candidates[] = $hdr; }

    foreach ($candidates as $key) {
        $v = trim(explode(';', (string) ($_SERVER[$key] ?? ''))[0]);
        if ($v !== '' && $v !== '(null)') { return $v; }
    }
    return '';
}

/** Who the proxy says this is: ['id', 'email', 'first_name', 'last_name'], or null. */
function wn_proxy_auth_identity() {
    if (!wn_proxy_auth_enabled()) { return null; }
    $id = wn_proxy_attr(wn_proxy_setting('proxy_auth_user_header'));
    if ($id === '') { return null; }

    $email = wn_proxy_attr(wn_proxy_setting('proxy_auth_email_header', ''));
    if ($email === '' && valid_email($id)) { $email = $id; }

    return [
        'id'         => $id,
        'email'      => strtolower($email),
        'first_name' => wn_proxy_attr(wn_proxy_setting('proxy_auth_first_name_header', '')),
        'last_name'  => wn_proxy_attr(wn_proxy_setting('proxy_auth_last_name_header', '')),
    ];
}

/**
 * A signed-in session made by a different proxy identity than this request carries
 * (someone else on a shared machine, or two hostnames sharing one SSO session).
 */
function wn_proxy_auth_session_mismatch() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['proxy_auth_id'])) { return false; }
    $who = wn_proxy_auth_identity();
    return $who !== null && $who['id'] !== $_SESSION['proxy_auth_id'];
}

/**
 * Sign in the user the proxy identified.
 * @return array ['user' => row] on success, or ['error' => message] when there is an
 *               identity but no way in, or [] when the request carries no identity.
 */
function wn_proxy_auth_login() {
    $who = wn_proxy_auth_identity();
    if (!$who) { return []; }

    if ($who['email'] === '' || !valid_email($who['email'])) {
        error_log("Proxy sign-in: no usable email for '{$who['id']}' (set proxy_auth_email_header)");
        return ['error' => 'Your sign-in did not include an email address this site can use.'];
    }

    $user = get_user_by_email($who['email']);

    if (!$user) {
        $mode = function_exists('wn_registration_mode') ? wn_registration_mode() : 'open';
        if (!wn_proxy_flag('proxy_auth_autocreate') || in_array($mode, ['closed', 'invite'], true)) {
            return ['error' => 'You are signed in as ' . $who['id'] . ', but you do not have an account on this site. Ask an administrator to add you.'];
        }

        $shard_id = get_least_loaded_shard();
        $r = db_query("INSERT INTO user (email, shard_id, is_confirmed, oauth_provider, oauth_id, created_date)
                        VALUES ('" . sanitize($who['email'], SQL) . "', '$shard_id', 1, 'proxy', '" . sanitize($who['id'], SQL) . "', NOW())");
        if (!$r) {
            return ['error' => 'Could not create your account. ' . db_error()];
        }
        $new_id = db_insert_id();
        prime_shard($shard_id);
        db_query_shard($shard_id, "INSERT INTO user_profile (user_id, first_name, last_name, created)
                        VALUES ('$new_id', '" . sanitize($who['first_name'], SQL) . "', '" . sanitize($who['last_name'], SQL) . "', NOW())");
        $_SESSION['shard_id'] = $shard_id;
        create_home_dir_id($new_id);
        $user = get_user($new_id);
    }

    if (!$user['is_confirmed']) {
        // The proxy has proved who they are; that is what confirmation was for.
        db_query("UPDATE user SET is_confirmed = 1 WHERE user_id = '" . (int) $user['user_id'] . "'");
    }

    load_user_session($user);
    $_SESSION['proxy_auth_id'] = $who['id'];

    if (function_exists('get_or_create_device')) {
        try { get_or_create_device(); } catch (Exception $e) { /* graceful */ }
    }
    if (function_exists('record_login')) {
        record_login($user['user_id'], 'proxy', 'success');
    }
    return ['user' => $user];
}

/** Where logout sends the browser: the proxy's logout URL, or '' for core's own login page. */
function wn_proxy_auth_logout_url() {
    return wn_proxy_auth_enabled() ? (string) wn_proxy_setting('proxy_auth_logout_url', '') : '';
}

/** Proxy sign-in is on and is the only way in (default when on). */
function wn_proxy_auth_required() {
    return wn_proxy_auth_enabled() && wn_proxy_flag('proxy_auth_required', true);
}

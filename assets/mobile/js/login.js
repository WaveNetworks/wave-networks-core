/**
 * login.js — the one screen the shell owns (spec 05).
 *
 * Every other screen in this app is a view (views/*.php), fetched as markup. Login
 * cannot be: `auth/login.php` is a full HTML page with its own <html> shell, not a
 * `?page=` fragment — and more to the point, a bundled client must be able to reach a
 * login screen with NO network and NO session. So it is bundled, and it is the only
 * hand-written screen in m/.
 *
 * It exchanges credentials for a device token via core's `deviceLogin` action, stores
 * the token, and hands off to the router. From that moment the app is authenticated and
 * every screen is a view again.
 *
 * It also creates accounts, in place, via `deviceRegister` — the web register page's
 * reCAPTCHA cannot run on an app:// origin, so sending people to the browser to sign up
 * was the only option until core grew a device sign-up (rate limits + a honeypot instead).
 * A new account is signed in on the spot unless the site requires email confirmation.
 *
 * (Per the spec this belongs in wave-networks-core as a vendorable m/ screen — it is
 * identical in every child app. It lives here until core ships the vendorable set.)
 */
window.WnLogin = (function () {
    'use strict';

    var needsTotp = false;

    function markup() {
        return ''
            + '<div class="d-flex flex-column justify-content-center" style="min-height:70vh;max-width:420px;margin:0 auto;">'
            +   '<div class="text-center mb-4">'
            +     '<img src="assets/img/app-tile.svg" alt="" width="72" height="72" class="mb-3">'
            +     '<h1 class="h4 mb-1" id="wnLoginTitle">Welcome back</h1>'
            +     '<p class="text-muted small mb-0" id="wnLoginSub">Sign in to continue.</p>'
            +   '</div>'
            +   '<div id="wnLoginErr" class="alert alert-danger d-none" role="alert" style="white-space:pre-line;"></div>'
            +   '<div id="wnLoginOk" class="alert alert-success d-none" role="status"></div>'
            +   '<div id="wnLoginPanel">'
            +   '<form id="wnLoginForm" novalidate>'
            +     '<div class="mb-3">'
            +       '<label class="form-label" for="wnEmail">Email</label>'
            +       '<input type="email" class="form-control" id="wnEmail" autocomplete="username" '
            +              'inputmode="email" autocapitalize="none" required>'
            +     '</div>'
            +     '<div class="mb-3">'
            +       '<label class="form-label" for="wnPassword">Password</label>'
            +       '<input type="password" class="form-control" id="wnPassword" autocomplete="current-password" required>'
            +     '</div>'
            +     '<div class="mb-3 d-none" id="wnTotpWrap">'
            +       '<label class="form-label" for="wnTotp">Authentication code</label>'
            +       '<input type="text" class="form-control" id="wnTotp" inputmode="numeric" '
            +              'autocomplete="one-time-code" pattern="[0-9]*" maxlength="6">'
            +       '<div class="form-text">Enter the 6-digit code from your authenticator app.</div>'
            +     '</div>'
            +     '<button type="submit" class="btn btn-primary w-100" id="wnLoginBtn">Sign in</button>'
            +   '</form>'
            // "Continue with Google" (task #2461): filled in by loadProviders() when the site has one.
            +   '<div id="wnOAuth" class="d-none"></div>'
            +   '<p class="text-center text-muted small mt-4 mb-1">'
            +     '<a href="#" id="wnForgot">Forgot your password?</a>'
            +   '</p>'
            +   '<p class="text-center text-muted small mb-0">'
            +     'New here? <a href="#" id="wnRegister">Create an account</a>'
            +   '</p>'
            +   '</div>'
            +   registerMarkup()
            + '</div>';
    }

    /** The sign-up panel. Hidden until "Create an account"; fields match auth/register.php. */
    function registerMarkup() {
        return ''
            + '<div id="wnRegPanel" class="d-none">'
            +   '<form id="wnRegForm" novalidate>'
            +     '<div class="row g-2 mb-3">'
            +       '<div class="col">'
            +         '<label class="form-label" for="wnRegFirst">First name</label>'
            +         '<input type="text" class="form-control" id="wnRegFirst" autocomplete="given-name">'
            +       '</div>'
            +       '<div class="col">'
            +         '<label class="form-label" for="wnRegLast">Last name</label>'
            +         '<input type="text" class="form-control" id="wnRegLast" autocomplete="family-name">'
            +       '</div>'
            +     '</div>'
            +     '<div class="mb-3">'
            +       '<label class="form-label" for="wnRegEmail">Email</label>'
            +       '<input type="email" class="form-control" id="wnRegEmail" autocomplete="email" '
            +              'inputmode="email" autocapitalize="none" required>'
            +     '</div>'
            +     '<div class="mb-3">'
            +       '<label class="form-label" for="wnRegPassword">Password</label>'
            +       '<input type="password" class="form-control" id="wnRegPassword" autocomplete="new-password" required>'
            +       '<div class="form-text">At least 8 characters.</div>'
            +     '</div>'
            +     '<div class="mb-3">'
            +       '<label class="form-label" for="wnRegConfirm">Confirm password</label>'
            +       '<input type="password" class="form-control" id="wnRegConfirm" autocomplete="new-password" required>'
            +     '</div>'
            // Honeypot. A person never sees or fills it; deviceRegister refuses when it is set.
            +     '<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">'
            +       '<label for="wnRegWebsite">Website</label>'
            +       '<input type="text" id="wnRegWebsite" tabindex="-1" autocomplete="off">'
            +     '</div>'
            // The agreement is explicit: nothing is created until it is ticked (the button stays
            // disabled), and the versions shown here are the ones recorded (legal_version_ids).
            +     '<div class="mb-3 form-check wn-reg-agree">'
            +       '<input type="checkbox" class="form-check-input" id="wnRegTerms" required>'
            +       '<label class="form-check-label small" for="wnRegTerms">'
            +         'I agree to the <a href="#" id="wnRegTos">Terms of Service</a> and '
            +         'the <a href="#" id="wnRegPrivacy">Privacy Policy</a>'
            +       '</label>'
            +       '<div class="form-text" id="wnRegLegalVer"></div>'
            +     '</div>'
            +     '<button type="submit" class="btn btn-primary w-100" id="wnRegBtn" disabled>Agree &amp; create account</button>'
            +   '</form>'
            +   '<p class="text-center text-muted small mt-4 mb-0">'
            +     'Already have an account? <a href="#" id="wnShowLogin">Sign in</a>'
            +   '</p>'
            + '</div>';
    }

    /** Swap between the sign-in and sign-up panels, keeping the shared header and error box. */
    // The Terms / Privacy versions in force (getLegalVersions, public): shown on the sign-up
    // panel, opened by its links, and posted back so the account records what was shown.
    var legal = null;
    function legalUrl(doc) {
        var key = doc === 'terms' ? 'terms_of_service' : 'privacy_policy';
        if (legal && legal[key] && legal[key].url) return legal[key].url;
        return window.WN_ENV.AUTH_BASE + '../../admin/legal/' + doc;   // forwards to the app's own page
    }
    function openLegal(doc) {
        var url = legalUrl(doc);
        if (typeof Platform.openInApp === 'function') Platform.openInApp(url); else Platform.openExternal(url);
    }
    function loadLegal() {
        if (legal) return;
        var body = new FormData();
        body.append('action', 'getLegalVersions');
        fetch(window.WN_ENV.API_BASE + 'index.php', { method: 'POST', body: body, credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                legal = (json && json.results && json.results.versions) || {};
                var t = legal.terms_of_service, p = legal.privacy_policy, el = document.getElementById('wnRegLegalVer');
                if (el && t && p) el.textContent = 'Terms of Service ' + t.version_label + ' · Privacy Policy ' + p.version_label;
            })
            .catch(function () {});
    }
    function legalIds() {
        if (!legal) return '';
        return ['terms_of_service', 'privacy_policy'].map(function (k) { return legal[k] ? legal[k].version_id : ''; }).filter(Boolean).join(',');
    }
    function syncRegBtn() {
        var b = document.getElementById('wnRegBtn'), c = document.getElementById('wnRegTerms');
        if (b && c) b.disabled = !c.checked;
    }

    function showPanel(which) {
        var reg = which === 'register';
        if (reg) loadLegal();
        document.getElementById('wnLoginPanel').classList.toggle('d-none', reg);
        document.getElementById('wnRegPanel').classList.toggle('d-none', !reg);
        document.getElementById('wnLoginTitle').textContent = reg ? 'Create your account' : 'Welcome back';
        document.getElementById('wnLoginSub').textContent   = reg ? 'It takes a minute.' : 'Sign in to continue.';
        document.getElementById('wnLoginErr').classList.add('d-none');
        var ok = document.getElementById('wnLoginOk');
        if (ok && reg) ok.classList.add('d-none');
        var first = document.getElementById(reg ? 'wnRegFirst' : 'wnEmail');
        if (first) first.focus();
    }

    function fail(msg) {
        var box = document.getElementById('wnLoginErr');
        if (!box) return;
        box.textContent = msg;
        box.classList.remove('d-none');
    }

    function submit(e) {
        e.preventDefault();

        var btn   = document.getElementById('wnLoginBtn');
        var email = (document.getElementById('wnEmail').value || '').trim();
        var pass  = document.getElementById('wnPassword').value || '';
        var totp  = (document.getElementById('wnTotp') || {}).value || '';

        document.getElementById('wnLoginErr').classList.add('d-none');
        btn.disabled = true;
        btn.textContent = 'Signing in…';

        var body = new FormData();
        body.append('action', 'deviceLogin');
        body.append('email', email);
        body.append('password', pass);
        if (totp) body.append('totp', totp);

        fetch(window.WN_ENV.API_BASE + 'index.php', {
            method: 'POST',
            body: body,
            // A stable per-install id, so this login shows up as ONE device in the
            // user's device list instead of a new row on every sign-in.
            headers: { 'X-Wn-Device': deviceId() },
            credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                btn.disabled = false;
                btn.textContent = 'Sign in';

                var res = json.results || {};

                // 2FA: not an error — the server is telling us to ask for the code.
                if (res.totp_required) {
                    needsTotp = true;
                    document.getElementById('wnTotpWrap').classList.remove('d-none');
                    document.getElementById('wnTotp').focus();
                    if (json.error) fail(json.error);
                    return;
                }

                if (json.error || !res.token) {
                    fail(json.error || 'Sign-in failed. Please try again.');
                    return;
                }

                signedIn(res);
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Sign in';
                fail(Platform.online()
                    ? 'Could not reach the server. Please try again.'
                    : "You're offline. Connect to sign in.");
            });
    }

    /**
     * Hand a fresh device token to the app. Shared by sign-in and sign-up, so a new
     * account arrives in the app through exactly the path a returning user takes.
     */
    function signedIn(res) {
        WnApi.setToken(res.token);
        WnApi.setUser({ user_id: res.user_id, email: res.email, name: res.name });

        // Policies to accept again (res.reconsent_needed) are answered INSIDE the app: the
        // shell's legal-notice.js asks getLegalStatus once signed in and shows the summary
        // with Accept. (Sending people to the browser's consent.php never worked — the
        // browser has no session — and the check here read .length off an object.)
        setTimeout(function () { if (window.WnLegalNotice) window.WnLegalNotice.check(); }, 800);

        WnStore.clear();          // never show the previous user's cached screens

        // The chrome (name/email in the topnav dropdown) is hydrated at boot for an
        // already-signed-in user; a FRESH login has to trigger it, or the dropdown
        // stays blank until the next launch.
        document.dispatchEvent(new CustomEvent('wn:authed'));

        var dest = '#/' + (WnRouter.home ? WnRouter.home() : 'dashboard');
        location.hash = dest;
        WnRouter.go(dest);
    }

    function register(e) {
        e.preventDefault();

        var btn = document.getElementById('wnRegBtn');
        var val = function (id) { return (document.getElementById(id) || {}).value || ''; };
        var email = val('wnRegEmail').trim();

        document.getElementById('wnLoginErr').classList.add('d-none');

        // The server checks all of this too; checking here only saves a round trip.
        if (!document.getElementById('wnRegTerms').checked) {
            fail('Please agree to the Terms of Service and Privacy Policy.');
            return;
        }
        if (val('wnRegPassword') !== val('wnRegConfirm')) {
            fail('Passwords do not match.');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Creating account…';

        var body = new FormData();
        body.append('action', 'deviceRegister');
        body.append('first_name', val('wnRegFirst').trim());
        body.append('last_name', val('wnRegLast').trim());
        body.append('email', email);
        body.append('password', val('wnRegPassword'));
        body.append('confirm_password', val('wnRegConfirm'));
        body.append('agree_terms', '1');
        body.append('legal_version_ids', legalIds());
        body.append('website', val('wnRegWebsite'));

        fetch(window.WN_ENV.API_BASE + 'index.php', {
            method: 'POST',
            body: body,
            headers: { 'X-Wn-Device': deviceId() },
            credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                btn.textContent = 'Agree & create account';
                syncRegBtn();

                var res = json.results || {};

                if (json.error) {
                    // The server joins several field errors with <br>; show them as lines.
                    fail(String(json.error).split(/<br\s*\/?>/i).join('\n'));
                    return;
                }

                // Sites that confirm email: the account exists but cannot sign in yet.
                if (res.confirm_required) {
                    showPanel('login');
                    document.getElementById('wnEmail').value = email;
                    var ok = document.getElementById('wnLoginOk');
                    ok.textContent = json.success || 'Account created. Check your email to confirm it, then sign in.';
                    ok.classList.remove('d-none');
                    return;
                }

                if (!res.token) {
                    fail('Sign-up failed. Please try again.');
                    return;
                }
                signedIn(res);
            })
            .catch(function () {
                btn.textContent = 'Agree & create account';
                syncRegBtn();
                fail(Platform.online()
                    ? 'Could not reach the server. Please try again.'
                    : "You're offline. Connect to create an account.");
            });
    }

    // ── Continue with Google (task #2461) ───────────────────────────────────────
    // Google refuses sign-in inside an embedded WebView, so the app opens the site's
    // auth/oauth_callback.php in the SYSTEM browser with device=1 and a challenge
    // (sha256 hex of a verifier kept here). The site ends the sign-in by sending the
    // browser to {APP_SCHEME}://open/auth?c=<one-time code>; oauthReturn() trades the
    // code + verifier for a device token (deviceOAuthExchange). A code read by anything
    // else on the phone is useless without the verifier.
    var OAUTH_KEY = 'wn.oauth_verifier';
    var GOOGLE_G = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true" style="vertical-align:-3px;margin-right:8px">'
        + '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
        + '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
        + '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
        + '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';

    /** SHA-256 hex of an ASCII string. Plain JS: crypto.subtle is missing on some app:// origins. */
    function sha256hex(str) {
        var K = [], H = [], i, j, isP = function (n) { for (var f = 2; f * f <= n; f++) if (n % f === 0) return false; return true; };
        for (i = 2, j = 0; j < 64; i++) if (isP(i)) { if (j < 8) H[j] = (Math.pow(i, 1 / 2) * 4294967296) | 0; K[j++] = (Math.pow(i, 1 / 3) * 4294967296) | 0; }
        var bytes = [], w = [];
        for (i = 0; i < str.length; i++) bytes.push(str.charCodeAt(i) & 255);
        var bitLen = bytes.length * 8;
        bytes.push(128);
        while (bytes.length % 64 !== 56) bytes.push(0);
        for (i = 7; i >= 0; i--) bytes.push(i > 3 ? 0 : (bitLen >>> (i * 8)) & 255);
        var rr = function (x, n) { return (x >>> n) | (x << (32 - n)); };
        for (var o = 0; o < bytes.length; o += 64) {
            for (i = 0; i < 64; i++) {
                if (i < 16) w[i] = (bytes[o + i * 4] << 24) | (bytes[o + i * 4 + 1] << 16) | (bytes[o + i * 4 + 2] << 8) | bytes[o + i * 4 + 3];
                else {
                    var s0 = rr(w[i - 15], 7) ^ rr(w[i - 15], 18) ^ (w[i - 15] >>> 3), s1 = rr(w[i - 2], 17) ^ rr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
                    w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
                }
            }
            var a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
            for (i = 0; i < 64; i++) {
                var t1 = (h + (rr(e, 6) ^ rr(e, 11) ^ rr(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + w[i]) | 0;
                var t2 = ((rr(a, 2) ^ rr(a, 13) ^ rr(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
                h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
            }
            H = [(H[0] + a) | 0, (H[1] + b) | 0, (H[2] + c) | 0, (H[3] + d) | 0, (H[4] + e) | 0, (H[5] + f) | 0, (H[6] + g) | 0, (H[7] + h) | 0];
        }
        return H.map(function (x) { return ('00000000' + (x >>> 0).toString(16)).slice(-8); }).join('');
    }

    function newVerifier() {
        var a = new Uint8Array(32), out = '', abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        (window.crypto || window.msCrypto).getRandomValues(a);
        for (var i = 0; i < a.length; i++) out += abc.charAt(a[i] & 63) + abc.charAt((a[i] >> 2) & 63);
        return out;   // 64 chars
    }

    function loadProviders() {
        var box = document.getElementById('wnOAuth');
        if (!box || !window.WN_ENV.APP_SCHEME) return;   // an app that has not declared its scheme has no way back
        var body = new FormData();
        body.append('action', 'deviceOAuthProviders');
        fetch(window.WN_ENV.API_BASE + 'index.php', { method: 'POST', body: body, credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var list = (json && json.results && json.results.providers) || [];
                if (list.indexOf('google') < 0) return;
                box.innerHTML = '<div class="d-flex align-items-center my-3 text-muted small"><hr class="flex-grow-1 my-0"><span class="px-2">or</span><hr class="flex-grow-1 my-0"></div>'
                    + '<button type="button" class="btn w-100 wn-oauth-google" id="wnGoogle" data-oauth="google" '
                    + 'style="background:#fff;color:#1f1f1f;border:1px solid #747775;font-weight:500">' + GOOGLE_G + 'Continue with Google</button>';
                box.classList.remove('d-none');
                document.getElementById('wnGoogle').addEventListener('click', function () { startOAuth('google'); });
            })
            .catch(function () {});
    }

    function startOAuth(provider) {
        var v = newVerifier();
        try { localStorage.setItem(OAUTH_KEY, v); } catch (e) { /* the exchange will say so */ }
        Platform.openExternal(window.WN_ENV.AUTH_BASE + 'oauth_callback.php?provider=' + encodeURIComponent(provider)
            + '&device=1&challenge=' + sha256hex(v));
    }

    var tradedCodes = {};
    /** {APP_SCHEME}://open/auth?c=<code> came back from the browser. Returns true when it was ours. */
    function oauthReturn(url) {
        var scheme = window.WN_ENV.APP_SCHEME;
        if (!scheme || String(url || '').indexOf(scheme + '://open/auth') !== 0) return false;
        var m = /[?&]c=([0-9a-f]{48})/.exec(url);
        if (!m || tradedCodes[m[1]]) return true;
        tradedCodes[m[1]] = 1;
        var v = '';
        try { v = localStorage.getItem(OAUTH_KEY) || ''; } catch (e) {}
        var body = new FormData();
        body.append('action', 'deviceOAuthExchange');
        body.append('code', m[1]);
        body.append('verifier', v);
        fetch(window.WN_ENV.API_BASE + 'index.php', {
            method: 'POST', body: body, headers: { 'X-Wn-Device': deviceId() },
            credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var res = (json && json.results) || {};
                if (json.error || !res.token) { fail((json && json.error) || 'Sign-in failed. Please try again.'); return; }
                try { localStorage.removeItem(OAUTH_KEY); } catch (e) {}
                signedIn(res);
            })
            .catch(function () { fail('Could not reach the server. Please try again.'); });
        return true;
    }

    // cordova-ios hands the app's own scheme to window.handleOpenURL; chained, never replaced.
    (function () {
        var prev = window.handleOpenURL;
        window.handleOpenURL = function (url) {
            if (typeof prev === 'function') { try { prev(url); } catch (e) { /* theirs */ } }
            setTimeout(function () { oauthReturn(url); }, 0);
        };
    })();

    /** A stable id for THIS install, so the device list shows one row, not one per login. */
    function deviceId() {
        var KEY = 'wn.device_id';
        try {
            var id = localStorage.getItem(KEY);
            if (!id) {
                id = 'viv-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
                localStorage.setItem(KEY, id);
            }
            return id;
        } catch (e) {
            return 'viv-anon';
        }
    }

    return {
        markup: markup,
        deviceId: deviceId,
        oauthReturn: oauthReturn,
        sha256hex: sha256hex,

        /** Called by the router once the login markup is in the DOM. */
        bind: function () {
            var form = document.getElementById('wnLoginForm');
            if (form) form.addEventListener('submit', submit);
            loadProviders();

            var forgot = document.getElementById('wnForgot');
            if (forgot) {
                forgot.addEventListener('click', function (e) {
                    e.preventDefault();
                    // Password reset is an emailed link — it has to happen in a real
                    // browser, not inside the WebView.
                    Platform.openExternal(window.WN_ENV.AUTH_BASE + 'forgot.php');
                });
            }

            var on = function (id, fn) {
                var el = document.getElementById(id);
                if (el) el.addEventListener('click', function (e) { e.preventDefault(); fn(); });
            };

            // Sign-up happens here, in the app (deviceRegister) — no trip to the browser.
            on('wnRegister',  function () { showPanel('register'); });
            on('wnShowLogin', function () { showPanel('login'); });

            // The policies are ordinary public web pages; read them in the browser. Core's
            // /admin/legal/<doc> serves every deployment and forwards to the app's own page
            // when it has one (legal.json), so this one link is right for every app.
            on('wnRegTos',     function () { openLegal('terms'); });
            on('wnRegPrivacy', function () { openLegal('privacy'); });
            var agree = document.getElementById('wnRegTerms');
            if (agree) agree.addEventListener('change', syncRegBtn);

            var regForm = document.getElementById('wnRegForm');
            if (regForm) regForm.addEventListener('submit', register);

            if (needsTotp) document.getElementById('wnTotpWrap').classList.remove('d-none');
        }
    };
})();

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
            +     '<div class="mb-3 form-check">'
            +       '<input type="checkbox" class="form-check-input" id="wnRegTerms" required>'
            +       '<label class="form-check-label small" for="wnRegTerms">'
            +         'I agree to the <a href="#" id="wnRegTos">Terms of Service</a> and '
            +         '<a href="#" id="wnRegPrivacy">Privacy Policy</a>'
            +       '</label>'
            +     '</div>'
            +     '<button type="submit" class="btn btn-primary w-100" id="wnRegBtn">Create account</button>'
            +   '</form>'
            +   '<p class="text-center text-muted small mt-4 mb-0">'
            +     'Already have an account? <a href="#" id="wnShowLogin">Sign in</a>'
            +   '</p>'
            + '</div>';
    }

    /** Swap between the sign-in and sign-up panels, keeping the shared header and error box. */
    function showPanel(which) {
        var reg = which === 'register';
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

        // Re-consent is a gate on the web, so it is a gate here too. The consent
        // page is not a view either, so send them to the browser to complete it
        // rather than half-admitting them into the app.
        if (res.reconsent_needed && res.reconsent_needed.length) {
            Platform.openExternal(window.WN_ENV.AUTH_BASE + 'consent.php');
            fail('Please accept the updated policies to continue.');
            WnApi.setToken('');
            return;
        }

        WnStore.clear();          // never show the previous user's cached screens

        // The chrome (name/email in the topnav dropdown) is hydrated at boot for an
        // already-signed-in user; a FRESH login has to trigger it, or the dropdown
        // stays blank until the next launch.
        document.dispatchEvent(new CustomEvent('wn:authed'));

        location.hash = '#/dashboard';
        WnRouter.go('#/dashboard');
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
        body.append('website', val('wnRegWebsite'));

        fetch(window.WN_ENV.API_BASE + 'index.php', {
            method: 'POST',
            body: body,
            headers: { 'X-Wn-Device': deviceId() },
            credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                btn.disabled = false;
                btn.textContent = 'Create account';

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
                btn.disabled = false;
                btn.textContent = 'Create account';
                fail(Platform.online()
                    ? 'Could not reach the server. Please try again.'
                    : "You're offline. Connect to create an account.");
            });
    }

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

        /** Called by the router once the login markup is in the DOM. */
        bind: function () {
            var form = document.getElementById('wnLoginForm');
            if (form) form.addEventListener('submit', submit);

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

            // The policies are ordinary web pages; read them in the browser. Same pages
            // auth/register.php links to (../site/ from the auth directory).
            on('wnRegTos',     function () { Platform.openExternal(window.WN_ENV.AUTH_BASE + '../site/terms.php'); });
            on('wnRegPrivacy', function () { Platform.openExternal(window.WN_ENV.AUTH_BASE + '../site/privacy.php'); });

            var regForm = document.getElementById('wnRegForm');
            if (regForm) regForm.addEventListener('submit', register);

            if (needsTotp) document.getElementById('wnTotpWrap').classList.remove('d-none');
        }
    };
})();

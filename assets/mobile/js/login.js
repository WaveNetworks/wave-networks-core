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
            +     '<h1 class="h4 mb-1">Welcome back</h1>'
            +     '<p class="text-muted small mb-0">Sign in to continue.</p>'
            +   '</div>'
            +   '<div id="wnLoginErr" class="alert alert-danger d-none" role="alert"></div>'
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
            + '</div>';
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
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = 'Sign in';
                fail(Platform.online()
                    ? 'Could not reach the server. Please try again.'
                    : "You're offline. Connect to sign in.");
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

            var reg = document.getElementById('wnRegister');
            if (reg) {
                reg.addEventListener('click', function (e) {
                    e.preventDefault();
                    // Registration needs the full web flow (reCAPTCHA + email confirmation),
                    // which can't run inside the bundled WebView — open it in the browser.
                    Platform.openExternal(window.WN_ENV.AUTH_BASE + 'register.php');
                });
            }

            if (needsTotp) document.getElementById('wnTotpWrap').classList.remove('d-none');
        }
    };
})();

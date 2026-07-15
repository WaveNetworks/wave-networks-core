/**
 * api.js — the mobile API client (spec 05).
 *
 * CRITICAL: this file exists to make the views' OWN JavaScript run unmodified.
 *
 * The screens' behavior is hoisted verbatim out of views/*.php, and that code
 * calls apiPost(action, data, callback) — admin's bs-init.js signature. So the
 * bundle must provide exactly that global, with exactly those semantics
 * (FormData body, {error,success,info,warning,results} envelope, alert on
 * error/success, callback with the parsed JSON).
 *
 * What differs from desktop, and why:
 *   - Bearer device token instead of a session cookie. Cookies are unreliable at
 *     file:// / app:// origins, so core's device-token machinery authenticates us.
 *   - Absolute API_BASE on device (env.js), relative in the browser.
 *   - "Login required" routes to the shell's login screen, not to ../auth/login.php,
 *     because there is no server to navigate to inside the bundle.
 *
 * Nothing else about the contract changes. If a screen's JS needs an exception
 * here, that is a smell: the fix belongs in the action file, not in a special case.
 */
window.WnApi = (function () {
    'use strict';

    var TOKEN_KEY = 'wn.device_token';

    function token()          { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
    function setToken(t)      { try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); } catch (e) {} }

    /** Headers every request carries. Bearer, never cookies — see above. */
    function headers() {
        var h = { 'X-Requested-With': 'XMLHttpRequest' };

        var t = token();
        if (t) h['Authorization'] = 'Bearer ' + t;

        // Core identifies a bundled client by this header instead of the wn_device
        // cookie, so the user's device list shows one row for this phone rather than a
        // new one every session.
        if (window.WnLogin) h['X-Wn-Device'] = WnLogin.deviceId();

        return h;
    }

    var USER_KEY = 'wn.user';
    function user()      { try { return JSON.parse(localStorage.getItem(USER_KEY) || '{}'); } catch (e) { return {}; } }
    function setUser(u)  { try { u ? localStorage.setItem(USER_KEY, JSON.stringify(u)) : localStorage.removeItem(USER_KEY); } catch (e) {} }

    return {
        token: token,
        setToken: setToken,
        headers: headers,
        user: user,
        setUser: setUser,
        isAuthed: function () { return token() !== ''; },

        /**
         * Sign out. Revoking server-side is what makes this real — the token is an
         * api_key row, so this is the same revocation as "sign out this device" on the
         * web. The local token and every cached screen go regardless, so a failed
         * network call can never leave the previous user's data on the device.
         */
        logout: function () {
            var done = function () {
                setToken('');
                if (window.WnStore) WnStore.clear();
                location.hash = '#/login';
                if (window.WnRouter) WnRouter.go('#/login');
            };
            if (!token()) return done();
            apiPost('deviceLogout', {}, done);
            setTimeout(done, 2000);   // never strand a user in a signed-in-looking app
        }
    };
})();

/**
 * POST an action to the API. Same signature and semantics as admin's bs-init.js,
 * because the hoisted screen code calls it by that name.
 *
 * @param {string}   action   Action name.
 * @param {object}   data     Key-value pairs. Objects/arrays are JSON-encoded, matching
 *                            what the desktop FormData path sends for nested params.
 * @param {function} callback Called with the parsed JSON envelope on success.
 */
function apiPost(action, data, callback) {
    var body = new FormData();
    body.append('action', action);

    for (var key in data) {
        if (!Object.prototype.hasOwnProperty.call(data, key)) continue;
        var v = data[key];
        if (v === null || v === undefined) continue;
        // FormData stringifies objects to "[object Object]" — encode them properly.
        body.append(key, (typeof v === 'object' && !(v instanceof Blob) && !(v instanceof File))
            ? JSON.stringify(v)
            : v);
    }

    fetch(window.WN_ENV.API_BASE + 'index.php', {
        method: 'POST',
        body: body,
        headers: window.WnApi.headers(),
        // Bundled origin sends Origin: null and has no usable cookie jar; the
        // Bearer token is the credential. Mobile web keeps its session cookie.
        credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json.error && /^Login required\.?$/.test(json.error)) {
                window.WnApi.setToken('');
                if (window.WnRouter) window.WnRouter.toLogin();
                return;
            }
            if (json.error) {
                showAlert('danger', json.error);
            } else if (json.success) {
                showAlert('success', json.success);
            }
            if (callback) callback(json);
        })
        .catch(function (err) {
            // Offline is not an error worth shouting about — the screen is still
            // showing the last good render, and the banner already says so.
            if (!Platform.online()) {
                document.body.classList.add('wn-offline');
                return;
            }
            showAlert('danger', 'Request failed: ' + err.message);
        });
}

/**
 * showAlert — desktop's bs-init.js injects a Bootstrap alert into .container-fluid.
 * There is no such container in the shell, and a phone wants a toast anyway, so we
 * route to the same showToast() the views already use. Keeping the NAME is what
 * matters: the hoisted screen code calls it.
 */
function showAlert(type, message) {
    if (window.showToast) {
        showToast(type, message);
    } else {
        console.warn('[viv] ' + type + ': ' + message);
    }
}

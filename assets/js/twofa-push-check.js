/*
 * twofa-push-check.js — the phone app's half of "Approve on your phone" (task #2662).
 *
 * When a computer is waiting on the 2FA push step (auth/2fa.php, WN_2FA_PUSH), the push opens
 * ?page=webapprove&push=N. A push can be missed (notifications off, swiped away), so the app
 * also asks twofaPushPending whenever it opens, comes back to the front (resume) or finishes
 * signing in, and goes to the number picker if a request is waiting. The screen itself
 * (the app's views/webapprove.php) re-reads the request from the server, so this file only
 * decides WHEN to look; it never approves anything.
 *
 * Only runs in the bundled app (WN_ENV.BUNDLED) and only for a signed-in session; an app
 * without WN_2FA_PUSH answers with no request, so loading it is harmless.
 */
(function (global) {
    'use strict';
    if (global.__wnTwofaPush) { return; }
    global.__wnTwofaPush = true;

    var env = global.WN_ENV || {};
    var busy = false, lastCheck = 0, lastShown = 0;

    function signedIn() {
        return !!(global.WnApi && global.WnApi.isAuthed && global.WnApi.isAuthed());
    }

    function onScreen() {
        return /^#\/?webapprove(\?|$)/.test(global.location.hash || '');
    }

    function check(force) {
        if (!env.BUNDLED || busy || !signedIn() || typeof global.apiPost !== 'function') { return; }
        if (/^#\/?login/.test(global.location.hash || '')) { return; }
        var now = Date.now();
        if (!force && now - lastCheck < 5000) { return; }
        lastCheck = now;
        busy = true;
        global.apiPost('twofaPushPending', {}, function (res) {
            busy = false;
            var r = res && res.results && res.results.request;
            if (!r || !r.id || onScreen()) { return; }
            if (r.id === lastShown && !force) { return; }   // they backed out of it: ask again only on resume
            lastShown = r.id;
            global.location.hash = '#/webapprove?push=' + encodeURIComponent(r.id);
        });
        setTimeout(function () { busy = false; }, 15000);
    }

    function start() { setTimeout(function () { check(true); }, 800); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
    global.addEventListener('hashchange', function () { check(false); });
    document.addEventListener('resume', function () { check(true); }, false);
    global.WnTwofaPush = { check: function () { check(true); } };
})(window);

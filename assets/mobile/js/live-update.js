/**
 * live-update.js — OTA updates of the web layer (Capawesome Live Updates).
 *
 * The dry half of the wet/dry split (spec 05) normally needs a store release: markup
 * travels at runtime, but a view's BEHAVIOR is hoisted into the binary at build time.
 * This file removes that wait — the bundle itself is replaced over the air.
 *
 * It stays inside Apple 2.5.2 for the same reason the fragment path does: the downloaded
 * bundle is served from the SAME app-scheme origin as the baked-in one, so `script-src
 * 'self'` still holds. Nothing is fetched into an eval; the web layer is swapped wholesale
 * and the app restarts into it. Only binary-compatible changes may ship this way — a new
 * plugin or any native change still requires a store build.
 *
 * INERT unless two things are true, so every app can carry it unconditionally:
 *   1. we're on a device — `deviceready` never fires in a browser, so the listener below
 *      is simply never called on the web build (no protocol sniffing needed);
 *   2. the app installed @capawesome/cordova-live-update — apps that haven't get a no-op.
 *
 * CONFIG LIVES IN config.xml, NOT HERE. The shell repo's plugin block carries APP_ID and
 * DEFAULT_CHANNEL; release-mobile.sh rewrites DEFAULT_CHANNEL to match the binary's
 * version every build. Keeping the channel out of the JS is the point — a bundle must
 * never be able to talk itself onto a channel its native layer can't support.
 */
(function () {
    'use strict';

    document.addEventListener('deviceready', function () {
        var LiveUpdate = window.cordova && cordova.plugins && cordova.plugins.LiveUpdate;
        if (!LiveUpdate) { return; }   // plugin not installed — this app isn't on Live Updates

        function report(kind) {
            return function (err) {
                if (window.WnReport) {
                    WnReport.signal('live-update', kind + ': ' + ((err && err.message) || err));
                }
            };
        }

        /**
         * ready() is the anti-brick switch: until it is called, the plugin assumes the
         * bundle it just installed might be broken and rolls back to the last good one
         * after READY_TIMEOUT. So calling it at `deviceready` — before anything has
         * actually rendered — would throw that protection away: a bundle that boots the
         * runtime and then white-screens would mark itself healthy and stick.
         *
         * The honest signal is the first `wn:navigated`, which router.js fires from
         * activate() once a screen is really on screen. It covers both launch paths,
         * because a signed-out device routes to the bundled #/login screen and that
         * activates like any other page.
         *
         * There is deliberately NO timeout fallback here. If no screen ever renders, we
         * WANT ready() to go uncalled so the plugin rolls back — a timer that called it
         * anyway would convert a recoverable bad release into a bricked install.
         */
        /*
         * STALE CODE APPLIES SOONER (contactswipe task #2862). A staged bundle normally waits
         * for the next launch. Two cases cannot wait:
         *   - the app is still on the bundle BAKED into the store binary (getCurrentBundle()
         *     → null: a fresh install). That bake is as old as the store build, and a new user
         *     would spend the whole first session (sign-up, phone sync) on it;
         *   - the router refused a screen as needing a newer build (wn:needs-build).
         * Then the staged bundle is applied at the next screen change, never while a text
         * field has focus, so nothing typed is lost. A signed-in session survives the reload.
         */
        var stale = false, staged = false, applying = false;
        document.addEventListener('wn:needs-build', function () { stale = true; });
        function typing() {
            var a = document.activeElement;
            return !!a && (a.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName));
        }
        function applyIfDue() {
            if (!staged || !stale || applying || typing()) { return; }
            applying = true;
            LiveUpdate.reload().catch(function (err) { applying = false; report('reload')(err); });
        }
        document.addEventListener('wn:navigated', function () { setTimeout(applyIfDue, 0); });

        document.addEventListener('wn:navigated', function () {
            LiveUpdate.ready()
                .then(function () {
                    // Only look for a new bundle once this one has proven it boots.
                    // sync() downloads and stages it; it takes effect on the next launch,
                    // so nothing is yanked out from under the user mid-session.
                    return LiveUpdate.sync();
                })
                .then(function (res) {
                    if (!res || !res.nextBundleId) { return; }
                    staged = true;
                    if (typeof LiveUpdate.getCurrentBundle !== 'function') { applyIfDue(); return; }
                    return LiveUpdate.getCurrentBundle().then(function (cur) {
                        if (!cur || !cur.bundleId) { stale = true; }   // still the store bake
                    }, function () {});
                })
                .catch(report('sync'));
        }, { once: true });
    }, false);
})();

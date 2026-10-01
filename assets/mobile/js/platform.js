/**
 * platform.js — the native adapter (spec 05).
 *
 * UI code never touches window.cordova directly, only Platform.*. That single
 * rule is what keeps one codebase runnable in a browser and on a device.
 *
 * Every capability degrades to a web equivalent, so the mobile web app at
 * the mobile web app is fully usable with no plugins present.
 */
window.Platform = (function () {
    'use strict';

    var ready = false;
    var queue = [];
    var isDevice = typeof window.cordova !== 'undefined';
    var sceneAware = null;   // openInApp: does this binary's in-app browser show on iOS?

    function flush() {
        ready = true;
        queue.forEach(function (fn) { fn(); });
        queue = [];
    }

    if (isDevice) {
        document.addEventListener('deviceready', flush, false);
    } else {
        // No cordova.js (it 404s in a browser — the ghost include). Boot on DOM.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', flush);
        } else {
            setTimeout(flush, 0);
        }
    }

    return {
        isDevice: isDevice,

        /** Run fn once the platform is ready (deviceready, or DOM in a browser). */
        onReady: function (fn) { ready ? fn() : queue.push(fn); },

        /** Open a URL outside the app. In a WebView this MUST leave the WebView. */
        openExternal: function (url) {
            if (isDevice && window.cordova && cordova.InAppBrowser) {
                cordova.InAppBrowser.open(url, '_system');
            } else {
                window.open(url, '_blank', 'noopener');
            }
        },

        /**
         * Open a page to READ and come back from (a policy, a preview): the in-app browser
         * with a Done button where this binary can show it, else the system browser.
         * iOS: stock cordova-plugin-inappbrowser 6.0.0 opens _blank in a window cordova-ios 8
         * never puts on screen, so _blank is used only when the plugin answers 'sceneAware'
         * (the fixed copy newer app builds vendor); older binaries get _system. Android: in-app.
         */
        openInApp: function (url) {
            var cd = window.cordova, iab = isDevice && cd && cd.InAppBrowser && typeof cd.InAppBrowser.open === 'function' ? cd.InAppBrowser : null;
            if (!iab) { window.open(url, '_blank', 'noopener'); return; }
            var ios = cd.platformId === 'ios' || /iPhone|iPad|iPod/.test(navigator.userAgent || '');
            var inApp = function () {
                try { if (iab.open(url, '_blank', 'location=no,toolbar=yes,closebuttoncaption=Done,presentationstyle=pagesheet')) return; } catch (e) {}
                iab.open(url, '_system');
            };
            if (!ios) { inApp(); return; }
            if (sceneAware === null) {
                sceneAware = new Promise(function (res) {
                    if (typeof cd.exec !== 'function') { res(false); return; }
                    var t = setTimeout(function () { res(false); }, 1500);
                    try { cd.exec(function () { clearTimeout(t); res(true); }, function () { clearTimeout(t); res(false); }, 'InAppBrowser', 'sceneAware', []); }
                    catch (e) { clearTimeout(t); res(false); }
                });
            }
            sceneAware.then(function (ok) { if (ok) inApp(); else iab.open(url, '_system'); });
        },

        share: function (text, url) {
            if (navigator.share) {
                return navigator.share({ text: text, url: url }).catch(function () {});
            }
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url || text);
                if (window.showToast) showToast('success', 'Link copied');
            }
        },

        setBadge: function (n) {
            if (isDevice && window.cordova && cordova.plugins && cordova.plugins.notification) {
                cordova.plugins.notification.badge.set(n);
            } else {
                var an=(window.WN_ENV&&WN_ENV.APP_NAME)||'App';document.title = n > 0 ? '(' + n + ') ' + an : an;
            }
        },

        /**
         * The hardware back button — a DEVICE concept only.
         *
         * On the web there is NOTHING to hook: the browser's own back button already
         * walks the hash history and fires hashchange, which the router handles. Hooking
         * popstate here was a real bug — popstate also fires on ordinary forward hash
         * navigation, so every tab tap ran the "go back" handler, popped the stack, and
         * bounced the app to the previous screen. First tap worked, the rest collapsed
         * onto dashboard. Leave web back to the browser.
         */
        onBack: function (fn) {
            if (isDevice) {
                document.addEventListener('backbutton', fn, false);
            }
        },

        online: function () {
            return navigator.onLine !== false;
        }
    };
})();

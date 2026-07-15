/**
 * store.js — the fragment cache and the deviation gauge (spec 05).
 *
 * Two jobs:
 *
 * 1. CACHE. Every fragment the server renders is kept in localStorage, so the app
 *    opens instantly and still works with no network — it shows what it last saw.
 *
 * 2. THE GAUGE. Each fragment arrives stamped with the source hash of the view it
 *    came from and the hash of that view's JS. The bundle carries the hashes it was
 *    BUILT against (manifest.json). Comparing them answers the only question that
 *    matters for releasing:
 *
 *      markup hash differs  → the screen is running WET. Fine. No build needed;
 *                             the user is already seeing the new markup.
 *      js hash differs      → the screen's BEHAVIOR changed, and behavior cannot
 *                             travel over the wire. A store build is REQUIRED, and
 *                             until it ships this device keeps its bundled copy —
 *                             a slightly stale screen beats a live screen whose
 *                             code we do not have.
 *
 * That refusal is not a safety net bolted on afterwards; it is the mechanism that
 * lets us pull markup at all without ever pulling code.
 */
window.WnStore = (function () {
    'use strict';

    var PREFIX = 'wn.frag.';
    var built = null;        // manifest.json — what this binary was built from

    function load(url, cb) {
        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(cb)
            .catch(function () { cb(null); });
    }

    function key(page, params) {
        return PREFIX + page + (params ? '?' + params : '');
    }

    return {
        /** Read the bundled manifest — the hashes this binary was built against. */
        init: function (cb) {
            load('manifest.json', function (m) {
                built = m || { screens: {}, version: '0' };
                cb(built);
            });
        },

        version: function () { return built ? built.version : '0'; },
        screens: function () { return built ? built.screens : {}; },

        /**
         * May this device render the server's markup for `page`?
         *
         * Only if the view's JS is the JS we shipped. Anything else and we would be
         * rendering markup that expects handlers this binary does not contain.
         */
        canRender: function (page, jsHash) {
            var b = built && built.screens[page];
            if (!b) return false;            // screen not in this build at all
            if (!b.js_hash && !jsHash) return true;   // no behavior either side
            return b.js_hash === jsHash;
        },

        put: function (page, params, markup, meta) {
            try {
                localStorage.setItem(key(page, params), JSON.stringify({
                    markup: markup,
                    view_hash: meta.view_hash || '',
                    js_hash: meta.js_hash || '',
                    at: (meta.at || 0)
                }));
            } catch (e) { /* quota — the app still works, it just re-fetches */ }
        },

        get: function (page, params) {
            try {
                var raw = localStorage.getItem(key(page, params));
                return raw ? JSON.parse(raw) : null;
            } catch (e) { return null; }
        },

        clear: function () {
            try {
                Object.keys(localStorage)
                    .filter(function (k) { return k.indexOf(PREFIX) === 0; })
                    .forEach(function (k) { localStorage.removeItem(k); });
            } catch (e) {}
        },

        /**
         * The release gauge, for the admin dashboard and for us:
         *   wet          — screens whose markup has moved on (harmless, expected)
         *   needs_build  — screens whose behavior has moved on (a release is due)
         */
        deviation: function () {
            var wet = [], needsBuild = [];
            var b = built ? built.screens : {};

            Object.keys(b).forEach(function (page) {
                var seen = null;
                try {
                    // Any cached render of this page, params or not.
                    var k = Object.keys(localStorage).filter(function (x) {
                        return x === PREFIX + page || x.indexOf(PREFIX + page + '?') === 0;
                    })[0];
                    if (k) seen = JSON.parse(localStorage.getItem(k));
                } catch (e) {}
                if (!seen) return;

                if (seen.js_hash && seen.js_hash !== b[page].js_hash) needsBuild.push(page);
                else if (seen.view_hash && seen.view_hash !== b[page].view_hash) wet.push(page);
            });

            return {
                version: built ? built.version : '0',
                wet: wet,
                needs_build: needsBuild,
                missing_handlers: window.WnDispatch ? WnDispatch.missing() : []
            };
        }
    };
})();

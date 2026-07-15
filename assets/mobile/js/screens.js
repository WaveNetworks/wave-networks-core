/**
 * screens.js — when a screen's code runs (spec 05).
 *
 * A view's <script> was written to run AFTER that view's markup, in the same
 * document — that is what a page load gives it, and what desktop SPA nav
 * reproduces by re-executing the script each time it swaps the content in.
 *
 * The bundle cannot honour that by accident: it loads all 13 screen files at boot,
 * when no screen markup exists. Run them then and ledger.js reaches for
 * #ledgerNext, gets null, and throws before the app has drawn a pixel.
 *
 * So the generated screen files do not execute their body at load. They hand it to
 * this registry, and the router runs it right after that screen's markup lands —
 * once per render, exactly like a page load. Same code, same moment.
 */
window.WnScreens = (function () {
    'use strict';

    var inits = {};

    return {
        /** Called by each generated js/screens/<page>.js at load time. */
        define: function (page, init) {
            inits[page] = init;
        },

        /**
         * Run a screen's code, now that its markup is in the DOM.
         *
         * Re-running on every render is deliberate, not sloppy: it is what the
         * desktop does on every navigation, so the views' scripts are already
         * written to tolerate it (they re-query the DOM and re-bind their own
         * listeners). A screen that could not survive being re-initialised would
         * already be broken on the web.
         */
        init: function (page) {
            var fn = inits[page];
            if (!fn) return;
            try {
                fn();
            } catch (e) {
                // One screen's script must not take down the shell.
                console.error('[viv] screen "' + page + '" failed to initialise:', e);
            }
        },

        has: function (page) { return !!inits[page]; }
    };
})();

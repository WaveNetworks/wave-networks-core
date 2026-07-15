/**
 * dispatch.js — the handler dispatcher (spec 05).
 *
 * The views write inline handlers: onclick="vivRsvp(42, 'yes', this)". Those
 * cannot survive the trip to a device — an inline handler is code, the CSP blocks
 * it, and shipping code over the wire is exactly what Apple 2.5.2 forbids.
 *
 * So the splitter rewrites them into data:
 *
 *   onclick="vivRsvp(42,'yes',this)"
 *     → data-on="click" data-act="vivRsvp" data-args='[42,"yes",{"__wn":"el"}]'
 *
 * and this file calls them. The crucial property: the name is looked up in a
 * registry of functions that ALREADY EXIST IN THE BUNDLE. Nothing is eval'd, no
 * Function constructor, no string from the server ever becomes code. A fragment
 * can name a handler; it cannot supply one. If the name is unknown — because the
 * server's markup is newer than this binary — the click does nothing and we log
 * it, which is the honest failure: an old app should not half-run a new screen.
 */
window.WnDispatch = (function () {
    'use strict';

    /** page → { fnName: fn } — populated by the generated js/screens/*.js. */
    var screens = {};

    /** Handlers whose name the markup asked for but nothing in the bundle provides. */
    var missing = {};

    /**
     * Resolve a handler name for an element, in the order that keeps screens honest:
     *   1. the screen the element belongs to (its own JS)
     *   2. any other screen's JS  (dinner.js declares vivAct; circle's markup calls it)
     *   3. the global chrome      (showToast, modalView, apiPost…)
     */
    function resolve(name, page) {
        if (page && screens[page] && typeof screens[page][name] === 'function') {
            return screens[page][name];
        }
        for (var p in screens) {
            if (screens[p] && typeof screens[p][name] === 'function') return screens[p][name];
        }
        if (typeof window[name] === 'function') return window[name];
        return null;
    }

    /** Substitute the sentinels the splitter emitted for things that are not data. */
    function bind(args, el) {
        return (args || []).map(function (a) {
            if (a && typeof a === 'object' && a.__wn === 'el') return el; // `this`
            return a;
        });
    }

    function run(el, evt) {
        var name = el.getAttribute('data-act');
        var page = el.closest('[data-screen]');
        page = page ? page.getAttribute('data-screen') : null;

        var confirmMsg = el.getAttribute('data-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            if (evt) evt.preventDefault();   // a confirm on a form guards the submit
            return;
        }

        // data-confirm with no data-act guards a native submit and nothing more.
        if (!name) return;

        if (el.getAttribute('data-prevent') === '1' && evt) evt.preventDefault();

        var fn = resolve(name, page);
        if (!fn) {
            // The markup is asking for behavior this binary does not have. Do not
            // guess, do not eval — record it. This is the deviation gauge's
            // sharpest signal: a screen whose JS moved on without a store build.
            missing[name] = (missing[name] || 0) + 1;
            console.warn('[viv] no handler "' + name + '" in this build — a release is due');
            if (window.WnReport) WnReport.signal('no-handler', name);
            return;
        }

        var args = [];
        try {
            args = JSON.parse(el.getAttribute('data-args') || '[]');
        } catch (e) {
            console.warn('[viv] bad data-args on', el);
        }

        fn.apply(el, bind(args, el));
    }

    // One delegated listener per event type, on the document. Fragments are swapped
    // in and out constantly; delegation means nothing has to be re-bound on render.
    ['click', 'change', 'input', 'submit'].forEach(function (type) {
        document.addEventListener(type, function (e) {
            var el = e.target.closest('[data-on="' + type + '"]');
            if (el) run(el, e);
        }, false);
    });

    return {
        /** Called by each generated js/screens/<page>.js at load time. */
        register: function (page, fns) {
            screens[page] = fns || {};
        },

        /** Handler names the markup wanted and this build could not supply. */
        missing: function () { return Object.keys(missing); }
    };
})();

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

    /**
     * The confirm sheet: the message, Cancel, and a yes button worded from the thing
     * being confirmed (a "Delete" button's yes says Delete). Styled in place through
     * the CSSOM, so it needs no stylesheet and passes the CSP.
     */
    function ask(msg, el, yes) {
        var old = document.getElementById('wnConfirm');
        if (old) old.remove();
        var src = el.tagName === 'FORM' ? el.querySelector('button[type="submit"], button:not([type])') : el;
        var label = ((src && src.textContent) || '').replace(/\s+/g, ' ').trim();
        if (label.length > 16) label = label.split(' ')[0];   // "Delete this landing page" → Delete
        if (!label || label.length > 16) label = 'OK';
        var danger = !!(src && /danger/.test(src.className || '')) || /delete|remove/i.test(label);

        var wrap = document.createElement('div');
        wrap.id = 'wnConfirm';
        wrap.setAttribute('role', 'alertdialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.45);'
            + 'display:flex;align-items:flex-end;justify-content:center;';
        var box = document.createElement('div');
        box.style.cssText = 'background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529);width:100%;'
            + 'max-width:520px;border-radius:16px 16px 0 0;padding:20px 16px calc(16px + env(safe-area-inset-bottom));'
            + 'box-shadow:0 -4px 24px rgba(0,0,0,.25);';
        var p = document.createElement('p');
        p.textContent = msg;
        p.style.cssText = 'margin:0 0 16px;font-size:1rem;line-height:1.4;';
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:10px;';
        var no = document.createElement('button');
        no.type = 'button';
        no.className = 'btn btn-outline-secondary';
        no.setAttribute('data-wn-confirm', 'no');
        no.textContent = 'Cancel';
        var ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        ok.setAttribute('data-wn-confirm', 'yes');
        ok.textContent = label;
        [no, ok].forEach(function (b) { b.style.cssText = 'flex:1;min-height:48px;'; });
        row.appendChild(no); row.appendChild(ok);
        box.appendChild(p); box.appendChild(row); wrap.appendChild(box);

        var close = function () { wrap.remove(); };
        no.addEventListener('click', close);
        wrap.addEventListener('click', function (e) { if (e.target === wrap) close(); });
        ok.addEventListener('click', function () { close(); yes(); });
        document.body.appendChild(wrap);
        ok.focus();
    }

    function run(el, evt) {
        var name = el.getAttribute('data-act');
        var page = el.closest('[data-screen]');
        page = page ? page.getAttribute('data-screen') : null;

        // The question is asked in the app, not with window.confirm: a WebView's native
        // dialog is not guaranteed (an iPhone "Delete this landing page" did nothing, task
        // #2381). The event is held, and a yes replays it with the guard already passed.
        var confirmMsg = el.getAttribute('data-confirm');
        if (confirmMsg && !el.__wnConfirmed) {
            if (evt) evt.preventDefault();
            var submitter = evt && evt.submitter;
            ask(confirmMsg, el, function () {
                el.__wnConfirmed = true;
                try {
                    if (evt && evt.type === 'submit' && el.tagName === 'FORM') {
                        if (el.requestSubmit) el.requestSubmit(submitter && submitter.form === el ? submitter : undefined);
                        else el.submit();
                    } else if (evt && evt.type === 'click') {
                        el.click();
                    } else {
                        run(el, null);
                    }
                } finally {
                    el.__wnConfirmed = false;
                }
            });
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

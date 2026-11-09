/**
 * wn-i18n.js — translation for JavaScript, the partner of wn_t() (include/common/i18nFunctions.php).
 *
 *     el.textContent = wnT('people.count', '{count} people', {count: n});
 *
 * wnT() returns plain text: use textContent, or escape it. It never throws and never waits. With
 * no catalog it returns the English default, so a page or a phone offline on first launch simply
 * shows English. The last catalog is kept in localStorage, so wnT() has it on the first paint and
 * offline; a refresh then runs in the background, and when the strings change document gets a
 * 'wn:i18n' event so a screen can redraw.
 *
 * Locale: wn_locale cookie (localStorage on the phone, which has no cookie), then the device's
 * languages. i18n.php answers with the first published one and has already applied the
 * pt-BR → pt fallback, so this file holds one flat map. A language taken off the app answers 404
 * and its cached copy is dropped. English-first devices make no request at all, unless they hold a
 * tester's preview code (wn_locale_preview cookie or localStorage; wnI18n.setPreview), which asks
 * for that language's unpublished catalog.
 *
 * Plurals: {n, plural, ...} is formatted with Intl.PluralRules. Other ICU forms (select, number,
 * date, offset:) and ICU apostrophe quoting are not handled here: that message shows the English
 * default, as wn_t() does on a host without intl.
 *
 * Endpoint: window.WN_I18N_URL, else from WN_ENV.API_BASE on the phone, else beside this script.
 */
(function (global) {
    'use strict';
    if (global.wnI18n) { return; }

    var CACHE = 'wn_i18n_catalog', PICK = 'wn_locale', TTL = 15 * 60 * 1000, TAG = /^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/;
    var ICU = /\{\s*\w+\s*,\s*(plural|select|selectordinal|number|date|time)\b/;
    var doc = global.document;
    var state = {locale: 'en', dir: 'ltr', strings: {}};
    var has = function (o, k) { return Object.prototype.hasOwnProperty.call(o, k); };
    var scriptSrc = (doc && doc.currentScript && doc.currentScript.src) || '';
    var turn = 0;

    function store(method, key, value) {
        try { return global.localStorage[method](key, value); } catch (e) { return null; }
    }

    function fill(message, params) {
        return message.replace(/\{([^{}]+)\}/g, function (all, name) {
            if (!has(params, name)) { return all; }
            var v = params[name];
            if (v === null || v === undefined || v === false) { return ''; }
            if (v === true) { return '1'; }
            return typeof v === 'object' || typeof v === 'function' ? all : String(v);
        });
    }

    /** The index of the } closing the { at start, or -1. */
    function closing(s, start) {
        for (var i = start, depth = 0; i < s.length; i++) {
            if (s[i] === '{') { depth++; } else if (s[i] === '}' && --depth === 0) { return i; }
        }
        return -1;
    }

    /** selector => message for the options of a plural, or null when they do not parse. */
    function options(s) {
        var out = {}, i = 0, m, end;
        while (i < s.length) {
            m = /^\s*(=\d+|[a-z]+)\s*\{/.exec(s.slice(i));
            if (!m) { return /^\s*$/.test(s.slice(i)) ? out : null; }
            end = closing(s, i + m[0].length - 1);
            if (end < 0) { return null; }
            out[m[1]] = s.slice(i + m[0].length, end);
            i = end + 1;
        }
        return out;
    }

    function plural(n, opts, params, locale) {
        if (typeof n !== 'number' || !isFinite(n)) { return null; }
        var pick = has(opts, '=' + n) ? opts['=' + n] : null;
        if (pick === null) {
            var cat = new Intl.PluralRules(locale).select(n);
            pick = has(opts, cat) ? opts[cat] : (has(opts, 'other') ? opts.other : null);
        }
        if (pick === null) { return null; }
        var number = new Intl.NumberFormat(locale).format(n);
        return icu(pick, params, locale, number);
    }

    function icu(message, params, locale, hash) {
        var out = '', i = 0, end, inner, m, opts, part;
        while (i < message.length) {
            var c = message[i];
            if (c === '}') { return null; }
            if (c === '#' && hash !== undefined) { out += hash; i++; continue; }
            if (c !== '{') { out += c; i++; continue; }
            end = closing(message, i);
            if (end < 0) { return null; }
            inner = message.slice(i + 1, end);
            m = /^\s*(\w+)\s*,\s*plural\s*,/.exec(inner);
            if (m) {
                opts = options(inner.slice(m[0].length));
                part = opts ? plural(has(params, m[1]) ? params[m[1]] : undefined, opts, params, locale) : null;
                if (part === null) { return null; }
                out += part;
            } else if (inner.indexOf(',') >= 0 || inner.indexOf('{') >= 0) {
                return null;
            } else {
                out += fill('{' + inner.trim() + '}', params);
            }
            i = end + 1;
        }
        return out;
    }

    /** The finished text, or null when ICU syntax cannot be formatted here. */
    function format(message, params, locale) {
        message = String(message);
        if (!ICU.test(message)) { return fill(message, params); }
        if (typeof Intl === 'undefined' || !Intl.PluralRules || message.indexOf("'") >= 0) { return null; }
        try { return icu(message, params, locale); } catch (e) { return null; }
    }

    function wnT(key, def, params) {
        var english = def === null || def === undefined ? '' : String(def);
        try {
            params = params && typeof params === 'object' ? params : {};
            if (has(state.strings, key) && typeof state.strings[key] === 'string' && state.strings[key] !== '') {
                var out = format(state.strings[key], params, state.locale);
                if (out !== null) { return out; }
            }
            var plain = format(english, params, 'en');
            return plain !== null ? plain : english;
        } catch (e) {
            return english;
        }
    }

    function cookie(name) {
        try {
            var m = new RegExp('(?:^|;\\s*)' + (name || 'wn_locale') + '=([^;]*)').exec(doc.cookie || '');
            return m ? decodeURIComponent(m[1]) : '';
        } catch (e) { return ''; }
    }

    /** A tester's preview code, 'es.<hex>', or ''. */
    function previewCode() {
        var c = cookie('wn_locale_preview') || store('getItem', 'wn_locale_preview') || '';
        return /^[A-Za-z0-9-]+\.[0-9a-f]{32}$/.test(c) ? c : '';
    }

    /** Valid tags, most wanted first. */
    function wanted() {
        var nav = global.navigator || {}, langs = nav.languages && nav.languages.length ? nav.languages : [nav.language];
        var list = [cookie(), store('getItem', PICK)].concat(Array.prototype.slice.call(langs)), out = [];
        list.forEach(function (tag) {
            tag = String(tag || '').replace(/_/g, '-');
            if (TAG.test(tag) && out.indexOf(tag) < 0 && out.length < 8) { out.push(tag); }
        });
        return out;
    }

    function endpoint() {
        if (global.WN_I18N_URL) { return global.WN_I18N_URL; }
        var env = global.WN_ENV || {};
        if (env.BUNDLED && env.API_BASE) { return String(env.API_BASE).replace(/\/[^\/]+\/api\/?$/, '/admin/i18n.php'); }
        return scriptSrc ? scriptSrc.replace(/assets\/js\/wn-i18n\.js.*$/, 'i18n.php') : '';
    }

    function use(next) {
        var before = JSON.stringify([state.locale, state.strings]);
        state = {locale: next.locale || 'en', dir: next.dir === 'rtl' ? 'rtl' : 'ltr', strings: next.strings || {}};
        if (before === JSON.stringify([state.locale, state.strings]) || !doc || !doc.dispatchEvent) { return; }
        try { doc.dispatchEvent(new global.CustomEvent('wn:i18n', {detail: {locale: state.locale}})); } catch (e) {}
    }

    function remember(want, data) {
        store('setItem', CACHE, JSON.stringify({wanted: want, at: Date.now(), locale: data.locale, dir: data.dir, strings: data.strings}));
    }

    /** Bring the catalog up to date. Resolves either way: a failed refresh keeps what is showing. */
    function load(force) {
        var pv = previewCode(), list = pv ? [pv.split('.')[0]] : wanted(), want = list.join(',') + (pv ? '|preview' : ''), mine = ++turn, url = endpoint(), cached = null;
        if (!pv && (!list.length || /^en(-|$)/i.test(list[0]))) {
            store('removeItem', CACHE);
            use({});
            return Promise.resolve();
        }
        try { cached = JSON.parse(store('getItem', CACHE) || 'null'); } catch (e) {}
        var usable = cached && cached.wanted === want && cached.strings && typeof cached.strings === 'object';
        use(usable ? cached : {});
        if (!url || !global.fetch || (usable && !force && Date.now() - cached.at < TTL)) { return Promise.resolve(); }
        return global.fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'locale=' + encodeURIComponent(list.join(',')) + (pv ? '&preview=' + encodeURIComponent(pv) : ''), {credentials: 'omit', cache: pv ? 'no-store' : 'no-cache'})
            .then(function (res) {
                if (res.status === 400 || res.status === 404) { return {}; }
                return res.ok ? res.json() : null;
            })
            .then(function (data) {
                if (mine !== turn || !data || typeof data !== 'object') { return; }
                var clean = {locale: data.locale || 'en', dir: data.dir, strings: data.strings && typeof data.strings === 'object' ? data.strings : {}};
                remember(want, clean);
                use(clean);
            })
            .catch(function () {});
    }

    /** The person's own choice. '' goes back to the device's language. */
    function setLocale(tag) {
        tag = String(tag || '').replace(/_/g, '-');
        if (tag !== '' && !TAG.test(tag)) { return Promise.resolve(); }
        if (tag === '') { store('removeItem', PICK); } else { store('setItem', PICK, tag); }
        try {
            doc.cookie = 'wn_locale=' + encodeURIComponent(tag) + '; path=/; SameSite=Lax; max-age=' + (tag === '' ? 0 : 31536000);
        } catch (e) {}
        return load(true);
    }

    /** A tester's preview code ('' ends the preview). The phone has no cookie from the preview link. */
    function setPreview(code) {
        if (code) { store('setItem', 'wn_locale_preview', String(code)); } else { store('removeItem', 'wn_locale_preview'); }
        return load(true);
    }

    global.wnT = wnT;
    global.wnI18n = {
        locale: function () { return state.locale; },
        dir: function () { return state.dir; },
        wanted: wanted,
        load: load,
        setLocale: setLocale,
        setPreview: setPreview,
        ready: null
    };
    global.wnI18n.ready = load(false);
})(typeof window !== 'undefined' ? window : this);

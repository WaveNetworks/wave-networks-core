#!/usr/bin/env node
/**
 * scripts/i18n-js-probe.js — the JavaScript half of translation: assets/js/wn-i18n.js and i18n.php.
 *
 * Runs wn-i18n.js in a stub browser with a fake fetch: no catalog means English and no request for
 * an English device; the cache works offline; a language taken off the app goes; a hostile cookie
 * is ignored; a bad translation shows the English default. Then serves the real i18n.php through
 * `php -S` and checks status, ETag, CORS for the phone's origins, and refusal of hostile locales.
 *
 * Usage: node scripts/i18n-js-probe.js    Exit 0 clean, 1 on a finding. No DB, no network.
 */
const fs = require('fs'), os = require('os'), path = require('path'), vm = require('vm'), http = require('http');
const { spawn } = require('child_process');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/wn-i18n.js'), 'utf8');
let fail = 0, n = 0;
function check(label, got, want) {
    n++;
    const a = JSON.stringify(got), b = JSON.stringify(want);
    if (a === b) { console.log('ok   ' + label); return; }
    fail++;
    console.log(`FAIL ${label}: got ${a}, want ${b}`);
}

/** Load wn-i18n.js in a fresh stub browser. */
function browser(opts = {}) {
    const items = new Map(Object.entries(opts.storage || {}));
    const calls = [], events = [];
    const w = {
        navigator: { language: opts.lang || 'es-MX', languages: opts.langs },
        document: {
            cookie: opts.cookie || '',
            dispatchEvent: e => { events.push(e.type); return true; },
        },
        localStorage: opts.noStorage ? null : {
            getItem: k => (items.has(k) ? items.get(k) : null),
            setItem: (k, v) => { items.set(k, String(v)); },
            removeItem: k => { items.delete(k); },
        },
        CustomEvent: function (t) { this.type = t; },
        WN_ENV: opts.env,
        WN_I18N_URL: opts.env ? undefined : 'https://app.test/admin/i18n.php',
        fetch: (url, init) => {
            calls.push({ url, init });
            return opts.fetch ? opts.fetch(url) : Promise.reject(new Error('offline'));
        },
    };
    if (opts.noFetch) delete w.fetch;
    w.window = w;
    const ctx = vm.createContext(w);
    ctx.Intl = Intl; ctx.Promise = Promise; ctx.JSON = JSON; ctx.Date = Date;
    vm.runInContext(source, ctx);
    return { w, calls, events, items };
}
const answer = (status, body) => () => Promise.resolve({ status, ok: status < 400, json: () => Promise.resolve(body) });
const cached = (over = {}) => JSON.stringify(Object.assign({
    wanted: 'es-MX', at: Date.now(), locale: 'es', dir: 'ltr',
    strings: {
        greet: 'Hola {name}',
        people: '{n, plural, =0 {nadie} one {# persona} other {# personas}}',
        mixed: '{who} tiene {n, plural, one {# amigo} other {# amigos}}',
        broken: '{n, plural, one {',
        noother: '{n, plural, one {# x}}',
        select: '{g, select, a {A} other {B}}',
        quoted: "{n, plural, other {l'ami}}",
        blank: '',
    },
}, over));

async function client() {
    // Nothing published anywhere.
    let b = browser({ lang: 'en-US' });
    await b.w.wnI18n.ready;
    check('english device: no request', b.calls.length, 0);
    check('english device: default text', b.w.wnT('a.b', 'Hello {name}', { name: 'Ann' }), 'Hello Ann');
    check('english device: locale', b.w.wnI18n.locale(), 'en');

    b = browser({ noStorage: true, noFetch: true });
    await b.w.wnI18n.ready;
    check('no storage or fetch: english, no throw', b.w.wnT('a.b', 'Hello'), 'Hello');

    b = browser();
    await b.w.wnI18n.ready;
    check('first launch offline: english', b.w.wnT('greet', 'Hello'), 'Hello');
    check('first launch offline: one attempt', b.calls.length, 1);
    check('request carries no cookies', b.calls[0].init.credentials, 'omit');
    check('browser revalidates, no manual header', [b.calls[0].init.cache, b.calls[0].init.headers], ['no-cache', undefined]);
    check('request asks by tag', b.calls[0].url, 'https://app.test/admin/i18n.php?locale=es-MX');

    // A cached catalog: offline, first paint.
    b = browser({ storage: { wn_i18n_catalog: cached({ at: 0 }) } });
    check('cache: available before any network', b.w.wnT('greet', 'Hello {name}', { name: 'Ann' }), 'Hola Ann');
    await b.w.wnI18n.ready;
    check('cache: stale copy survives offline', b.w.wnT('greet', 'Hello {name}', { name: 'Ann' }), 'Hola Ann');
    check('cache: stale copy is refreshed', b.calls.length, 1);
    b = browser({ storage: { wn_i18n_catalog: cached() } });
    await b.w.wnI18n.ready;
    check('cache: fresh copy makes no request', b.calls.length, 0);
    b = browser({ storage: { wn_i18n_catalog: cached({ wanted: 'de' }) } });
    check('cache: another language is not used', b.w.wnT('greet', 'Hello'), 'Hello');
    b = browser({ storage: { wn_i18n_catalog: '{not json' } });
    await b.w.wnI18n.ready;
    check('cache: corrupt copy is english', b.w.wnT('greet', 'Hello'), 'Hello');

    // Formatting.
    b = browser({ storage: { wn_i18n_catalog: cached() } });
    const t = b.w.wnT;
    check('plural exact', t('people', 'x', { n: 0 }), 'nadie');
    check('plural one', t('people', 'x', { n: 1 }), '1 persona');
    check('plural other', t('people', 'x', { n: 3 }), '3 personas');
    check('plural beside a param', t('mixed', 'x', { who: 'Ann', n: 2 }), 'Ann tiene 2 amigos');
    check('english plural default', t('nope', '{n, plural, one {# item} other {# items}}', { n: 2 }), '2 items');
    check('plural without a number: default', t('people', 'People', {}), 'People');
    check('broken translation: default', t('broken', 'Hello', { n: 1 }), 'Hello');
    check('no other branch: default', t('noother', 'Hello', { n: 3 }), 'Hello');
    check('select is not handled: default', t('select', 'Hello', { g: 'a' }), 'Hello');
    check('ICU quoting is not handled: default', t('quoted', 'Hello', { n: 1 }), 'Hello');
    check('blank translation: default', t('blank', 'Hello'), 'Hello');
    check('missing key: default', t('nope', 'Hello'), 'Hello');
    check('inherited key is not a translation', t('constructor', 'Hello'), 'Hello');
    check('param text is not re-read', t('greet', 'x', { name: '{name}$&' }), 'Hola {name}$&');
    check('unknown param stays', t('nope', 'Hi {who}'), 'Hi {who}');
    check('object param stays', t('nope', 'Hi {who}', { who: {} }), 'Hi {who}');
    check('null default', t('nope', null), '');
    check('non-object params', t('greet', 'x', 'nope'), 'Hola {name}');

    // Fetching.
    b = browser({ fetch: answer(200, { locale: 'es', dir: 'ltr', strings: { greet: 'Hola' } }) });
    await b.w.wnI18n.ready;
    check('fetched catalog is used', [b.w.wnT('greet', 'Hello'), b.w.wnI18n.locale()], ['Hola', 'es']);
    check('a screen hears about it', b.events, ['wn:i18n']);
    check('fetched catalog is kept', JSON.parse(b.items.get('wn_i18n_catalog')).strings, { greet: 'Hola' });

    b = browser({ storage: { wn_i18n_catalog: cached({ at: 0 }) }, fetch: answer(404, {}) });
    await b.w.wnI18n.ready;
    check('unpublished: back to english', [b.w.wnT('greet', 'Hello'), b.w.wnI18n.locale()], ['Hello', 'en']);
    check('unpublished: remembered, no retry each page', JSON.parse(b.items.get('wn_i18n_catalog')).strings, {});

    b = browser({ storage: { wn_i18n_catalog: cached({ at: 0 }) }, fetch: answer(500, {}) });
    await b.w.wnI18n.ready;
    check('server error keeps the catalog', b.w.wnT('greet', 'Hi'), 'Hola {name}');

    b = browser({ storage: { wn_i18n_catalog: cached({ at: 0 }) }, fetch: answer(200, { strings: 'nope' }) });
    await b.w.wnI18n.ready;
    check('malformed answer: english, no throw', b.w.wnT('greet', 'Hi'), 'Hi');

    // Choosing the locale.
    b = browser({ cookie: 'x=1; wn_locale=pt-BR', langs: ['es', 'fr'] });
    check('cookie then device', b.w.wnI18n.wanted(), ['pt-BR', 'es', 'fr']);
    await b.w.wnI18n.ready;
    b = browser({ cookie: 'wn_locale=../../secret', langs: ['es'] });
    check('hostile cookie ignored', b.w.wnI18n.wanted(), ['es']);
    await b.w.wnI18n.ready;
    check('hostile cookie never sent', b.calls[0].url.indexOf('secret'), -1);
    b = browser({ storage: { wn_locale: 'nl' }, langs: ['es'] });
    check('the phone keeps a choice without a cookie', b.w.wnI18n.wanted(), ['nl', 'es']);
    await b.w.wnI18n.ready;

    let answered = 0;
    b = browser({ lang: 'fr', fetch: () => { answered++; return answer(200, { locale: 'de', strings: { greet: 'Hallo' } })(); } });
    await b.w.wnI18n.ready;
    await b.w.wnI18n.setLocale('de');
    check('a choice loads now', [b.w.wnT('greet', 'Hello'), answered, b.w.document.cookie.split(';')[0]], ['Hallo', 2, 'wn_locale=de']);
    await b.w.wnI18n.setLocale('../x');
    check('a hostile choice changes nothing', b.items.get('wn_locale'), 'de');

    // A slow old answer must not replace a newer choice.
    let release;
    let first = true;
    b = browser({ lang: 'fr', fetch: () => {
        if (first) { first = false; return new Promise(r => { release = () => r({ status: 200, ok: true, json: () => Promise.resolve({ locale: 'fr', strings: { greet: 'Salut' } }) }); }); }
        return answer(200, { locale: 'de', strings: { greet: 'Hallo' } })();
    } });
    await b.w.wnI18n.setLocale('de');
    release();
    await b.w.wnI18n.ready;
    check('late answer is dropped', b.w.wnT('greet', 'Hello'), 'Hallo');

    // Where it asks.
    b = browser({ env: { BUNDLED: true, API_BASE: 'https://contactsweep.com/contactsweep/api/' } });
    await b.w.wnI18n.ready;
    check('phone asks the server, not the bundle', b.calls[0].url, 'https://contactsweep.com/admin/i18n.php?locale=es-MX');
}

function get(port, url, headers = {}, method = 'GET') {
    return new Promise((resolve, reject) => {
        const req = http.request({ host: '127.0.0.1', port, path: url, headers, method }, res => {
            let body = '';
            res.on('data', d => { body += d; });
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
        });
        req.on('error', reject);
        req.end();
    });
}

async function server() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'i18n-js-probe-'));
    const cat = path.join(dir, 'catalogs');
    fs.mkdirSync(cat);
    fs.writeFileSync(path.join(cat, 'es.json'), JSON.stringify({ greet: 'Hola' }));
    fs.writeFileSync(path.join(dir, 'outside.json'), JSON.stringify({ greet: 'leak' }));
    const prepend = path.join(dir, 'prepend.php');
    fs.writeFileSync(prepend, `<?php define('WN_I18N_DIR', ${JSON.stringify(cat)});`);
    const port = 18000 + (process.pid % 2000);
    const php = spawn('php', ['-d', 'auto_prepend_file=' + prepend, '-d', 'display_errors=0', '-S', '127.0.0.1:' + port, '-t', root], { stdio: 'ignore' });
    try {
        let up = false;
        for (let i = 0; i < 50 && !up; i++) {
            await new Promise(r => setTimeout(r, 100));
            up = await get(port, '/i18n.php?locale=es').then(() => true, () => false);
        }
        check('php server started', up, true);
        const ok = await get(port, '/i18n.php?locale=fr,es');
        check('200 first published', [ok.status, JSON.parse(ok.body)], [200, { locale: 'es', dir: 'ltr', strings: { greet: 'Hola' } }]);
        check('json', ok.headers['content-type'], 'application/json; charset=utf-8');
        check('revalidated, never stale', ok.headers['cache-control'], 'public, max-age=0, must-revalidate');
        check('no session cookie', ok.headers['set-cookie'], undefined);
        const same = await get(port, '/i18n.php?locale=es', { 'If-None-Match': ok.headers.etag });
        check('304 with no body', [same.status, same.body], [304, '']);
        const changed = await get(port, '/i18n.php?locale=es', { 'If-None-Match': '"old"' });
        check('old etag gets the catalog', changed.status, 200);
        check('unpublished 404', (await get(port, '/i18n.php?locale=fr')).status, 404);
        check('404 is not cached', (await get(port, '/i18n.php?locale=fr')).headers['cache-control'], 'no-store');
        check('no locale 400', (await get(port, '/i18n.php')).status, 400);
        for (const bad of ['../outside', '..%2Foutside', 'es%00', 'es.json', '%2Fetc%2Fpasswd']) {
            const r = await get(port, '/i18n.php?locale=' + bad);
            check('hostile locale ' + bad, [r.status, r.body.indexOf('leak')], [400, -1]);
        }
        for (const origin of ['https://localhost', 'app://localhost', 'null']) {
            const r = await get(port, '/i18n.php?locale=es', { Origin: origin });
            check('phone origin allowed ' + origin, r.headers['access-control-allow-origin'], origin);
        }
        const evil = await get(port, '/i18n.php?locale=es', { Origin: 'https://evil.test' });
        check('other origin gets no CORS', evil.headers['access-control-allow-origin'], undefined);
        check('cache varies by origin', evil.headers.vary, 'Origin');
        check('never credentialed', (await get(port, '/i18n.php?locale=es', { Origin: 'https://localhost' })).headers['access-control-allow-credentials'], undefined);
        check('preflight', (await get(port, '/i18n.php?locale=es', { Origin: 'https://localhost' }, 'OPTIONS')).status, 204);
        check('post refused', (await get(port, '/i18n.php?locale=es', {}, 'POST')).status, 405);
    } finally {
        php.kill();
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

(async () => {
    await client();
    await server();
    console.log(fail ? `${fail} of ${n} FAILED` : `${n} passed`);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });

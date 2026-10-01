/*
 * legal-notice.js — the one-time "we've updated our terms" notice, web and phone app.
 *
 * When a Privacy Policy or Terms of Service version published with "requires re-acceptance"
 * takes effect, a signed-in person sees this once: what changed (the summary), a link to the
 * full text, and Accept. Accepting records exactly the versions shown (acceptLegalUpdate,
 * include/actions/memberActions/legalActions.php); if the text changed meanwhile the server
 * refuses and the notice reloads with the newer version.
 *
 * Uses apiPost(): bs-init.js on the web, the device engine's api.js (Bearer) in the app, so
 * the same file works in both. In the app the check also runs after sign-in and on resume.
 * Needs Bootstrap's Modal (both shells load bootstrap.bundle).
 */
(function (global) {
    'use strict';
    if (global.__wnLegalNotice) { return; }
    global.__wnLegalNotice = true;

    var env = global.WN_ENV || {};
    var busy = false, shown = false, lastCheck = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function signedIn() {
        if (env.BUNDLED) { return !!(global.WnApi && global.WnApi.isAuthed && global.WnApi.isAuthed()); }
        return true;   // the web template that loads this is only served to a session
    }

    function openUrl(url) {
        if (global.Platform && typeof global.Platform.openExternal === 'function' && env.BUNDLED) {
            global.Platform.openExternal(url);
        } else {
            global.open(url, '_blank', 'noopener');
        }
    }

    function fmtDate(d) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || '');
        if (!m) { return d || ''; }
        var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        return parseInt(m[3], 10) + ' ' + months[parseInt(m[2], 10) - 1] + ' ' + m[1];
    }

    function show(pending) {
        if (shown || !pending || !pending.length || !global.bootstrap || !global.bootstrap.Modal) { return; }
        shown = true;
        var names = pending.map(function (p) { return p.title; }).join(' and ');
        var items = pending.map(function (p) {
            return '<div class="wn-legal-item border rounded p-3 mb-2">'
                + '<div class="d-flex justify-content-between align-items-baseline gap-2">'
                + '<strong>' + esc(p.title) + '</strong>'
                + '<span class="small text-nowrap" style="opacity:.75">Version ' + esc(p.version_label) + '</span></div>'
                + '<div class="small mb-1" style="opacity:.75">Effective ' + esc(fmtDate(p.effective_date)) + '</div>'
                + (p.summary ? '<p class="small mb-2">' + esc(p.summary) + '</p>' : '')
                + '<a href="' + esc(p.url) + '" class="small wn-legal-read" data-url="' + esc(p.url) + '">Read the full ' + esc(p.title) + '</a>'
                + '</div>';
        }).join('');
        var el = document.createElement('div');
        el.className = 'modal fade';
        el.id = 'wnLegalNotice';
        el.tabIndex = -1;
        el.setAttribute('aria-labelledby', 'wnLegalNoticeTitle');
        el.setAttribute('data-bs-backdrop', 'static');
        el.setAttribute('data-bs-keyboard', 'false');
        el.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
            + '<div class="modal-header"><h5 class="modal-title" id="wnLegalNoticeTitle">We’ve updated our ' + esc(names) + '</h5></div>'
            + '<div class="modal-body"><p class="small">Please take a moment to review what changed. By selecting Accept you agree to the updated '
            + (pending.length > 1 ? 'documents' : 'document') + '.</p>' + items
            + '<p class="small text-danger mb-0 d-none" id="wnLegalErr"></p></div>'
            + '<div class="modal-footer"><button type="button" class="btn btn-primary w-100" id="wnLegalAccept">Accept</button></div>'
            + '</div></div>';
        document.body.appendChild(el);
        var modal = new global.bootstrap.Modal(el);
        el.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('.wn-legal-read') : null;
            if (a) { e.preventDefault(); openUrl(a.getAttribute('data-url')); }
        });
        document.getElementById('wnLegalAccept').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Saving…';
            global.apiPost('acceptLegalUpdate', {
                version_ids: pending.map(function (p) { return p.version_id; }).join(','),
                client: env.BUNDLED ? 'app' : 'web'
            }, function (res) {
                if (res && !res.error) {
                    modal.hide();
                    el.addEventListener('hidden.bs.modal', function () { el.parentNode && el.parentNode.removeChild(el); });
                    return;
                }
                // Stale (a newer version appeared) or any refusal: show the latest instead.
                modal.hide();
                el.parentNode && el.parentNode.removeChild(el);
                shown = false;
                lastCheck = 0;
                check(true);
            });
        });
        modal.show();
    }

    function check(force) {
        if (busy || shown || typeof global.apiPost !== 'function' || !signedIn()) { return; }
        var now = Date.now();
        if (!force && now - lastCheck < 10 * 60 * 1000) { return; }
        lastCheck = now;
        busy = true;
        global.apiPost('getLegalStatus', {}, function (res) {
            busy = false;
            var p = res && res.results && res.results.pending;
            if (p && p.length) { show(p); }
        });
        setTimeout(function () { busy = false; }, 15000);
    }

    // Links to the policies (data-wn-legal="privacy|terms"): in the app there is no server
    // next door, so open the public page in the browser. Core's /admin/legal/<doc> forwards
    // to the app's own page when it has one (legal.json). On the web the href is used as is.
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-wn-legal]') : null;
        if (!a || !env.BUNDLED || !env.AUTH_BASE) { return; }
        var kind = a.getAttribute('data-wn-legal');
        e.preventDefault();
        // "url": the link already names an absolute page (a version permalink).
        if (kind === 'url' && /^https?:\/\//.test(a.href)) { openUrl(a.href); return; }
        openUrl(env.AUTH_BASE + '../../admin/legal/' + (kind === 'terms' ? 'terms' : 'privacy'));
    }, true);

    function start() { setTimeout(function () { check(true); }, 600); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
    // In the app: after signing in (the route leaves #/login) and when it comes back to the front.
    global.addEventListener('hashchange', function () { if (env.BUNDLED) { check(false); } });
    document.addEventListener('resume', function () { check(false); }, false);
    global.WnLegalNotice = { check: function () { check(true); } };
})(window);

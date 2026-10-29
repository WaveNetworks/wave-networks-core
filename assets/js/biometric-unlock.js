/*
 * biometric-unlock.js — optional "Unlock with Face ID / fingerprint" for the phone app (task #2662).
 *
 * Off by default. The switch is any element marked [data-wn-bio-unlock] (an app's Sign-in &
 * security view renders it hidden; this script shows it only where cordova-plugin-fingerprint-aio
 * answers, so the web never sees it). Turning it on asks for Face ID / fingerprint once, so it is
 * never saved on a phone that cannot pass the check. The choice lives on this phone only
 * (localStorage 'wn_bio_unlock').
 *
 * When on: the app covers itself on open, and on resume after LOCK_AFTER ms in the background,
 * until Face ID / fingerprint passes. "Sign out instead" is the way out (WnApi.logout), so a
 * failed sensor never strands anyone. Nothing locks while signed out.
 */
(function (global) {
    'use strict';
    if (global.WnBioUnlock) { return; }

    var KEY = 'wn_bio_unlock', LOCK_AFTER = 60000, DISMISSED = -108;
    var pausedAt = 0, cover = null, asking = false;

    function fp() { return global.Fingerprint && typeof global.Fingerprint.show === 'function' ? global.Fingerprint : null; }
    function on() { try { return global.localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
    function set(v) { try { if (v) { global.localStorage.setItem(KEY, '1'); } else { global.localStorage.removeItem(KEY); } } catch (e) {} }
    function signedIn() { return !global.WnApi || typeof global.WnApi.isAuthed !== 'function' || global.WnApi.isAuthed(); }
    function say(msg, kind) { if (typeof global.showToast === 'function') { global.showToast(msg, kind || 'warning'); } }

    function available(yes, no) {
        var F = fp();
        if (!F) { no(); return; }
        F.isAvailable(function () { yes(F); }, function () { no(); }, { requireStrongBiometrics: true });
    }

    function ask(F, why, ok, fail) {
        if (asking) { return; }
        asking = true;
        F.show({ description: why, disableBackup: false }, function () { asking = false; ok(); },
            function (e) { asking = false; if (fail) { fail(e); } });
    }

    function unlock() {
        available(function (F) {
            ask(F, 'Unlock the app', function () { hide(); }, function (e) {
                if (e && e.code !== DISMISSED) { say('That did not match. Try again or sign out.'); }
            });
        }, function () { hide(); });   // biometrics removed from the phone: nothing left to check against
    }

    function hide() { if (cover) { cover.remove(); cover = null; } }

    function lock() {
        if (cover || !on() || !fp() || !signedIn()) { return; }
        cover = document.createElement('div');
        cover.className = 'wn-bio-lock';
        cover.setAttribute('role', 'dialog');
        cover.setAttribute('aria-modal', 'true');
        cover.setAttribute('aria-label', 'App locked');
        cover.style.cssText = 'position:fixed;inset:0;z-index:2147483000;display:flex;flex-direction:column;align-items:center;'
            + 'justify-content:center;gap:1rem;padding:2rem;text-align:center;background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529)';
        cover.innerHTML = '<i class="bi bi-lock-fill" style="font-size:3rem" aria-hidden="true"></i>'
            + '<p class="h5 mb-0">Locked</p><p class="text-muted small mb-2">Use Face ID or your fingerprint to open the app.</p>'
            + '<button type="button" class="btn btn-primary wn-bio-unlock-btn">Unlock</button>'
            + '<button type="button" class="btn btn-link btn-sm wn-bio-signout">Sign out instead</button>';
        cover.querySelector('.wn-bio-unlock-btn').addEventListener('click', unlock);
        cover.querySelector('.wn-bio-signout').addEventListener('click', function () {
            hide();
            if (global.WnApi && typeof global.WnApi.logout === 'function') { global.WnApi.logout(); }
        });
        document.body.appendChild(cover);
        unlock();
    }

    // The switch on Sign-in & security (rendered into the page by the app's view).
    function sync(root) {
        var els = (root || document).querySelectorAll ? (root || document).querySelectorAll('[data-wn-bio-unlock]:not([data-wn-bio-ready])') : [];
        if (!els.length || !fp()) { return; }
        Array.prototype.forEach.call(els, function (wrap) { wrap.setAttribute('data-wn-bio-ready', '1'); });   // ask the sensor once per switch
        available(function () {
            Array.prototype.forEach.call(els, function (wrap) {
                var box = wrap.querySelector('input[type="checkbox"]');
                if (box) { box.checked = on(); }
                wrap.classList.remove('d-none');
                wrap.hidden = false;
            });
        }, function () {});
    }

    document.addEventListener('change', function (e) {
        var box = e.target;
        if (!box || !box.closest || !box.closest('[data-wn-bio-unlock]') || box.type !== 'checkbox') { return; }
        if (!box.checked) { set(false); say('The app no longer asks for Face ID / fingerprint.', 'success'); return; }
        box.checked = false;   // only on once the check passes
        available(function (F) {
            ask(F, 'Turn on unlock with Face ID / fingerprint', function () {
                set(true); box.checked = true;
                say('The app now asks for Face ID / fingerprint when you open it.', 'success');
            }, function (err) { if (!err || err.code !== DISMISSED) { say('Could not check it is you, so it stays off.'); } });
        }, function () { say('Set up Face ID or a fingerprint on this phone first.'); });
    }, true);

    // Runs on page load AND deviceready (the plugin may only exist after the latter); both are safe twice.
    var watching = false;
    function start() {
        sync(document);
        if (!watching && global.MutationObserver && document.body) {
            watching = true;
            new MutationObserver(function () { sync(document); }).observe(document.body, { childList: true, subtree: true });
        }
        lock();
    }
    document.addEventListener('deviceready', function () { setTimeout(start, 0); }, false);
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
    document.addEventListener('pause', function () { pausedAt = Date.now(); }, false);
    document.addEventListener('resume', function () {
        if (pausedAt && Date.now() - pausedAt >= LOCK_AFTER) { lock(); }
        pausedAt = 0;
    }, false);

    global.WnBioUnlock = { lock: lock, isOn: on, LOCK_AFTER: LOCK_AFTER };
})(window);

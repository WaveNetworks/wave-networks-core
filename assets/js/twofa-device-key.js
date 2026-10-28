/*
 * twofa-device-key.js — the phone's device key for "Approve on your phone" (task #2662).
 *
 * Tapping a number on the picker (twofaPushDecide, decision=approve) first asks for Face ID /
 * Touch ID / fingerprint. Behind it sits a random key kept in the Keychain (iOS) or Keystore
 * (Android) by cordova-plugin-fingerprint-aio, so it never leaves the phone without a
 * biometric check. The first approval creates the key and sends it with this phone's push
 * token; core stores only sha256 on that phone's row (shard/1.6) and from then on an approval
 * counts only with the key of a phone still allowed on Sign-in & security. "Not me" never
 * asks: blocking is always safe.
 *
 * The push token comes from localStorage 'wn_push_token' (the app's push code keeps it).
 * No plugin (web, older builds) → the form goes as before and the server decides.
 * Cancelling the prompt sends nothing.
 */
(function (global) {
    'use strict';
    if (global.__wnTwofaKey) { return; }
    global.__wnTwofaKey = true;

    var NOT_FOUND = -113, DISMISSED = -108, LOCKED = -111, LOCKED_PERM = -112;

    function fp() { return global.Fingerprint && typeof global.Fingerprint.loadBiometricSecret === 'function' ? global.Fingerprint : null; }

    function newKey() {
        var b = new Uint8Array(32);
        global.crypto.getRandomValues(b);
        return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }

    function field(form, name, value) {
        var el = form.querySelector('input[name="' + name + '"]');
        if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = name; form.appendChild(el); }
        el.value = value;
    }

    function send(form, key) {
        if (key) {
            field(form, 'device_key', key);
            field(form, 'push_token', (global.localStorage && global.localStorage.getItem('wn_push_token')) || '');
        }
        form.__wnKeyed = true;
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    }

    function say(msg) {
        if (typeof global.showToast === 'function') { global.showToast(msg, 'warning'); } else { global.alert(msg); }
    }

    function approve(form, F) {
        var why = 'Approve the sign-in';
        F.loadBiometricSecret({ description: why, disableBackup: true }, function (key) {
            send(form, key);
        }, function (err) {
            var code = err && err.code;
            if (code === NOT_FOUND) {
                var key = newKey();
                F.registerBiometricSecret({ description: why, secret: key, invalidateOnEnrollment: true, disableBackup: true },
                    function () { send(form, key); },
                    function (e2) { if (!e2 || e2.code !== DISMISSED) { say('Could not save the sign-in key on this phone. Use another way on the computer.'); } });
            } else if (code === LOCKED || code === LOCKED_PERM) {
                say('Face ID / fingerprint is locked. Unlock your phone, then try again.');
            } else if (code !== DISMISSED) {
                say('Could not check it is you. Use another way on the computer.');
            }
        });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.__wnKeyed || !form.querySelector) { return; }
        var a = form.querySelector('input[name="action"]'), d = form.querySelector('input[name="decision"]');
        if (!a || a.value !== 'twofaPushDecide' || !d || d.value !== 'approve') { return; }
        var F = fp();
        if (!F) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        F.isAvailable(function () { approve(form, F); },
            function () { send(form, ''); },   // no biometrics set up: the server decides (a keyed account refuses)
            { requireStrongBiometrics: true });
    }, true);

})(window);

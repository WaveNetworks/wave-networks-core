/**
 * passkey.js — the browser half of passkeys (core task #2661; server: include/common/passkeyFunctions.php).
 * Inlined by snippets/passkey_signin.php and snippets/passkey_manage.php, so it works from core and
 * every child app without an asset path. Options arrive as JSON with binary fields in base64url.
 */
(function () {
    if (window.WnPasskey) return;

    function b64uToBuf(s) {
        s = String(s || '').replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = atob(s), buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }
    function bufToB64u(buf) {
        if (!buf) return '';
        var bytes = new Uint8Array(buf), bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function idList(list) {
        return (list || []).map(function (c) { var o = Object.assign({}, c); o.id = b64uToBuf(c.id); return o; });
    }
    /** What a person reads when the device ceremony fails. */
    function why(err) {
        var n = err && err.name;
        if (n === 'NotAllowedError') return 'The passkey was cancelled or timed out.';
        if (n === 'InvalidStateError') return 'This device already has a passkey for your account.';
        if (n === 'SecurityError') return 'Passkeys do not work on this address.';
        if (n === 'NotSupportedError') return 'This device cannot make a passkey.';
        return 'The passkey did not work: ' + ((err && err.message) || err);
    }

    window.WnPasskey = {
        supported: function () {
            return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
        },
        why: why,

        /** Sign in (or finish the second step): ask the device, then post the answer in $form. */
        signIn: function (options, form, onError) {
            var pk = Object.assign({}, options.publicKey);
            pk.challenge = b64uToBuf(pk.challenge);
            pk.allowCredentials = idList(pk.allowCredentials);
            return navigator.credentials.get({ publicKey: pk }).then(function (cred) {
                var r = cred.response;
                form.querySelector('[name=credential_id]').value = bufToB64u(cred.rawId);
                form.querySelector('[name=client_data]').value = bufToB64u(r.clientDataJSON);
                form.querySelector('[name=authenticator_data]').value = bufToB64u(r.authenticatorData);
                form.querySelector('[name=signature]').value = bufToB64u(r.signature);
                form.querySelector('[name=user_handle]').value = bufToB64u(r.userHandle);
                form.submit();
            }, function (err) { if (onError) onError(why(err)); });
        },

        /** Add a passkey on the account page: options → device → save. done(ok, message). */
        enrol: function (name, done) {
            apiPost('passkeyEnrolOptions', {}, function (res) {
                if (!res || res.error || !res.results || !res.results.options) { done(false, (res && res.error) || ''); return; }
                var pk = Object.assign({}, res.results.options.publicKey);
                pk.challenge = b64uToBuf(pk.challenge);
                pk.user = Object.assign({}, pk.user, { id: b64uToBuf(pk.user.id) });
                pk.excludeCredentials = idList(pk.excludeCredentials);
                navigator.credentials.create({ publicKey: pk }).then(function (cred) {
                    apiPost('passkeyEnrolFinish', {
                        client_data: bufToB64u(cred.response.clientDataJSON),
                        attestation: bufToB64u(cred.response.attestationObject),
                        name: name || res.results.default_name || ''
                    }, function (fin) { done(!!(fin && !fin.error), (fin && (fin.error || fin.success)) || ''); });
                }, function (err) { done(false, why(err)); });
            }, function (msg) { done(false, msg); });
        }
    };
})();

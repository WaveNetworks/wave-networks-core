/**
 * bs-init.js
 * Bootstrap initialization and global JS utilities.
 */

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(el) {
        return new bootstrap.Tooltip(el);
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(el) {
        return new bootstrap.Popover(el);
    });
});

/**
 * POST to the API endpoint and handle JSON response.
 *
 * @param {string} action  The action name
 * @param {object} data    Key-value pairs to send
 * @param {function} callback  Called with (response) on success
 * @param {function} [onError] Called with (message, detail) when no usable answer came back:
 *        the request failed, timed out (45 s), or the answer was not JSON (a PHP error, a login
 *        page, a proxy page). Without it a page's loader waited for ever, because the callback
 *        never ran. Pass it from anything that shows a spinner and replace the spinner with the
 *        message. detail = {status, body} (body: the first 240 characters, tags stripped).
 */
function apiPost(action, data, callback, onError) {
    var formData = new FormData();
    formData.append('action', action);
    for (var key in data) {
        formData.append(key, data[key]);
    }
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function() { if (ctl) ctl.abort(); }, 45000);

    function fail(message, detail) {
        if (typeof onError === 'function') onError(message, detail || {});
        else showAlert('danger', message);
    }

    fetch('../api/index.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        signal: ctl ? ctl.signal : undefined
    })
    .then(function(r) {
        return r.text().then(function(txt) { return { status: r.status, txt: txt }; });
    })
    .then(function(x) {
        clearTimeout(timer);
        var json = null;
        try { json = JSON.parse(x.txt); } catch (e) { /* not JSON */ }
        if (!json || typeof json !== 'object') {
            var body = x.txt.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240);
            fail('The server answered HTTP ' + x.status + ' with something that is not JSON' + (body ? ': ' + body : '.'),
                 { status: x.status, body: body });
            return;
        }
        // Auth failure — session lost, redirect to login page
        // Uses relative path so it works for both admin and child apps
        if (json.error && /^Login required\.?$/.test(json.error)) {
            window.location.href = '../auth/login.php';
            return;
        }
        if (json.error) {
            showAlert('danger', json.error);
        } else if (json.success) {
            showAlert('success', json.success);
        }
        if (callback) callback(json);
    }, function(err) {
        clearTimeout(timer);
        fail((err && err.name === 'AbortError') ? 'The server took more than 45 seconds to answer.'
                                                : 'Request failed: ' + ((err && err.message) || err));
    });
}

/**
 * Show a Bootstrap alert at the top of the content area.
 */
function showAlert(type, message) {
    var container = document.querySelector('.container-fluid');
    if (!container) return;

    var alert = document.createElement('div');
    alert.className = 'alert alert-' + type + ' alert-dismissible fade show';
    alert.setAttribute('role', 'alert');
    alert.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

    var firstChild = container.querySelector(':scope > .alert') || container.firstChild;
    try {
        container.insertBefore(alert, firstChild);
    } catch(e) {
        container.prepend(alert);
    }

    // Auto-dismiss after a few seconds so messages/errors don't linger forever.
    // Uses Bootstrap's Alert.close() to respect the fade-out animation and clean
    // up the node; the manual close button still works before the timer fires.
    setTimeout(function() {
        if (!alert.parentNode) return;
        try {
            var inst = (window.bootstrap && bootstrap.Alert)
                ? bootstrap.Alert.getOrCreateInstance(alert)
                : null;
            if (inst) { inst.close(); } else { alert.remove(); }
        } catch(e) {
            alert.remove();
        }
    }, 6000);
}

// ─── SESSION HEARTBEAT ─────────────────────────────────────────────────────
// Polls the server to detect expired sessions and redirect to login.
// Runs every 2 minutes and on tab visibility change.
(function() {
    'use strict';

    // Login-optional pages (e.g. a public drop feed) render for anonymous
    // visitors who have no session to keep alive. checkSession returns
    // "Login required" for them, which would false-positive as an expired
    // session and bounce them to login on a timer / tab-focus. Skip the
    // heartbeat when the page explicitly declares a logged-out visitor.
    // Backward-compatible: apps that never set the flag leave it undefined,
    // so the heartbeat runs unchanged.
    if (window.__WN_AUTHED__ === false) return;

    var SESSION_CHECK_INTERVAL = 120000; // 2 minutes
    var sessionTimer = null;

    function checkSession() {
        var formData = new FormData();
        formData.append('action', 'checkSession');

        fetch('../api/index.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.error && /^Login required\.?$/.test(json.error)) {
                    window.location.href = '../auth/login.php';
                }
            })
            .catch(function() { /* network error — skip, retry next interval */ });
    }

    // Poll on interval
    sessionTimer = setInterval(checkSession, SESSION_CHECK_INTERVAL);

    // Also check when user returns to the tab
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            checkSession();
        }
    });
})();

/**
 * report.js — surface the mobile app's own health signals to the admin (spec 05).
 *
 * error-reporter.js (vendored from core) already sends uncaught errors, promise rejections
 * and console.error to the admin error log via logJsError. But the two signals that matter
 * most for THIS architecture are only console.warn, so they slip past it:
 *
 *   - "no handler X" — the markup asked for behavior this build doesn't have
 *   - "needs a newer build" — a screen's JS drifted from what shipped (the deviation gauge)
 *
 * Both mean the same thing: the shipped bundle is out of sync with the server's views, i.e.
 * a store build is due. That's exactly what an admin wants to see WITHOUT a user pasting a
 * console. So we report them explicitly, tagged with surface=mobile + the view + the app
 * version, through the same logJsError endpoint. Deduped per session so one broken screen
 * doesn't flood the log.
 */
window.WnReport = (function () {
    'use strict';

    var sent = {};

    function view() {
        return (window.WnRouter && WnRouter.current && WnRouter.current()) || '';
    }
    function version() {
        return (window.WnStore && WnStore.version) ? WnStore.version() : '?';
    }

    return {
        /**
         * Report an app health signal once per session.
         * @param {string} kind   e.g. 'no-handler', 'needs-build', 'fetch-fail'
         * @param {string} detail the handler name / screen / reason
         */
        signal: function (kind, detail) {
            var key = kind + ':' + detail;
            if (sent[key]) return;              // once per session — don't flood the log
            sent[key] = 1;

            if (typeof apiPost !== 'function') return;
            apiPost('logJsError', {
                message: '[mobile:' + kind + '] ' + detail + ' (view: ' + (view() || '—') + ')',
                error_type: 'mobile-signal',
                source_app: (window.WN_ENV && WN_ENV.APP_SLUG) || 'app',
                page_url: location.href,
                // No JS stack for these — record the context the admin actually needs.
                stack: 'surface=mobile' +
                       '\nnative=' + !!(window.Platform && Platform.isDevice) +
                       '\nview=' + view() +
                       '\napp_version=' + version()
            }, function () {});
        }
    };
})();

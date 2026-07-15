/**
 * env.js — the ONE per-app switch (child-app spec 05).
 *
 * This is a TEMPLATE. Each child app owns its own env.js (copied from here by the
 * scaffold); fill in the placeholders below. Everything else in the mobile engine is
 * generic and vendored from core — this is the only file that names your app.
 *
 * The engine reads window.WN_ENV for: the API/app/auth base (relative on the web, absolute
 * on a device where there is no server next door), plus the app NAME (login/topbar text)
 * and SLUG (error-log source_app).
 */
window.WN_ENV = (function () {
    'use strict';
    // A bundled Cordova app is served from the device, NOT from the app's real domain:
    //   Android WebView → https://localhost   iOS WKWebView → app://localhost   old → file://
    // So "bundled" = not served over http(s) from a real host. The protocol test alone is
    // NOT enough — Android uses https, so it must also treat a localhost host as bundled.
    var h = location.hostname;
    var isBundled = location.protocol === 'file:' || h === 'localhost' || h === '127.0.0.1' || h === '';

    // ── Fill these per app ────────────────────────────────────────────────────
    var ORIGIN = 'https://__APP_DOMAIN__';   // e.g. https://vivajee.com
    var PATH   = '/__APP_SLUG__';            // webroot path, e.g. /vivajee

    return {
        API_BASE:  isBundled ? ORIGIN + PATH + '/api/'  : '../api/',
        APP_BASE:  isBundled ? ORIGIN + PATH + '/app/'  : '../app/',
        AUTH_BASE: isBundled ? ORIGIN + PATH + '/auth/' : '../auth/',
        BUNDLED:   isBundled,
        APP_NAME:  '__APP_NAME__',            // e.g. Vivajee — shown on login + topbar
        APP_SLUG:  '__APP_SLUG__'             // e.g. vivajee — error-log source_app
    };
})();

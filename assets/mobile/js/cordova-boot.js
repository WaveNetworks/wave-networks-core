/**
 * cordova-boot.js — load the platform's cordova.js, but ONLY on a device.
 *
 * cordova.js is not part of m/ — the Cordova build injects it next to index.html in the
 * binary. On the web it simply does not exist, so a static <script src="cordova.js">
 * 404s and the CSP refuses the returned HTML error page as a script. Harmless (the app
 * is correctly in browser mode) but it looks like a real error in the console.
 *
 * The device-vs-web tell is the protocol: a device bundle loads over file:// (Android)
 * or an app/ionic/capacitor scheme (iOS WKWebView), never http(s). Same signal env.js
 * uses for API_BASE.
 *
 * document.write is deliberate: it injects cordova.js into the parser stream so it runs
 * BEFORE platform.js, which decides device-vs-browser by whether window.cordova exists.
 * Loading it async would let platform.js run first and wrongly pick browser mode on a
 * real device. This file is the only place that trick lives.
 */
(function () {
    'use strict';

    var isWeb = location.protocol === 'http:' || location.protocol === 'https:';
    if (isWeb) { return; }   // nothing to load; platform.js will resolve to browser mode

    // Don't load cordova.js twice. Some build pipelines inject their own
    // <script src="cordova.js"> into index.html (Appflow's "add cordova to index" step
    // does exactly this) — loading it again fires deviceready twice and re-inits plugins.
    // Only inject when nothing else already has.
    if (window.cordova) { return; }
    if (document.querySelector('script[src="cordova.js"], script[src$="/cordova.js"]')) { return; }

    // On a device the origin is the app scheme, so cordova.js is same-origin and the
    // CSP's script-src 'self' allows it.
    document.write('<script src="cordova.js"><\/script>');
})();

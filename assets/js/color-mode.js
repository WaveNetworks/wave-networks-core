/**
 * color-mode.js
 * Light/dark mode toggle button behavior.
 * Initial mode is set by inline <head> script to prevent FOUC.
 * This file wires up the toggle button and icon sync.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'wn_color_mode';
    var btn = document.getElementById('colorModeToggle');
    var iconEl = btn ? btn.querySelector('i') : null;

    function getMode() {
        return document.documentElement.getAttribute('data-bs-theme') || 'light';
    }

    function syncIcon(mode) {
        if (!iconEl) return;
        iconEl.className = mode === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
        btn.setAttribute('title', mode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }

    function updateLogos(mode) {
        document.querySelectorAll('img[data-logo-dark]').forEach(function (img) {
            // If the logo is inside a forced-dark container, always use the dark variant
            var forcedDark = img.closest('[data-bs-theme="dark"]');
            img.src = (mode === 'dark' || forcedDark) ? img.dataset.logoDark : img.dataset.logoLight;
        });
    }

    // Expose logo re-evaluation so theme.js can call it after it changes the
    // sidebar's forced-dark mode (glass themes drop data-bs-theme="dark",
    // which flips the sidebar logo from the dark variant to the light one).
    window.wnUpdateLogos = function () { updateLogos(getMode()); };

    // Sync icon and logos to whatever the head script already set.
    // MUST run even when there is no toggle button: the guard used to sit above this,
    // so any page without #colorModeToggle kept the server-rendered src — which is
    // logo_path, the LIGHT-theme (dark ink) art — no matter what the theme was.
    syncIcon(getMode());
    updateLogos(getMode());

    // Re-sync whatever the SPA/router just injected. updateLogos only ever walked the
    // images present at load, so a logo arriving with a fragment (e.g. the dashboard
    // watermark) stayed on its server-rendered variant until the user happened to hit
    // the toggle — "black regardless of theme, correct once I use the switcher".
    document.addEventListener('spa:contentLoaded', function () { updateLogos(getMode()); });

    if (!btn) return;

    btn.addEventListener('click', function () {
        var current = getMode();
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem(STORAGE_KEY, next);
        syncIcon(next);
        updateLogos(next);
    });

    // Live system preference tracking (only when user hasn't set explicit preference)
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (localStorage.getItem(STORAGE_KEY)) return; // user has explicit preference
        var mode = e.matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', mode);
        syncIcon(mode);
        updateLogos(mode);
    });
})();

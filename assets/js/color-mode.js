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
    if (!btn) return;

    var iconEl = btn.querySelector('i');

    function getMode() {
        return document.documentElement.getAttribute('data-bs-theme') || 'light';
    }

    function syncIcon(mode) {
        if (!iconEl) return;
        iconEl.className = mode === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
        btn.setAttribute('title', mode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }

    // Sync icon to whatever the head script already set
    syncIcon(getMode());

    btn.addEventListener('click', function () {
        var current = getMode();
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem(STORAGE_KEY, next);
        syncIcon(next);
    });

    // Live system preference tracking (only when user hasn't set explicit preference)
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (localStorage.getItem(STORAGE_KEY)) return; // user has explicit preference
        var mode = e.matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', mode);
        syncIcon(mode);
    });
})();

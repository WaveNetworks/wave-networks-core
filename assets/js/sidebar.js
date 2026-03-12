/**
 * sidebar.js
 * Responsive sidebar toggle with 3-state behavior.
 *
 * xs  (<576px):  hidden by default, slides in as overlay via body.sidebar-visible
 * md  (576-992px): collapsed (icon-only) by default, .expanded class to widen
 * lg  (≥992px):  expanded by default, .collapsed class to narrow
 *
 * Adapted from ownershiptrack theme.js patterns — clean vanilla JS, no jQuery,
 * no SB Admin 2 dependencies.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'wn_sidebar_state';
    var BP_SM = 576;
    var BP_LG = 992;

    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    var lastBreakpoint = null;

    // ── Helpers ──────────────────────────────────────────────────

    function getViewportWidth() {
        return Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    }

    function getBreakpoint() {
        var vw = getViewportWidth();
        if (vw < BP_SM) return 'xs';
        if (vw < BP_LG) return 'md';
        return 'lg';
    }

    function cleanState() {
        sidebar.classList.remove('collapsed', 'expanded');
        document.body.classList.remove('sidebar-visible');
    }

    // ── Initial load: apply saved preference for current breakpoint ──

    function initSidebarState(bp) {
        cleanState();

        if (bp === 'xs') return;

        var saved = localStorage.getItem(STORAGE_KEY);

        if (bp === 'md') {
            // SM-MD: collapsed by default (CSS). Expand only if user saved that.
            if (saved === 'expanded') {
                sidebar.classList.add('expanded');
            }
        } else {
            // LG+: expanded by default (CSS). Collapse only if user saved that.
            if (saved === 'collapsed') {
                sidebar.classList.add('collapsed');
            }
        }
    }

    // ── Resize: reset to CSS defaults when crossing breakpoints ──
    //    (matches ownershiptrack theme.js lines 256-304)

    function onBreakpointChange(bp) {
        cleanState();

        if (bp === 'xs') {
            // Going to mobile — force collapsed state in storage
            localStorage.setItem(STORAGE_KEY, 'collapsed');
        } else if (bp === 'md') {
            // Going to tablet — force collapsed (CSS default at this breakpoint)
            localStorage.setItem(STORAGE_KEY, 'collapsed');
        } else {
            // Going to desktop — force expanded (CSS default at this breakpoint)
            localStorage.setItem(STORAGE_KEY, 'expanded');
        }
    }

    // ── Click handlers (event delegation, capture phase) ────────

    function isSidebarCollapsed() {
        var bp = getBreakpoint();
        if (bp === 'lg') return sidebar.classList.contains('collapsed');
        if (bp === 'md') return !sidebar.classList.contains('expanded');
        return false;
    }

    document.addEventListener('click', function (e) {
        var target = e.target;

        // Collapsed sidebar: parent menu icons navigate to first child page
        var parentLink = target.closest ? target.closest('.sidebar-parent') : null;
        if (parentLink && isSidebarCollapsed()) {
            var submenuId = parentLink.getAttribute('href');
            if (submenuId) {
                var submenu = document.querySelector(submenuId);
                if (submenu) {
                    var firstLink = submenu.querySelector('a.nav-link');
                    if (firstLink && firstLink.href) {
                        e.preventDefault();
                        e.stopPropagation();
                        window.location.href = firstLink.href;
                        return;
                    }
                }
            }
        }

        // Desktop toggle (sidebar footer)
        var desktopBtn = target.closest ? target.closest('#sidebarToggle') : null;
        if (desktopBtn) {
            e.preventDefault();
            e.stopPropagation();
            var bp = getBreakpoint();
            if (bp === 'xs') return;

            if (bp === 'md') {
                var isExpanded = sidebar.classList.toggle('expanded');
                localStorage.setItem(STORAGE_KEY, isExpanded ? 'expanded' : 'collapsed');
            } else {
                var isCollapsed = sidebar.classList.toggle('collapsed');
                localStorage.setItem(STORAGE_KEY, isCollapsed ? 'collapsed' : 'expanded');
            }
            return;
        }

        // Mobile toggle (topnav hamburger)
        var mobileBtn = target.closest ? target.closest('#sidebarToggleTop') : null;
        if (mobileBtn) {
            e.preventDefault();
            e.stopPropagation();
            document.body.classList.toggle('sidebar-visible');
            return;
        }

        // Click-outside-to-close on mobile
        if (getBreakpoint() === 'xs' && document.body.classList.contains('sidebar-visible')) {
            if (!target.closest('#sidebar') && !target.closest('#sidebarToggleTop')) {
                document.body.classList.remove('sidebar-visible');
            }
        }
    }, true); // Capture phase — handles clicks before Bootstrap

    // ── Resize handler (debounced) ──────────────────────────────

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            var bp = getBreakpoint();
            if (bp !== lastBreakpoint) {
                onBreakpointChange(bp);
                lastBreakpoint = bp;
            }
        }, 150);
    });

    // ── Init ────────────────────────────────────────────────────

    lastBreakpoint = getBreakpoint();
    initSidebarState(lastBreakpoint);

})();

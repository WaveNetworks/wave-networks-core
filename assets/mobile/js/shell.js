/**
 * shell.js — boot + hydrate the template.php chrome (spec 05).
 *
 * The chrome is NOT hand-built here — it is views/template.php, rendered into index.html
 * at build time (scripts/build_shell.php). So the sidebar, topnav, notification bell, user
 * menu, colour-mode toggle, settings panel, footer, feedback tab and modals are the real
 * ones, already responsive, using the app's real CSS. This file only does what a static
 * render can't: fill in who's looking, keep the sidebar's active item in sync with the
 * router, and start the app.
 */
(function () {
    'use strict';

    // The template was rendered with placeholder user info (no DB at build time). Fill it
    // with whoever is actually signed in.
    function applyUser(u) {
        var name  = (u && u.name)  || '';
        var email = (u && u.email) || '';
        if (!name && !email) return;

        // The topnav user dropdown shows the first name; the template printed it empty.
        // Set only the text node so the Bootstrap caret (::after) survives.
        var trigger = document.querySelector('.navbar .dropdown-toggle');
        if (trigger && name) trigger.childNodes[0]
            ? (trigger.childNodes[0].nodeValue = name.split(' ')[0] + ' ')
            : (trigger.textContent = name.split(' ')[0]);
        var emailLine = document.querySelector('.navbar .dropdown-item-text');
        if (emailLine && email) emailLine.textContent = email;

        document.querySelectorAll('[data-user-name]').forEach(function (el) { el.textContent = name; });
        document.querySelectorAll('[data-user-email]').forEach(function (el) { el.textContent = email; });
    }

    function hydrateUser() {
        // Show the cached user instantly (a device-token login stored it)...
        applyUser(WnApi.user());

        // ...then confirm from the server. This is also the ONLY source on mobile web,
        // where the user is authed by the session cookie and never went through
        // deviceLogin, so WnApi has nothing cached.
        apiPost('deviceMe', {}, function (json) {
            var r = (json && json.results) || {};
            if (!r.user_id) return;              // signed out — leave it to the router/login
            WnApi.setUser({ user_id: r.user_id, email: r.email, name: r.name });
            applyUser(r);
        });
    }

    // Keep template.php's sidebar in sync with the router: highlight the nav item whose
    // page (or page-group) matches, the same way the server sets `active` on a full load.
    var GROUP = {
        circles: ['circles', 'circle', 'dinners', 'dinner', 'ledger', 'gallery', 'recap'],
        messages: ['messages', 'chat']
    };
    function reflectActive() {
        document.addEventListener('wn:navigated', function (e) {
            var page = e.detail.page;
            document.querySelectorAll('#sidebar .nav-link[href*="page="]').forEach(function (a) {
                var m = a.getAttribute('href').match(/[?&]page=([^&]+)/);
                var linkPage = m ? m[1] : '';
                var match = linkPage === page || (GROUP[linkPage] && GROUP[linkPage].indexOf(page) !== -1);
                a.classList.toggle('active', !!match);
                a.classList.toggle('bg-primary', !!match);
                a.classList.toggle('rounded', !!match);
            });

            // Close the sidebar overlay after navigating on a phone (it's persistent on
            // wide screens; sidebar.js manages that, we only dismiss the mobile overlay).
            if (document.body.classList.contains('sidebar-visible')) {
                var sb = document.getElementById('sidebar');
                if (sb) sb.classList.remove('expanded');
                document.body.classList.remove('sidebar-visible');
            }
        });
    }

    Platform.onReady(function () {
        WnStore.init(function () {
            reflectActive();

            // A fresh login (login.js) fires this so the topnav name/email fill in without
            // waiting for the next launch.
            document.addEventListener('wn:authed', hydrateUser);

            // Signed-out device → login. Mobile web with no token still has its session
            // cookie, so let the server decide (the fragment 401s / redirects if not).
            //
            // CRITICAL: always call WnRouter.start() — it is what attaches the hashchange
            // listener AND renders the initial route. toLogin() alone only sets the hash,
            // so on a signed-out device (no start) nothing rendered and you got the bare
            // template chrome. Preset the route to #/login BEFORE start() so it renders the
            // bundled login screen directly, with no network (a device may be offline at
            // launch) and no dashboard detour.
            var signedOutDevice = !WnApi.isAuthed() && window.WN_ENV.BUNDLED;
            if (signedOutDevice) {
                if (location.hash.indexOf('#/') !== 0) location.hash = '#/login';
            } else {
                hydrateUser();
            }
            WnRouter.start();
        });

        window.addEventListener('online',  function () { document.body.classList.remove('wn-offline'); WnRouter.reload(); });
        window.addEventListener('offline', function () { document.body.classList.add('wn-offline'); });
    });
})();

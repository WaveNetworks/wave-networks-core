/**
 * router.js — CSS routing + fragment loading (spec 05).
 *
 * Every screen is a <section data-screen="dinner">; the router flips [data-active].
 * No framework, no virtual DOM — the same CSS-routing pattern the ecosystem's other
 * Cordova app proved, and the same hash-route shape (#/dinner?id=42).
 *
 * What it puts INSIDE a section is the point of the whole design: markup rendered
 * by views/dinner.php — the very file the desktop renders — fetched through the
 * app's own ?page= router in mobile mode. There is no mobile implementation of the
 * dinner screen, and there is no build step that transforms one into the other.
 */
window.WnRouter = (function () {
    'use strict';

    var mount = null;
    var current = null;
    var stack = [];

    function parse(hash) {
        var h = (hash || location.hash || '#/dashboard').replace(/^#\/?/, '');
        var qi = h.indexOf('?');
        return {
            page: (qi === -1 ? h : h.slice(0, qi)) || 'dashboard',
            params: qi === -1 ? '' : h.slice(qi + 1)
        };
    }

    function section(page) {
        var el = mount.querySelector('[data-screen="' + page + '"]');
        if (!el) {
            el = document.createElement('section');
            el.setAttribute('data-screen', page);
            mount.appendChild(el);
        }
        return el;
    }

    function activate(page) {
        mount.querySelectorAll('[data-screen]').forEach(function (s) {
            s.removeAttribute('data-active');
        });
        section(page).setAttribute('data-active', '');

        // Login owns the whole viewport — the template chrome (sidebar/topnav) is hidden.
        document.body.classList.toggle('wn-chromeless', page === 'login');

        window.scrollTo(0, 0);
        current = page;

        // Let shell.js reflect the active screen in the template's sidebar/topnav.
        document.dispatchEvent(new CustomEvent('wn:navigated', { detail: { page: page } }));
    }

    /**
     * Render markup into a screen, then let the screen's own code wake up.
     *
     * The hoisted view scripts were written to run against freshly-rendered markup
     * (that is what a page load gave them). They listen for the same event desktop
     * SPA nav fires — spa:contentLoaded — so firing it here means the views' code
     * needs no mobile-specific entry point. Same code, same signal.
     */
    function render(page, markup) {
        var el = section(page);
        el.innerHTML = markup;
        activate(page);
        document.body.classList.add('wn-ready');

        // NOW run the screen's own code — the markup it was written against exists.
        // This is the moment a page load would have given it (see js/screens.js).
        WnScreens.init(page);

        // Load the screen's external scripts (chat → viv-chat.js, gallery → viv-upload.js,
        // …). The splitter stripped them from the fragment, so they live vendored in the
        // bundle; the manifest lists them per screen. They are IIFEs that bind to the
        // freshly-rendered markup at load, so — exactly like desktop spa-nav — we re-execute
        // them on every render by injecting a fresh <script> element. Without this, chat and
        // gallery render their shell but never wire up ("messages not loading").
        loadScreenDeps(page);

        document.dispatchEvent(new CustomEvent('spa:contentLoaded', {
            detail: { container: el, page: page }
        }));
    }

    // A thin top-of-viewport progress bar during a fragment fetch — the mobile equivalent
    // of desktop spa-nav's loading bar. Ref-counted so overlapping navigations don't hide
    // it early.
    var loadingCount = 0;
    var loadingEl = null;
    function showLoading() {
        loadingCount++;
        if (loadingEl) return;
        loadingEl = document.createElement('div');
        loadingEl.id = 'wn-loading';
        document.body.appendChild(loadingEl);
    }
    function hideLoading() {
        loadingCount = Math.max(0, loadingCount - 1);
        if (loadingCount > 0 || !loadingEl) return;
        loadingEl.remove();
        loadingEl = null;
    }

    function loadScreenDeps(page) {
        var meta = (WnStore.screens && WnStore.screens()[page]) || {};
        (meta.deps || []).forEach(function (src) {
            // Fresh element each time so the IIFE re-runs against the new DOM. The browser
            // still serves the file from cache, so this is cheap.
            var s = document.createElement('script');
            s.src = src;
            s.async = false;
            document.body.appendChild(s);
        });
    }

    /** Fetch the server's render of this view — the WET path. */
    function fetchFragment(page, params, cb) {
        var url = window.WN_ENV.APP_BASE + 'index.php?page=' + encodeURIComponent(page)
                + (params ? '&' + params : '')
                + '&reloadView=true&mobile=1';

        fetch(url, {
            headers: window.WnApi.headers(),
            credentials: window.WN_ENV.BUNDLED ? 'omit' : 'same-origin',
            cache: 'no-store'
        })
            .then(function (r) {
                // A bundled client gets a clean 401 (core answers Bearer requests that
                // way on purpose). Mobile WEB has no token, so core does what it does
                // for any signed-out browser: 302 → auth/login.php. fetch follows that
                // redirect silently and hands us a 200 full of HTML, and .json() then
                // throws — which would surface as "couldn't reach the server" when the real
                // answer is "you're signed out". Catch it as what it is.
                if (r.status === 401 || r.status === 403) throw new Error('unauthorized');

                var type = r.headers.get('content-type') || '';
                if (r.redirected || type.indexOf('json') === -1) throw new Error('unauthorized');

                return r.json();
            })
            .then(function (json) { cb(null, json); })
            .catch(function (err) { cb(err); });
    }

    function go(hash, push) {
        var r = parse(hash);
        var page = r.page;
        var params = r.params;

        // Login is the one screen that is NOT a view: it must work with no session and
        // no network, so it is bundled rather than fetched (see js/login.js).
        if (page === 'login') {
            render(page, WnLogin.markup());
            WnLogin.bind();
            return;
        }

        // Show the cached render immediately — the app should never look dead while
        // the network is thinking. It is replaced in place when the fetch lands.
        var cached = WnStore.get(page, params);
        if (cached) render(page, cached.markup);

        if (!Platform.online()) {
            document.body.classList.add('wn-offline');
            if (!cached) render(page, emptyState(page, 'You\'re offline, and this screen hasn\'t been opened on this device yet.'));
            return;
        }
        document.body.classList.remove('wn-offline');

        // Show a progress bar while the fragment is in flight — same feedback the web app's
        // spa-nav gives, so a tap doesn't feel dead while the next screen loads.
        showLoading();

        fetchFragment(page, params, function (err, json) {
            hideLoading();
            if (err) {
                if (err.message === 'unauthorized') return toLogin();
                // A genuine fetch failure while online is worth surfacing (a broken/renamed
                // fragment endpoint, a server error) — not mere offline, which is expected.
                if (Platform.online() && window.WnReport) WnReport.signal('fetch-fail', page);
                if (!cached) render(page, emptyState(page, 'Couldn\'t reach the server. Pull down to try again.'));
                return;
            }

            // THE REFUSAL. If this view's behavior has changed since the binary was
            // built, its markup expects handlers we do not have. Keep what we shipped.
            if (!WnStore.canRender(page, json.js_hash)) {
                console.warn('[viv] ' + page + ' needs a newer build — showing the bundled version');
                if (window.WnReport) WnReport.signal('needs-build', page);
                if (!cached) render(page, emptyState(page, 'This screen needs an app update.'));
                return;
            }

            WnStore.put(page, params, json.markup, {
                view_hash: json.view_hash,
                js_hash: json.js_hash
            });
            render(page, json.markup);

            if (json.toast && window.showToast) {
                ['success', 'error', 'warning', 'info'].forEach(function (k) {
                    if (json.toast[k]) showToast(k === 'error' ? 'danger' : k, json.toast[k]);
                });
            }
        });

        if (push !== false) stack.push(page);
    }

    function emptyState(page, msg) {
        return '<div class="text-center text-muted py-5"><p class="mb-0">' + msg + '</p></div>';
    }

    function toLogin() {
        // No server to navigate to inside the bundle: the login SCREEN is part of
        // the shell (core's vendored m/ screens), so route to it like any other.
        location.hash = '#/login';
    }

    /**
     * Intercept the views' own navigation links.
     *
     * The screens are desktop views, so their links are desktop links —
     * `<a href="index.php?page=circle&id=5">`. In a browser tab those work; in the
     * mobile shell they'd navigate the WebView to a URL that does not exist. Desktop
     * solves this with spa-nav.js; the shell does the same here, rewriting a page link
     * into a hash route so the router handles it. Without this you can tap into nothing —
     * the dashboard's circle links, a circle's dinner links, all dead. It is the single
     * thing that makes navigation past the tab bar work.
     */
    function interceptLinks() {
        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;

            var a = e.target.closest('a[href]');
            if (!a || a.hasAttribute('data-no-route') || a.getAttribute('target') === '_blank') return;

            var href = a.getAttribute('href');
            if (!href) return;

            // A same-page hash link (e.g. dinner.php's <a href="#date-vote">) must
            // scroll — but we CANNOT let the browser write "#date-vote" over our
            // "#/dinner?id=2" route. If it did, the next reload() (vivAct fires one
            // after every write) would boot the router at a bogus "date-vote" page and
            // land on a broken screen — exactly the "clicking vote goes to a broken
            // page" report. So we scroll to the target ourselves and keep the route
            // hash intact. A "#/…" href is a real route; leave that to hashchange.
            if (href.charAt(0) === '#') {
                if (href.length > 1 && href.charAt(1) !== '/') {
                    e.preventDefault();
                    var target = document.getElementById(href.slice(1));
                    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                return;
            }

            // A real external link leaves the app (in the WebView, via the system browser).
            if (/^(https?:|mailto:|tel:)/i.test(href)) {
                if (window.WN_ENV.BUNDLED && /^https?:/i.test(href)) {
                    e.preventDefault();
                    Platform.openExternal(href);
                }
                return;
            }

            // Only ?page= links are screens. Anything else (a raw .php, a file) we leave.
            var m = href.match(/[?&]page=([^&#]+)([^#]*)/);
            if (!m) return;

            e.preventDefault();

            var page = decodeURIComponent(m[1]);
            var extra = (m[2] || '').replace(/^&/, '')
                .split('&')
                .filter(function (kv) { return kv && !/^(page|reloadView|mobile)=/.test(kv); })
                .join('&');

            location.hash = '#/' + page + (extra ? '?' + extra : '');
        }, false);
    }

    /**
     * Intercept the notification-bell dropdown (task #991).
     *
     * core's notifications.js (admin/assets/js/notifications.js) binds its own
     * click handler to each bell item that, after marking read, does a hard
     * `window.location.href = <action_url>` — e.g. "index.php?page=dinner&id=5".
     * In a browser tab that navigates fine; the web bundle also has the m/index.php
     * shim to catch it. But on-device (app://localhost, no PHP runtime) that URL
     * resolves to a file that does not exist, so the WebView goes blank — the
     * "clicking a notification in the top menu goes to a blank screen" report.
     *
     * We beat core to it with a CAPTURE-phase listener: it runs before the item's
     * own (bubble-phase) handler, so stopping propagation here keeps core's
     * hard-navigation from ever firing. We then do the two things it would have —
     * mark all read, and open the target — but as a hash route the router owns,
     * which works identically on-device and on the web.
     */
    function interceptNotifications() {
        document.addEventListener('click', function (e) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;

            var item = e.target.closest('[data-notification-id]');
            if (!item) return;
            // Only the bell dropdown's items carry action_url; the full notifications
            // screen (views/notifications.php) routes through interceptLinks as usual.
            if (!item.closest('#notifDropdown, #notifList')) return;

            e.preventDefault();
            e.stopImmediatePropagation(); // keep core's window.location.href handler from running

            // Mirror core: clear the unread state so the badge settles on next poll.
            if (window.apiPost) apiPost('markAllNotificationsRead', {}, function () {});

            // Close the open dropdown so it isn't left hanging over the new screen.
            var menu = item.closest('.dropdown-menu');
            if (menu) menu.classList.remove('show');

            // Turn the desktop-shaped action_url into a hash route. Empty/unclickable
            // notifications fall back to the notifications screen, same as core.
            var url = item.getAttribute('data-action-url') || '';
            var m = url.match(/[?&]page=([^&#]+)([^#]*)/);
            if (!m) { location.hash = '#/notifications'; return; }

            var page = decodeURIComponent(m[1]);
            var extra = (m[2] || '').replace(/^&/, '')
                .split('&')
                .filter(function (kv) { return kv && !/^(page|reloadView|mobile)=/.test(kv); })
                .join('&');
            location.hash = '#/' + page + (extra ? '?' + extra : '');
        }, true); // capture — must run before core's per-item bubble handler
    }

    return {
        start: function () {
            // template.php's own content area — the same #content-dynamic the desktop
            // app swaps on navigation. The router owns it now instead of spa-nav.
            mount = document.getElementById('content-dynamic') || document.getElementById('wn-screens');
            window.addEventListener('hashchange', function () {
                // Only "#/page" hashes are routes. A plain "#section" anchor (a view links
                // to its own subsection, e.g. dinner.php's <a href="#date-vote">) belongs to
                // the browser — routing it would fetch a non-existent "date-vote" screen.
                if (location.hash && location.hash.indexOf('#/') !== 0) return;
                go(location.hash);
            });
            interceptLinks();
            interceptNotifications();

            Platform.onBack(function () {
                if (stack.length > 1) { stack.pop(); location.hash = '#/' + stack[stack.length - 1]; }
                else if (Platform.isDevice && navigator.app) { navigator.app.exitApp(); }
            });

            go(location.hash || '#/dashboard');
        },

        go: go,
        toLogin: toLogin,
        current: function () { return current; },

        /** Re-fetch the current screen. The views call this by name after a write. */
        reload: function () { go(location.hash, false); }
    };
})();

/**
 * The views' own code calls reloadView() (desktop: spa-nav.js) and, in a few
 * places, window.location.reload(). Give them the name they already use, so the
 * hoisted code needs no edit — it just re-fetches the fragment instead of the page.
 */
window.reloadView = function () { window.WnRouter.reload(); };

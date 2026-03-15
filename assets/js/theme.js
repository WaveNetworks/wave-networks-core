/**
 * theme.js
 * Bootswatch + registered theme dynamic switcher.
 * Stores theme in both localStorage and a cookie so PHP can render
 * the correct stylesheet on first paint (no FOUC).
 */

(function() {
    var selector = document.getElementById('themeSelector');
    if (!selector) return;

    var BOOTSWATCH_API = 'https://bootswatch.com/api/5.json';
    var STORAGE_KEY = 'wn_theme';

    // Parse registered custom themes from data attribute
    var registeredThemes = [];
    try {
        registeredThemes = JSON.parse(selector.getAttribute('data-registered-themes') || '[]');
    } catch (e) {}
    var registeredSlugs = registeredThemes.map(function(t) { return t.slug; });

    // Load saved theme
    var savedTheme = localStorage.getItem(STORAGE_KEY) || 'sandstone';

    // Validate saved theme exists in Bootswatch or registered list
    // (Bootswatch validation happens after API fetch; for now trust it)

    function saveTheme(name) {
        localStorage.setItem(STORAGE_KEY, name);
        document.cookie = STORAGE_KEY + '=' + encodeURIComponent(name) + ';path=/;max-age=31536000;SameSite=Lax';
    }

    // Sync cookie if localStorage has a theme but cookie doesn't match
    var cookieMatch = document.cookie.match(new RegExp('(?:^|; )' + STORAGE_KEY + '=([^;]*)'));
    var cookieTheme = cookieMatch ? decodeURIComponent(cookieMatch[1]) : null;
    if (cookieTheme !== savedTheme) {
        saveTheme(savedTheme);
    }

    // Fetch themes from Bootswatch API
    fetch(BOOTSWATCH_API)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            selector.innerHTML = '';
            data.themes.forEach(function(theme) {
                var opt = document.createElement('option');
                opt.value = theme.name.toLowerCase();
                opt.textContent = theme.name;
                if (opt.value === savedTheme) opt.selected = true;
                selector.appendChild(opt);
            });

            // Append registered custom themes
            if (registeredThemes.length > 0) {
                var sep = document.createElement('option');
                sep.disabled = true;
                sep.textContent = '── Custom ──';
                selector.appendChild(sep);

                registeredThemes.forEach(function(theme) {
                    var opt = document.createElement('option');
                    opt.value = theme.slug;
                    opt.textContent = theme.name;
                    if (opt.value === savedTheme) opt.selected = true;
                    selector.appendChild(opt);
                });
            }
        })
        .catch(function() {
            // Fallback if API is down
            selector.innerHTML = '<option value="sandstone">Sandstone (default)</option>';
            if (registeredThemes.length > 0) {
                var sep = document.createElement('option');
                sep.disabled = true;
                sep.textContent = '── Custom ──';
                selector.appendChild(sep);

                registeredThemes.forEach(function(theme) {
                    var opt = document.createElement('option');
                    opt.value = theme.slug;
                    opt.textContent = theme.name;
                    if (opt.value === savedTheme) opt.selected = true;
                    selector.appendChild(opt);
                });
            }
        });

    // Theme change handler
    selector.addEventListener('change', function() {
        var theme = this.value;
        saveTheme(theme);
        applyTheme(theme);
    });

    function applyTheme(name) {
        var link = document.getElementById('themeStylesheet') || document.querySelector('link[href*="bootstrap"]');
        if (!link) return;

        // Check registered custom themes first
        var reg = registeredThemes.find(function(t) { return t.slug === name; });
        if (reg) {
            var newLink = document.createElement('link');
            newLink.rel = 'stylesheet';
            newLink.href = '../../' + reg.css_path;
            newLink.onerror = function() {
                saveTheme('sandstone');
                link.href = '../assets/bootstrap/css/bootstrap.min.css';
                newLink.remove();
            };
            newLink.onload = function() {
                link.href = newLink.href;
                newLink.remove();
            };
            document.head.appendChild(newLink);
            return;
        }

        if (name === 'default' || name === 'bootstrap') {
            link.href = '../assets/bootstrap/css/bootstrap.min.css';
        } else {
            var newLink = document.createElement('link');
            newLink.rel = 'stylesheet';
            newLink.href = 'https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/' + name + '/bootstrap.min.css';
            newLink.onerror = function() {
                // Theme CSS failed to load — fall back to default
                saveTheme('sandstone');
                link.href = '../assets/bootstrap/css/bootstrap.min.css';
                newLink.remove();
            };
            newLink.onload = function() {
                link.href = newLink.href;
                newLink.remove();
            };
            document.head.appendChild(newLink);
        }
    }
})();

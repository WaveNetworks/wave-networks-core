# Theme Management & Branding

## Theme management
Bootswatch themes + registered custom themes. Theme stored in cookie (wn_theme)
so PHP can render correct stylesheet on first paint — no FOUC.

Built-in themes: 25 Bootswatch themes (cerulean, cosmo, cyborg, darkly, flatly,
  journal, litera, lumen, lux, materia, minty, morph, pulse, quartz, sandstone,
  simplex, sketchy, slate, solar, spacelab, superhero, united, vapor, yeti, zephyr).
  Default: sandstone. Loaded from jsDelivr CDN.

Custom theme registration (for child apps):
  register_theme($slug, $name, $css_path, $opts)
  $opts: sidebar_mode (dark|glass), created_by_app, is_active
  DB table: registered_theme (main DB). Columns: slug (unique), name, css_path,
    sidebar_mode, created_by_app, is_active. Uses ON DUPLICATE KEY UPDATE.
  Child apps call register_theme() at bootstrap to add app-specific themes.

Theme resolution: get_active_theme() reads wn_theme cookie, validates against
  Bootswatch allowed list + registered_theme table. Falls back to sandstone.
  get_theme_css_url($prefix, $webroot_prefix) returns CDN URL for Bootswatch
  or relative path for registered themes.

JS: assets/js/theme.js
  Populates <select id="themeSelector"> from Bootswatch API + registered themes
  (registered themes passed via data-registered-themes attribute as JSON).
  On change: saves to localStorage + cookie, swaps stylesheet link href.
  Custom themes appear under a "Custom" separator in the dropdown.
  Falls back gracefully if Bootswatch API is down or theme CSS fails to load.

Helpers: include/common/themeFunctions.php, include/common/themeRegistrationFunctions.php

## Branding settings
Admin UI: views/settings.php — branding tab.
Form field names (important for programmatic updates):
  logo          — Logo file upload (navbar, login page)
  logo_dark     — Dark mode logo variant
  favicon       — Favicon file upload
  site_name     — Display name shown in navbar and page titles
  site_short_name — Short name for PWA manifest and browser tabs
  site_description — Site description for meta tags
  theme_color   — Hex color for PWA manifest and browser chrome

Storage: auth_settings table in main DB (key-value pairs).
Access: get_branding() returns all values. Available to child apps and
  websites via common_auth.php (no session required).

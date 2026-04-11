# Versioning & Update Checks

Version constants defined in include/definition.php:
  WN_ADMIN_VERSION — current admin core version (e.g. '1.0.0')
Child apps define WN_CHILD_APP_VERSION in their own definition.php.

Semantic versioning: MAJOR.MINOR.PATCH. Bump the constant + create a git tag
on each release (e.g. git tag -a v1.0.0 -m "Initial release").

Update check system:
  Helpers: include/common/updateCheckFunctions.php
    check_for_updates($force = false) — queries https://subtheme.com/api/versions
      Caches result to config/.update_check_cache.json (24h TTL).
      Returns stale cache if API unreachable.
    fetch_version_api($url) — cURL with file_get_contents fallback
    fetch_changelog($component, $fromVersion) — full changelog with diffs

  Action: include/actions/memberActions/updateCheckActions.php
    checkForUpdates — AJAX action, force-refreshes update check (admin only)

  Dashboard: views/dashboard.php auto-checks on load (cached, cheap).
    Shows dismissible alert banner when outdated with version comparison,
    release dates, and migration badges. Links to subtheme.com/docs/changelog.

  Settings: views/settings.php — System Info card shows Admin Version and
    Child App Version. Updates card with "Check for Updates" button (AJAX).

Public API (no auth): https://subtheme.com/api/versions
  No params — lightweight version summary for both components
  ?component=admin — full admin changelog with diffs
  ?component=child-app — full child-app changelog with diffs
  ?component=admin&from=1.0.0 — changes since version 1.0.0

Changelog generation: generate_changelog.php (in public_html/ root, not admin/)
  CLI script run locally before deploy. Reads git tags and history from each
  sub-repo (admin/, child-app/), outputs JSON to site/api/versions/data/.
  Usage: php generate_changelog.php [admin|child-app]

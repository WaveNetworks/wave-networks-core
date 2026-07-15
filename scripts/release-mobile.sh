#!/usr/bin/env bash
#
# release-mobile.sh — the mobile release pipeline, in core (child-app spec 05).
#
#   ADMIN_ROOT=/path/to/admin APP_ORIGIN=https://yourapp.com \
#     bash "$ADMIN_ROOT/scripts/release-mobile.sh" <version> [--push]
#
# Turns a child app's views/*.php + template.php into a self-contained mobile bundle (m/)
# and syncs it into the app's Cordova shell repo. The ENGINE is core's — this script
# vendors it into the app; the app owns only env.js, its theme, brand assets, and the
# per-app config below. Nothing here is app-specific: every app runs the same script with
# different config.
#
# Config (env vars; a child app's thin wrapper sets these):
#   APP_ROOT     the child app repo            (default: cwd)
#   ADMIN_ROOT   wave-networks-core checkout   (default: $APP_ROOT/../admin)
#   APP_ORIGIN   API/media origin for the CSP  (required, e.g. https://vivajee.com)
#   APP_SLUG     short name                    (default: basename of APP_ROOT)
#   THEME_SCSS   the app's theme entry scss    (default: assets/themes/glassmorphism/custom.scss)
#   BRAND_TILE   the app's icon svg            (default: assets/img/<slug>-tile.svg)
#   SHELL_REPO   the <app>-cordova checkout    (default: $APP_ROOT/../<slug>-cordova)
set -euo pipefail

VERSION="${1:-}"; PUSH="${2:-}"
[[ -z "$VERSION" ]] && { echo "usage: release-mobile.sh <version> [--push]" >&2; exit 1; }

APP_ROOT="${APP_ROOT:-$(pwd)}"
ADMIN_ROOT="${ADMIN_ROOT:-$APP_ROOT/../admin}"
APP_SLUG="${APP_SLUG:-$(basename "$APP_ROOT")}"
APP_ORIGIN="${APP_ORIGIN:-}"
THEME_SCSS="${THEME_SCSS:-assets/themes/glassmorphism/custom.scss}"
BRAND_TILE="${BRAND_TILE:-assets/img/${APP_SLUG}-tile.svg}"
SHELL_REPO="${SHELL_REPO:-$APP_ROOT/../${APP_SLUG}-cordova}"
ENGINE_JS="$ADMIN_ROOT/assets/mobile/js"
cd "$APP_ROOT"

[[ -d "$ENGINE_JS" ]] || { echo "✗ core engine not found at $ENGINE_JS — set ADMIN_ROOT" >&2; exit 1; }
[[ -f m/js/env.js ]]  || { echo "✗ $APP_ROOT/m/js/env.js missing (copy it from $ENGINE_JS/env.js.template and fill it in)" >&2; exit 1; }
[[ -n "$APP_ORIGIN" ]] || echo "   ! APP_ORIGIN unset — CSP will allow only 'self' (media/API from the app origin will be blocked)" >&2

echo "── 1. styles ─────────────────────────────────────────────"
npx sass "$ADMIN_ROOT/assets/mobile/scss/mobile-shell.scss" m/assets/mobile-shell.css --style compressed --load-path=node_modules --no-source-map
npx sass "$THEME_SCSS" m/assets/vendor/theme.css --style compressed --load-path=node_modules --no-source-map
echo "   mobile-shell.css (core) + theme.css (app)"

echo "── 2. hoist behavior + render the shell (core build scripts) ──"
php "$ADMIN_ROOT/scripts/build_mobile.php" --app-root="$APP_ROOT" --version="$VERSION"
php "$ADMIN_ROOT/scripts/build_shell.php"  --app-root="$APP_ROOT" --app-origin="$APP_ORIGIN"

echo "── 3. vendor (derived from what the shell actually references) ──"
mkdir -p m/js/vendor m/assets/vendor m/assets/img m/assets/icons m/assets/fonts
# The engine JS the boot section loads (env is the app's; the rest come from core).
cp "$ENGINE_JS"/*.js m/js/ 2>/dev/null; rm -f m/js/env.js.template
# Bootstrap (strip the sourcemap pragma — we don't ship the .map).
sed '/^\/\/# sourceMappingURL=/d' node_modules/bootstrap/dist/js/bootstrap.bundle.min.js > m/js/vendor/bootstrap.bundle.min.js
# Every OTHER js/vendor/X the generated shell + manifest reference, located in the app's or
# core's assets/js. This is what makes the vendor list app-agnostic — no hardcoded filenames.
refs=$( { grep -oE 'js/vendor/[^"?]+\.js' m/index.html; grep -oE 'js/vendor/[^"?]+\.js' m/manifest.json 2>/dev/null; } | sort -u )
for ref in $refs; do
    base=$(basename "$ref")
    [[ "$base" == "bootstrap.bundle.min.js" ]] && continue
    if   [[ -f "assets/js/$base" ]];            then cp "assets/js/$base" m/js/vendor/
    elif [[ -f "$ADMIN_ROOT/assets/js/$base" ]]; then cp "$ADMIN_ROOT/assets/js/$base" m/js/vendor/
    else echo "   ! chrome dep not found: $base (referenced by the shell)" >&2; fi
done
# App CSS the shell loads (real look), from core.
cp "$ADMIN_ROOT/assets/css/style.css"              m/assets/vendor/style.css
cp "$ADMIN_ROOT/assets/css/bs-theme-overrides.css" m/assets/vendor/bs-theme-overrides.css
# Bootstrap Icons — self-hosted.
if [[ -d node_modules/bootstrap-icons ]]; then
    cp node_modules/bootstrap-icons/font/bootstrap-icons.css m/assets/icons/
    cp -r node_modules/bootstrap-icons/font/fonts m/assets/icons/
fi
# Brand tile → standard bundle name the engine's login screen references.
[[ -f "$BRAND_TILE" ]] && cp "$BRAND_TILE" m/assets/img/app-tile.svg || echo "   ! BRAND_TILE $BRAND_TILE missing" >&2
cp assets/img/*.svg m/assets/img/ 2>/dev/null || true
# Self-hosted fonts (app-owned brand fonts).
if compgen -G "assets/fonts/*.woff2" > /dev/null; then
    cp assets/fonts/*.woff2 m/assets/fonts/
    cp assets/fonts/app-fonts.css m/assets/ 2>/dev/null || true
fi

echo "── 4. cache-bust (this host's CDN pins bare URLs) ────────"
php -r '
$out = preg_replace_callback("/(src|href)=\"((?!https?:|\/\/)[^\"?]+\.(?:js|css))\"/", function ($m) {
    if (basename($m[2]) === "cordova.js") return $m[0];
    $p = "m/" . $m[2];
    return is_readable($p) ? $m[1] . "=\"" . $m[2] . "?v=" . substr(md5_file($p),0,10) . "\"" : $m[0];
}, file_get_contents("m/index.html"));
file_put_contents("m/index.html", $out);
printf("   %d asset URLs content-hashed\n", preg_match_all("/\?v=/", $out));
$mf = "m/manifest.json";
if (is_readable($mf)) { $m = json_decode(file_get_contents($mf), true);
    if (!empty($m["screens"])) foreach ($m["screens"] as $pg => $sc) {
        $m["screens"][$pg]["deps"] = array_map(function ($d) {
            $p = "m/" . preg_replace("/\?.*$/","",$d);
            return is_readable($p) ? preg_replace("/\?.*$/","",$d) . "?v=" . substr(md5_file($p),0,10) : $d;
        }, $sc["deps"] ?? []);
    }
    file_put_contents($mf, json_encode($m, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}
'
cat > m/.htaccess <<'HT'
<FilesMatch "\.(html|json)$">
    Header set Cache-Control "no-cache, must-revalidate"
</FilesMatch>
<FilesMatch "\.(js|css|woff2?|svg|png)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
HT

echo "── 5. assert device-portable ─────────────────────────────"
fail=0
grep -rnE '(src|href)="https?://' m/index.html m/js/*.js 2>/dev/null | grep -v '^\s*//' && { echo "   ✗ remote reference" >&2; fail=1; }
grep -rnE '(src|href)="/' m/index.html 2>/dev/null && { echo "   ✗ absolute path in index.html" >&2; fail=1; }
while read -r src; do
    src="${src%%\?*}"; [[ "$src" == "cordova.js" ]] && continue
    [[ -f "m/$src" ]] || { echo "   ✗ missing referenced file: $src" >&2; fail=1; }
done < <(grep -oE '<script src="[^"]+"' m/index.html | sed 's/.*src="//; s/"//')
[[ $fail -eq 0 ]] || { echo "asset-completeness FAILED" >&2; exit 1; }
echo "   ✓ self-contained, relative-only, every reference resolves"

echo "── 6. sync → shell repo ──────────────────────────────────"
[[ -d "$SHELL_REPO" ]] || { echo "   ! $SHELL_REPO not found — skipping sync." >&2; exit 0; }
mkdir -p "$SHELL_REPO/www"
rsync -a --delete --exclude 'scss/' --exclude 'index.php' m/ "$SHELL_REPO/www/"
echo "   m/ → $SHELL_REPO/www/"
[[ -f "$SHELL_REPO/config.xml" ]] && sed -i -E "s/(<widget[^>]* version=\")[^\"]+(\")/\1$VERSION\2/" "$SHELL_REPO/config.xml"

echo "── 7. commit + tag ───────────────────────────────────────"
git -C "$SHELL_REPO" add -A
if git -C "$SHELL_REPO" diff --cached --quiet; then echo "   nothing changed"; exit 0; fi
git -C "$SHELL_REPO" commit -q -m "m/ sync $VERSION"
git -C "$SHELL_REPO" tag -f "v$VERSION"
if [[ "$PUSH" == "--push" ]]; then git -C "$SHELL_REPO" push origin HEAD && git -C "$SHELL_REPO" push -f origin "v$VERSION"; echo "   pushed v$VERSION"; else echo "   committed + tagged v$VERSION (re-run with --push)"; fi

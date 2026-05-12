#!/usr/bin/env python3
"""
audit_mobile_parity.py — desktop↔mobile gap inventory generator.

Scans a child app's desktop source for five categories of features and
checks whether each has a corresponding implementation in the mobile SPA.
Posts the results to the admin DB via apiBulkUpsertMobileParity so the
admin/views/mobile_parity.php browser can render the diff.

Categories:

  page    — views/<name>.php files (top-level routed screens). Mobile
            "wired" means index.html has a <section> / data-route /
            hash route name matching the page slug.

  action  — `if ($_POST['action'] == 'X')` handlers in
            include/actions/. Mobile "wired" means JS calls
            apiPost('X') or apiClient.post('X').

  script  — <script src="assets/js/..."> tags in views/template.php.
            Mobile "wired" means mobile/index.html has the same JS
            file (by basename), via either <script src="js/...">
            or mobile/js/<basename> existing.

  snippet — <?php include 'snippets/X.php' ?> in views/template.php.
            Mobile "wired" means mobile/index.html contains some
            marker for the snippet (best-effort: snippet name as
            id/class).

  widget  — Manual allowlist of platform-level shell elements
            (token balance, notification badge, color-mode toggle,
            error reporter, feedback tab, …). These don't follow
            a single grep-able pattern; they're seeded here and
            updated when the inventory grows.

Usage:

  python3 audit_mobile_parity.py \\
    --source-app pwt \\
    --desktop-root /home/jeevan/Desktop/pwt \\
    --mobile-root  /home/jeevan/Desktop/pwt/mobile \\
    --wn-api-url   https://playwithtarot.com/admin/api/index.php \\
    --wn-api-key   wn_sk_...
"""
import argparse, os, re, json, sys, urllib.request, urllib.parse, glob

# ─── Manual widget allowlist ────────────────────────────────────────────
# Each entry: (feature_key, feature_name, priority, mobile_indicator).
# mobile_indicator is a substring; if present anywhere under mobile_root
# (HTML or JS), the widget is considered 'wired'.
WIDGETS = [
    ('token_balance',     'Token balance (sidebar footer)', 'high',
        ['token-balance', 'getTokenBalance']),
    ('notifications_bell','Notifications bell + dropdown',  'medium',
        ['notification-bell', "apiPost('getNotifications", 'getNotifications']),
    ('color_mode_toggle', 'Dark / light mode toggle',       'low',
        ['colorModeToggle', 'wn_color_mode']),
    ('feedback_tab',      'Floating feedback tab',          'medium',
        ['feedbackTabTrigger', 'feedbackOffcanvas', "submitUserFeedback"]),
    ('error_reporter',    'Client-side error reporter',     'high',
        ['error-reporter', 'window.onerror', 'reportClientError']),
    ('user_avatar_menu',  'User avatar / account menu',     'low',
        ['userMenuTrigger', 'data-user-menu']),
]

# Catches both `$_POST['action'] == 'X'` and the `($_POST['action'] ?? '') == 'X'`
# null-coalesce form (and the `($_GET['action'] ?? $_POST['action'] ?? '')` mix
# used by audio/oracle actions). [^=] lets the null-coalesce tail slide by.
ACTION_DECL_RX  = re.compile(
    r"""\$_POST\[['"]action['"]\][^=]*?==\s*['"]([a-zA-Z_][a-zA-Z0-9_]*)['"]""")
APIPOST_RX      = re.compile(r"""apiPost\s*\(\s*['"]([a-zA-Z_][a-zA-Z0-9_]*)['"]""")
APICLIENT_RX    = re.compile(r"""apiClient\s*\.\s*post\s*\(\s*['"]([a-zA-Z_][a-zA-Z0-9_]*)['"]""")
# Two ways desktop loads JS: literal src="path/X.js" or asset_v('path/X.js')
# inside a <?= ?> tag. We scan template.php with both.
TEMPLATE_SCRIPT_LITERAL_RX = re.compile(r"""<script[^>]*?\bsrc=['"]([^'"<>]+\.js)(?:\?[^'"]*)?['"]""")
TEMPLATE_SCRIPT_ASSETV_RX  = re.compile(r"""asset_v\(\s*['"]([^'"]+\.js)['"]""")
# Snippet includes are typically `include(__DIR__ . '/../snippets/X.php')` —
# the leading dir prefix means we can't anchor on a quote.
TEMPLATE_SNIPPET_RX = re.compile(r"""include[^;]*?snippets/([a-zA-Z0-9_-]+)\.php""")

def read_text(path):
    try:
        with open(path, 'r', encoding='utf-8', errors='replace') as f:
            return f.read()
    except (FileNotFoundError, IsADirectoryError):
        return ''

def grep_dir_text(root, max_files=2000):
    """Concatenate text content of every .php/.js/.html file under root."""
    buf = []
    count = 0
    for dirpath, _, files in os.walk(root):
        # Skip noisy dirs
        if any(seg in dirpath for seg in ('/node_modules/','/.git/','/vendor/')):
            continue
        for name in files:
            if not name.endswith(('.php','.js','.html','.tsx','.jsx')):
                continue
            count += 1
            if count > max_files: return '\n'.join(buf)
            buf.append(read_text(os.path.join(dirpath, name)))
    return '\n'.join(buf)

def scan_pages(desktop_root):
    """Each views/*.php (except template/404) is a 'page'."""
    out = []
    views_dir = os.path.join(desktop_root, 'views')
    if not os.path.isdir(views_dir): return out
    for f in sorted(os.listdir(views_dir)):
        if not f.endswith('.php'): continue
        slug = f[:-4]
        if slug in ('template','404'): continue
        out.append({
            'feature_key':    slug,
            'feature_name':   slug.replace('-',' ').replace('_',' ').title(),
            'desktop_source': f'views/{f}',
        })
    return out

def scan_actions(desktop_root):
    """Walk include/actions/ for $_POST['action']==X declarations."""
    out = []
    seen = {}
    actions_dir = os.path.join(desktop_root, 'include', 'actions')
    if not os.path.isdir(actions_dir): return out
    for dirpath, _, files in os.walk(actions_dir):
        for name in files:
            if not name.endswith('.php'): continue
            path = os.path.join(dirpath, name)
            text = read_text(path)
            for m in ACTION_DECL_RX.finditer(text):
                a = m.group(1)
                rel = os.path.relpath(path, desktop_root)
                seen.setdefault(a, rel)
    for a, src in sorted(seen.items()):
        out.append({
            'feature_key':    a,
            'feature_name':   a,
            'desktop_source': src,
        })
    return out

def scan_scripts(desktop_root):
    """<script src=...> tags in views/template.php — both literal and asset_v()."""
    out = []
    tpl = read_text(os.path.join(desktop_root, 'views', 'template.php'))
    if not tpl: return out
    seen = set()
    sources = list(TEMPLATE_SCRIPT_LITERAL_RX.findall(tpl)) \
            + list(TEMPLATE_SCRIPT_ASSETV_RX.findall(tpl))
    for src in sources:
        clean = src.split('?')[0]
        basename = os.path.basename(clean)
        if not basename.endswith('.js'): continue
        # CDN-loaded vendor scripts have full http URLs — skip them, they
        # ride along on mobile too via separate <script> tags in index.html.
        if clean.startswith(('http://','https://','//')): continue
        if basename in seen: continue
        seen.add(basename)
        out.append({
            'feature_key':    basename,
            'feature_name':   basename,
            'desktop_source': clean,
        })
    return out

def scan_snippets(desktop_root):
    """<?php include 'snippets/X.php' ?> in views/template.php."""
    out = []
    tpl = read_text(os.path.join(desktop_root, 'views', 'template.php'))
    if not tpl: return out
    seen = set()
    for name in TEMPLATE_SNIPPET_RX.findall(tpl):
        if name in seen: continue
        seen.add(name)
        out.append({
            'feature_key':    name,
            'feature_name':   name.replace('_',' ').replace('-',' ').title(),
            'desktop_source': f'snippets/{name}.php',
        })
    return out

def mobile_has_page(mobile_text, slug):
    """Heuristics: hash-route #slug, data-route="slug", or section id."""
    patterns = [
        f'href="#{slug}"', f"href='#{slug}'",
        f'#{slug}"', f"data-route=\"{slug}\"", f"data-route='{slug}'",
        f'id="{slug}-view"', f"id='{slug}-view'",
        f'id="{slug}-section"', f"id='{slug}-section'",
        f"'{slug}':", f'"{slug}":',     # router map keys
    ]
    return any(p in mobile_text for p in patterns)

def mobile_has_action(mobile_text, action):
    return action in mobile_text  # loose but practical

def mobile_has_script(mobile_root, basename):
    # Mobile carries either <script src="js/basename"> or the file just exists in mobile/js/
    if os.path.isfile(os.path.join(mobile_root, 'js', basename)): return True
    return False

def mobile_has_snippet(mobile_text, snippet_name):
    """Snippets are PHP-only on desktop. Mobile substitutes them with
    inline HTML or JS modules. Look for the snippet name as an id/class."""
    name = snippet_name.replace('_','-')
    return any(p in mobile_text for p in (
        f'id="{name}"', f"id='{name}'",
        f'class="{name}"', f"class='{name}'",
        f'feedback_tab' if snippet_name == 'feedback_tab' else snippet_name,
    ))

def mobile_has_widget(mobile_text, indicators):
    return any(ind in mobile_text for ind in indicators)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--source-app', required=True)
    ap.add_argument('--desktop-root', required=True)
    ap.add_argument('--mobile-root',  required=True)
    ap.add_argument('--wn-api-url',   required=True)
    ap.add_argument('--wn-api-key',   required=True)
    ap.add_argument('--dry-run', action='store_true',
                    help='Print rows but do not POST to admin.')
    args = ap.parse_args()

    if not os.path.isdir(args.desktop_root):
        print(f'ERR: desktop-root {args.desktop_root} not found', file=sys.stderr)
        sys.exit(1)

    have_mobile = os.path.isdir(args.mobile_root)
    mobile_text = grep_dir_text(args.mobile_root) if have_mobile else ''

    rows = []

    # Pages
    for p in scan_pages(args.desktop_root):
        wired = have_mobile and mobile_has_page(mobile_text, p['feature_key'])
        rows.append({**p,
            'source_app':    args.source_app,
            'category':      'page',
            'mobile_status': 'wired' if wired else 'missing',
            'priority':      'medium',
        })

    # Actions
    for a in scan_actions(args.desktop_root):
        wired = have_mobile and mobile_has_action(mobile_text, a['feature_key'])
        rows.append({**a,
            'source_app':    args.source_app,
            'category':      'action',
            'mobile_status': 'wired' if wired else 'missing',
            'priority':      'medium',
        })

    # Scripts
    for s in scan_scripts(args.desktop_root):
        wired = have_mobile and mobile_has_script(args.mobile_root, s['feature_key'])
        rows.append({**s,
            'source_app':    args.source_app,
            'category':      'script',
            'mobile_status': 'wired' if wired else 'missing',
            'priority':      'low',
        })

    # Snippets
    for sn in scan_snippets(args.desktop_root):
        wired = have_mobile and mobile_has_snippet(mobile_text, sn['feature_key'])
        rows.append({**sn,
            'source_app':    args.source_app,
            'category':      'snippet',
            'mobile_status': 'wired' if wired else 'missing',
            'priority':      'medium',
        })

    # Widgets (manual allowlist)
    for key, name, prio, indicators in WIDGETS:
        wired = have_mobile and mobile_has_widget(mobile_text, indicators)
        rows.append({
            'source_app':    args.source_app,
            'category':      'widget',
            'feature_key':   key,
            'feature_name':  name,
            'desktop_source':'views/template.php',
            'mobile_status': 'wired' if wired else 'missing',
            'priority':      prio,
        })

    # Summary
    by_cat_status = {}
    for r in rows:
        by_cat_status.setdefault(r['category'], {'missing':0,'wired':0,'partial':0,'n_a':0})
        by_cat_status[r['category']][r['mobile_status']] = \
            by_cat_status[r['category']].get(r['mobile_status'],0) + 1
    print(f'audit_mobile_parity: {args.source_app}')
    for cat, sts in by_cat_status.items():
        print(f'  {cat:8s}  ' + '  '.join(f'{k}={v}' for k,v in sts.items() if v))
    print(f'  TOTAL    {len(rows)} rows')

    if args.dry_run:
        print('\n--- dry-run, first 5 rows ---')
        for r in rows[:5]:
            print(json.dumps(r))
        return

    # POST in chunks of 50 to avoid hitting body limits.
    posted = 0
    for i in range(0, len(rows), 50):
        chunk = rows[i:i+50]
        body = urllib.parse.urlencode({
            'action': 'apiBulkUpsertMobileParity',
            'rows':   json.dumps(chunk),
        }).encode()
        req = urllib.request.Request(args.wn_api_url, data=body, method='POST')
        req.add_header('Authorization', 'Bearer ' + args.wn_api_key)
        with urllib.request.urlopen(req, timeout=60) as r:
            resp = json.loads(r.read().decode())
        if resp.get('error'):
            print(f'  chunk[{i}:{i+50}] ERROR: {resp["error"]}', file=sys.stderr)
            sys.exit(1)
        posted += resp.get('results',{}).get('inserted', 0)
    print(f'posted {posted} rows to {args.wn_api_url}')

if __name__ == '__main__':
    main()

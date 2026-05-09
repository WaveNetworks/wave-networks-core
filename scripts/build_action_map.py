#!/usr/bin/env python3
"""
build_action_map.py — Static-analysis derivation of use_cases.

For each declared `if ($_POST['action'] == 'X')` handler in the
admin core + a child app's source tree, find every UI trigger that
fires it (apiPost('X'), <input name="action" value="X">, onclick
handlers, data-action attrs), map the trigger back to its hosting
view page, and emit a deterministic use_case row covering the
journey login → dashboard → host page → action.

Why this matters: action-log derivation is non-deterministic (depends
on whether a real user happened to walk a flow). Static code analysis
gives us 100% coverage of declared actions regardless of who's been
clicking. Two layers complement each other — log-based for
multi-step organic journeys, static for "every action is at least
smoke-tested".

Output:
  - One UPSERT into admin's use_case table per (action, host_page)
    pair. Slug shaped like 'action-saveItem-on-items'.
  - Stable across runs as long as source code is stable.

Usage:
    build_action_map.py --source-app nokemo \\
        --source-roots /mnt/data/nokemo,/mnt/data/nokemo/admin \\
        --wn-api-url https://nokemo.com/admin/api/index.php \\
        --wn-api-key wn_sk_...

    build_action_map.py --source-app elevateher \\
        --source-roots /mnt/data/nokemo/.tmp-elevateher,/mnt/data/nokemo/admin \\
        --wn-api-url https://dswa.org/admin/api/index.php \\
        --wn-api-key wn_sk_...

Re-runs are idempotent: apiUpsertUseCase keys on (source_app, slug).
"""

import argparse
import json
import logging
import os
import re
import sys
from collections import defaultdict
from pathlib import Path

import requests


log = logging.getLogger("build_action_map")
SESSION = requests.Session()


# ── Pattern library ────────────────────────────────────────────────────────

# Action declarations — handles all the variations actually used in code:
#   if ($_POST['action'] == 'X')
#   if (($_POST['action'] ?? '') === 'X')
#   if (($_POST['action'] ?? '') == 'X')
#   if (($action ?? null) == 'X')
DECL_PATTERNS = [
    re.compile(r"""\$_POST\[\s*['"]action['"]\s*\]\s*\?\?\s*['"]['"]\s*\)\s*[=!]==?\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"]"""),
    re.compile(r"""\$_POST\[\s*['"]action['"]\s*\]\s*[=!]==?\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"]"""),
    re.compile(r"""\$action\s*\?\?\s*null\s*\)\s*[=!]==?\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"]"""),
    re.compile(r"""\$_GET\[\s*['"]action['"]\s*\]\s*\?\?\s*\$_POST\[\s*['"]action['"]\s*\]\s*\?\?\s*['"]['"]\s*\)\s*[=!]==?\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"]"""),
]

# UI triggers that invoke an action by name.
TRIGGER_PATTERNS = [
    # apiPost('actionName', ... )  /  apiPost("actionName", ... )
    (re.compile(r"""apiPost\s*\(\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"]"""),
     'apiPost'),
    # <input ... name="action" value="X" ...>  (form hidden input)
    (re.compile(r"""<input\b[^>]*\bname\s*=\s*['"]action['"][^>]*\bvalue\s*=\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"]"""),
     'form_input'),
    # <input ... value="X" ... name="action" ...>  (reverse attr order)
    (re.compile(r"""<input\b[^>]*\bvalue\s*=\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"][^>]*\bname\s*=\s*['"]action['"]"""),
     'form_input'),
    # data-action="X" attr
    (re.compile(r"""data-action\s*=\s*['"]([a-zA-Z][a-zA-Z0-9_]*)['"]"""),
     'data_attr'),
]

# When indexing source files, skip these directories (vendor / generated /
# unrelated). Each is matched as a path-segment.
SKIP_DIRS = {
    'node_modules', 'vendor', '.git', '.github', 'tests', 'test',
    '__pycache__', 'dist', 'build', '.tmp-child-app',
}

# Actions named here are background-only (cron, MCP, internal) — emit as
# category=api so they don't fail the Playwright suite.
API_ONLY_ACTIONS = {
    'apiCheckSession', 'apiPing', 'apiHealth',
}


# ── File walking ───────────────────────────────────────────────────────────

def iter_source_files(root: Path, suffixes=('.php', '.js')):
    """Yield every source file under root, skipping vendor/node_modules/etc."""
    for path in root.rglob('*'):
        if not path.is_file():
            continue
        if any(part in SKIP_DIRS for part in path.parts):
            continue
        if path.suffix in suffixes:
            yield path


# ── Pass 1: action declarations ────────────────────────────────────────────

def find_action_declarations(roots):
    """Return dict[action_name] = list of (path, line, root_label)."""
    declarations = defaultdict(list)
    for label, root in roots:
        if not root.is_dir():
            log.warning("source root not found: %s", root)
            continue
        for path in iter_source_files(root, suffixes=('.php',)):
            try:
                text = path.read_text(errors='replace')
            except OSError:
                continue
            # Action handlers live in actions/ directories; everything
            # else is unlikely to declare action names. This keeps the
            # scan focused.
            if '/actions/' not in str(path) and '/include/' not in str(path):
                continue
            for line_no, line in enumerate(text.splitlines(), start=1):
                for pat in DECL_PATTERNS:
                    for name in pat.findall(line):
                        declarations[name].append((path, line_no, label))
                        break  # first matching pattern wins per line
    return declarations


# ── Pass 2: trigger sites ──────────────────────────────────────────────────

def find_triggers(roots, action_names):
    """Return dict[action_name] = list of (path, line, kind, root_label)."""
    triggers = defaultdict(list)
    name_set = set(action_names)
    for label, root in roots:
        if not root.is_dir():
            continue
        for path in iter_source_files(root, suffixes=('.php', '.js')):
            try:
                text = path.read_text(errors='replace')
            except OSError:
                continue
            for line_no, line in enumerate(text.splitlines(), start=1):
                for pat, kind in TRIGGER_PATTERNS:
                    for name in pat.findall(line):
                        if name in name_set:
                            triggers[name].append((path, line_no, kind, label))
    return triggers


# ── Pass 3: trigger → hosting page ─────────────────────────────────────────

def host_pages_for_trigger(trigger_path: Path, view_index, snippet_index,
                          js_index):
    """Return a list of page slugs that host this trigger.

    A trigger is hosted by a page if:
      - the trigger file IS a view file (admin/views/X.php → page=X), OR
      - the trigger file is a snippet/include file used by view(s), OR
      - the trigger file is a JS asset referenced by view(s)
    """
    pages = set()
    parts = trigger_path.parts
    # Direct view hit
    if 'views' in parts:
        idx = parts.index('views')
        if idx + 1 < len(parts):
            view_file = parts[idx + 1]
            if view_file.endswith('.php'):
                pages.add(view_file[:-4])
    # Snippet — find views that include it
    if 'snippets' in parts and trigger_path.suffix == '.php':
        snippet_name = trigger_path.name
        for view, includes in snippet_index.items():
            if snippet_name in includes:
                pages.add(view)
    # JS asset — find views that load it via <script src=...>
    if trigger_path.suffix == '.js':
        js_basename = trigger_path.name
        for view, scripts in js_index.items():
            if any(js_basename in s for s in scripts):
                pages.add(view)
    return sorted(pages)


def build_view_indexes(roots):
    """Produce two maps:
       snippet_index[view_slug] = set of snippet filenames it includes
       js_index[view_slug] = set of JS src strings it loads
    """
    snippet_index = defaultdict(set)
    js_index = defaultdict(set)
    snippet_re = re.compile(r"""include(?:_once)?\s*\(\s*[^)]*?['"]([^'"]+\.php)['"]""")
    script_re = re.compile(r"""<script[^>]*\bsrc\s*=\s*['"]([^'"]+)['"]""")
    for label, root in roots:
        views_dir = root / 'views'
        if not views_dir.is_dir():
            continue
        for view in views_dir.glob('*.php'):
            slug = view.stem
            try:
                text = view.read_text(errors='replace')
            except OSError:
                continue
            for inc in snippet_re.findall(text):
                snippet_index[slug].add(Path(inc).name)
            for src in script_re.findall(text):
                js_index[slug].add(src)
    return snippet_index, js_index


# ── Slug + name composition ────────────────────────────────────────────────

def compose_slug(action: str, page: str) -> str:
    safe_action = re.sub(r'[^a-zA-Z0-9]+', '-', action).strip('-').lower()
    safe_page   = re.sub(r'[^a-zA-Z0-9]+', '-', page).strip('-').lower() or 'unknown'
    return f"action-{safe_action}-on-{safe_page}"


def humanize(s: str) -> str:
    # CamelCase + snake_case → words
    s = re.sub(r'([a-z0-9])([A-Z])', r'\1 \2', s)
    s = s.replace('_', ' ').replace('-', ' ').strip()
    return s[:1].upper() + s[1:] if s else s


def compose_name(action: str, page: str) -> str:
    return f"{humanize(action)} (from {humanize(page)})"


def compose_description(action: str, page: str, decls, triggers) -> str:
    decl_count = len(decls)
    trig_count = len(triggers)
    return (
        f"Static-derived: action `{action}` declared in "
        f"{decl_count} handler{'s' if decl_count != 1 else ''}, "
        f"triggered from {trig_count} site{'s' if trig_count != 1 else ''}. "
        f"Reachable journey: login → dashboard → {page} → fire {action}."
    )


# ── Emit use_case rows ─────────────────────────────────────────────────────

def upsert_use_case(api_url, api_key, source_app, slug, name, description,
                    starting_page, ending_action, action_path, category):
    data = {
        "action": "apiUpsertUseCase",
        "source_app": source_app,
        "slug": slug,
        "name": name,
        "description": description,
        "requires_login": "1",
        "starting_page": starting_page or "",
        "ending_action": ending_action or "",
        "action_path": json.dumps(action_path),
        "test_category": category,
        "derived_from_log_count": "0",
    }
    r = SESSION.post(api_url, data=data,
                     headers={"Authorization": f"Bearer {api_key}"},
                     timeout=30)
    body = r.json()
    if r.status_code >= 400 or body.get("error"):
        raise RuntimeError(f"upsert {slug}: {body.get('error') or r.text[:200]}")
    return body.get("results") or {}


# ── Main ───────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--source-app", required=True,
                    help="The source_app slug to register use_cases under.")
    ap.add_argument("--source-roots", required=True,
                    help="Comma-separated source tree roots to scan, e.g. "
                         "/mnt/data/nokemo/.tmp-elevateher,/mnt/data/nokemo/admin")
    ap.add_argument("--wn-api-url",
                    default=os.environ.get("WN_API_URL"))
    ap.add_argument("--wn-api-key",
                    default=os.environ.get("WN_API_KEY"))
    ap.add_argument("--dry-run", action="store_true",
                    help="Print emitted use_cases; do not UPSERT.")
    ap.add_argument("--verbose", "-v", action="store_true")
    args = ap.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    if not args.dry_run and (not args.wn_api_url or not args.wn_api_key):
        log.error("--wn-api-url and --wn-api-key (or env vars) are required "
                  "unless --dry-run.")
        return 2

    roots = []
    for raw in args.source_roots.split(","):
        raw = raw.strip()
        if not raw:
            continue
        p = Path(raw).resolve()
        label = p.name
        roots.append((label, p))
    if not roots:
        log.error("no source roots given")
        return 2

    log.info("scanning %d root(s) for source_app=%s …",
             len(roots), args.source_app)
    for label, root in roots:
        log.info("  • %s  (%s)", label, root)

    log.info("pass 1/3 — finding action declarations")
    declarations = find_action_declarations(roots)
    log.info("  found %d distinct action(s)", len(declarations))

    log.info("pass 2/3 — finding UI triggers for each action")
    triggers = find_triggers(roots, declarations.keys())
    triggered = sum(1 for a in declarations if a in triggers)
    log.info("  %d/%d action(s) have at least one UI trigger",
             triggered, len(declarations))

    log.info("pass 3/3 — building view/snippet/js index")
    snippet_index, js_index = build_view_indexes(roots)
    log.info("  indexed %d view→snippets and %d view→scripts entries",
             sum(len(v) for v in snippet_index.values()),
             sum(len(v) for v in js_index.values()))

    # Compose use_case rows
    emitted = 0
    skipped_api_only = 0
    skipped_no_host = 0
    for action, decls in sorted(declarations.items()):
        if action in API_ONLY_ACTIONS:
            log.debug("api-only %s — emitting category=api", action)
            slug = compose_slug(action, "api")
            if not args.dry_run:
                upsert_use_case(
                    args.wn_api_url, args.wn_api_key,
                    args.source_app, slug,
                    f"{humanize(action)} (API only)",
                    compose_description(action, "api", decls, []),
                    None, action,
                    [{"step": 1, "kind": "action", "action": action,
                      "trigger_kind": "api_only"}],
                    "api",
                )
            emitted += 1
            skipped_api_only += 1
            continue

        # Find pages for each trigger
        trig_list = triggers.get(action, [])
        host_pages = set()
        for trig_path, _line, _kind, _label in trig_list:
            for page in host_pages_for_trigger(trig_path, None, snippet_index,
                                                js_index):
                host_pages.add(page)

        if not host_pages:
            # No UI trigger — likely cron / MCP / API-only declaration we
            # haven't tagged yet. Emit as category=api so the Playwright
            # suite skips it but admin still has visibility.
            log.debug("no host page for %s — emitting category=api", action)
            slug = compose_slug(action, "api")
            if not args.dry_run:
                upsert_use_case(
                    args.wn_api_url, args.wn_api_key,
                    args.source_app, slug,
                    f"{humanize(action)} (no UI trigger found)",
                    compose_description(action, "api", decls, trig_list),
                    None, action,
                    [{"step": 1, "kind": "action", "action": action,
                      "trigger_kind": "no_ui_trigger"}],
                    "api",
                )
            emitted += 1
            skipped_no_host += 1
            continue

        # Emit one use_case per (action, host_page) pair
        for page in sorted(host_pages):
            slug = compose_slug(action, page)
            name = compose_name(action, page)
            desc = compose_description(action, page, decls, trig_list)
            action_path = [
                {"step": 1, "kind": "view", "page": "login"},
                {"step": 2, "kind": "view", "page": "dashboard"},
                {"step": 3, "kind": "view", "page": page},
                {"step": 4, "kind": "action", "action": action,
                 "trigger_kind": trig_list[0][2] if trig_list else "unknown"},
            ]
            log.info("  → %s", slug)
            if not args.dry_run:
                upsert_use_case(
                    args.wn_api_url, args.wn_api_key,
                    args.source_app, slug, name, desc,
                    "dashboard", action, action_path, "feature",
                )
            emitted += 1

    log.info(
        "Done. emitted %d use_case row(s) for source_app=%s "
        "(of which %d api-only, %d no-host) %s",
        emitted, args.source_app,
        skipped_api_only, skipped_no_host,
        "(dry-run — nothing written)" if args.dry_run else "",
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

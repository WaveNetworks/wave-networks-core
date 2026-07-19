#!/usr/bin/env python3
"""
generate_playwright_specs.py — Emit Playwright .spec.ts files from
use_case rows.

For each use_case row in the admin-core DB:
  - read its action_path JSON (one record per step the user took)
  - filter to navigation steps (action='view' on a known page)
  - emit a TypeScript spec that logs in as nokemo@nokemo.com,
    visits each page in order, screenshots before+after, and
    asserts no console errors or 5xx responses

POST/UI-action steps are skipped in v1 — turning a recorded
`saveItem` action into a Playwright click requires a per-action
UI-selector map we don't yet have. The smoke-test (navigation +
screenshot) catches the most common regression: a page 500s, a JS
error breaks render, or styling regresses visibly.

Output layout (per the user's brief — "screenshot storage with the
child app filesystem is best ... saved in git and pushed to server
with the deploy actions"):

    {child_repo_root}/
      tests/
        use-cases/
          {slug}.spec.ts        # one spec per use_case
        screenshots/             # populated by the runner, gitignored
          .gitkeep
        playwright.config.ts     # written once per repo
        package.json             # written once per repo

Usage:
    generate_playwright_specs.py \\
        --source-app nokemo \\
        --output-dir /mnt/data/nokemo \\
        --base-url https://nokemo.com

The output-dir is the CHILD APP'S repo root; the script writes
relative to there. Re-running is idempotent — each spec gets
rewritten from the current use_case row.
"""

import argparse
import json
import logging
import os
import re
import sys
import textwrap
from pathlib import Path

import requests

WN_API_URL = os.environ.get(
    "WN_API_URL", "https://nokemo.com/admin/api/index.php"
)
WN_API_KEY = os.environ.get(
    "WN_API_KEY",
    "wn_sk_fYiOEvHPb8hMX2w3jsSYgkGo9ClO70pK2atiQ9rzqn23OIqgGOmyHuaOyh",
)

log = logging.getLogger("generate_playwright_specs")

SESSION = requests.Session()


def api(action: str, **params) -> dict:
    data = {"action": action,
            **{k: str(v) for k, v in params.items() if v is not None}}
    r = SESSION.post(WN_API_URL, data=data,
                     headers={"Authorization": f"Bearer {WN_API_KEY}"},
                     timeout=60)
    body = r.json()
    if r.status_code >= 400 or body.get("error"):
        raise RuntimeError(f"api {action}: {body.get('error') or r.text[:200]}")
    return body.get("results") or {}


# ── Step → Playwright translation ──────────────────────────────────────
def is_navigation_step(step: dict) -> bool:
    """A 'view' step on a real page is a navigation we know how to replay
    (goto + screenshot). Other steps (POST forms, JS apiPost calls) need a
    UI selector map we don't yet have, so we skip them in v1.

    The static analyzer (build_action_map.py) tags view steps with
    `kind: "view"`; the older log-derived path used `action: "view"`.
    Accept either. Skip `login` — the spec already authenticates in
    beforeEach, so a page=login goto is redundant/misleading."""
    kind = step.get("kind") or step.get("action")
    page = (step.get("page") or "").strip()
    return kind == "view" and page not in ("", "login")


def ts_escape(s: str) -> str:
    """Single-quoted TypeScript string literal."""
    return s.replace("\\", "\\\\").replace("'", "\\'")


def emit_spec(use_case: dict, source_app: str, base_url: str,
              list_pages: frozenset = frozenset()) -> str:
    """Render a self-contained .spec.ts for one use_case.

    list_pages is the set of all page names that appear as view steps across
    the whole use_case set. A host page X is treated as a *detail* page (needs
    a concrete ?id=) when its plural `Xs` is also a page — e.g. circle+circles,
    dinner+dinners. Detail steps resolve a real id from a link at runtime
    instead of navigating to a bare page= that renders a "not found" state.
    """
    slug = use_case["slug"]
    name = use_case.get("name") or slug
    desc = use_case.get("description") or ""
    path = use_case.get("action_path") or "[]"
    if isinstance(path, str):
        try:
            path = json.loads(path)
        except json.JSONDecodeError:
            path = []
    nav_steps = [s for s in path if is_navigation_step(s)]

    # The login URL is admin-core-relative; subdir for child apps
    # follows the same /<app>/app/... convention.
    app_prefix = "/admin" if source_app == "admin" else f"/{source_app}"
    login_url = f"{base_url}/admin/auth/login.php"

    base = f"{base_url}{app_prefix}/app/index.php"
    step_lines = []
    for i, s in enumerate(nav_steps):
        page_value = ts_escape(s["page"])
        shot = f"screenshots/${{runId}}/{slug}/{i+1:02d}_{page_value}.png"
        # Detail page? (its plural list page also exists in the journey set)
        is_detail = (s["page"].strip() + "s") in list_pages
        if is_detail:
            sel = ('a[href*="page=' + page_value + '&id="], '
                   'a[href*="page=' + page_value + '&amp;id="]')
            step_lines.append(
                f"    await test.step('step {i+1}: page={page_value}', async () => {{\n"
                f"      // detail page — resolve a real ?id= from a link (fall back to the list page,\n"
                f"      // then to the bare page) so we screenshot a real record, not a 'not found' state.\n"
                f"      let target = '{base}?page={page_value}';\n"
                f"      const sel = '{sel}';\n"
                f"      // page.$() is querySelector semantics — returns null immediately when no\n"
                f"      // link is present. (page.getAttribute() auto-waits the full 30s timeout on\n"
                f"      // a missing selector; two of those blew past the 60s test cap and timed out\n"
                f"      // every detail-page spec whose ?id= link was absent, e.g. an empty list.)\n"
                f"      let el = await page.$(sel);\n"
                f"      if (!el) {{\n"
                f"        await page.goto('{base}?page={page_value}s', {{ waitUntil: 'load' }}).catch(() => {{}});\n"
                f"        el = await page.$(sel);\n"
                f"      }}\n"
                f"      const href = el ? await el.getAttribute('href') : null;\n"
                f"      if (href) target = new URL(href, page.url()).href;\n"
                f"      const resp = await page.goto(target, {{ waitUntil: 'load' }});\n"
                f"      expect(resp?.status() ?? 0, 'HTTP status').toBeLessThan(500);\n"
                f"      await page.screenshot({{ path: `{shot}`, fullPage: true }});\n"
                f"    }});"
            )
        else:
            target = f"{base}?page={page_value}"
            step_lines.append(
                f"    await test.step('step {i+1}: page={page_value}', async () => {{\n"
                f"      const resp = await page.goto('{target}', {{ waitUntil: 'load' }});\n"
                f"      expect(resp?.status() ?? 0, 'HTTP status').toBeLessThan(500);\n"
                f"      await page.screenshot({{ path: `{shot}`, fullPage: true }});\n"
                f"    }});"
            )

    if not step_lines:
        step_lines.append(
            "    test.skip(true, 'no navigation steps in this use_case');"
        )

    body = textwrap.dedent(f"""\
        // ──────────────────────────────────────────────────────────────
        // {ts_escape(name)}
        // {ts_escape(desc)}
        // ──────────────────────────────────────────────────────────────
        // AUTO-GENERATED by admin/scripts/generate_playwright_specs.py
        // from use_case slug={slug!r} (source_app={source_app!r}).
        // Edits to this file will be overwritten on the next regeneration.
        // ──────────────────────────────────────────────────────────────
        import {{ test, expect }} from '@playwright/test';

        const BASE = process.env.BASE_URL || '{base_url}';
        const TEST_EMAIL = process.env.TEST_USER_EMAIL || 'nokemo@nokemo.com';
        const TEST_PASSWORD = process.env.TEST_USER_PASSWORD || '';
        const runId = process.env.TEST_RUN_ID || new Date()
            .toISOString().replace(/[:T]/g, '-').slice(0, 19);

        const consoleErrors: string[] = [];

        test.beforeEach(async ({{ page }}) => {{
          consoleErrors.length = 0;
          page.on('console', msg => {{
            if (msg.type() === 'error') consoleErrors.push(msg.text());
          }});

          // Login as the test user. Phase 3 service-impersonation will
          // replace this with a token-exchange flow; for v1 we use the
          // standard form login with a runner-supplied password.
          if (!TEST_PASSWORD) {{
            test.skip(true, 'TEST_USER_PASSWORD env not set');
            return;
          }}
          await page.goto('{login_url}');
          await page.fill('input[name="email"]',    TEST_EMAIL);
          await page.fill('input[name="password"]', TEST_PASSWORD);
          await Promise.all([
            page.waitForNavigation({{ waitUntil: 'load' }}),
            page.click('button[type="submit"]'),
          ]);
        }});

        test('{ts_escape(slug)}', async ({{ page }}) => {{
        {chr(10).join(step_lines)}

          expect(consoleErrors, 'console errors during run').toEqual([]);
        }});
        """)
    return body


# ── Once-per-repo scaffolding ──────────────────────────────────────────
PLAYWRIGHT_CONFIG_TS = """\
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'use-cases',
  timeout: 60_000,
  retries: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    headless: true,
    baseURL: process.env.BASE_URL,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace:  'retain-on-failure',
  },
});
"""

PACKAGE_JSON = """\
{
  "name": "use-case-tests",
  "private": true,
  "version": "0.1.0",
  "scripts": {
    "test":         "playwright test",
    "test:headed":  "playwright test --headed",
    "test:report":  "playwright show-report"
  },
  "devDependencies": {
    "@playwright/test": "^1.48.0",
    "typescript": "^5.4.0"
  }
}
"""


def write_scaffolding(repo_root: Path) -> None:
    tests = repo_root / "tests"
    tests.mkdir(exist_ok=True)
    (tests / "use-cases").mkdir(exist_ok=True)
    (tests / "screenshots").mkdir(exist_ok=True)

    cfg = tests / "playwright.config.ts"
    if not cfg.exists():
        cfg.write_text(PLAYWRIGHT_CONFIG_TS)
        log.info("wrote %s", cfg.relative_to(repo_root))
    pkg = tests / "package.json"
    if not pkg.exists():
        pkg.write_text(PACKAGE_JSON)
        log.info("wrote %s", pkg.relative_to(repo_root))
    keep = tests / "screenshots" / ".gitkeep"
    if not keep.exists():
        keep.write_text("")
    gi = tests / ".gitignore"
    if not gi.exists():
        gi.write_text("node_modules/\nplaywright-report/\ntest-results/\n")


# ── Main ──────────────────────────────────────────────────────────────
def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--source-app", required=True,
                    help="Child app slug (matches use_case.source_app).")
    ap.add_argument("--output-dir", required=True, type=Path,
                    help="Path to the child app's repo root. The script "
                         "writes into {repo}/tests/use-cases/.")
    ap.add_argument("--base-url", required=True,
                    help="Public base URL of the deployed child app, "
                         "e.g. https://nokemo.com")
    ap.add_argument("--limit", type=int, default=1000)
    ap.add_argument("--verbose", "-v", action="store_true")
    args = ap.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    if not args.output_dir.is_dir():
        log.error("output-dir %s does not exist", args.output_dir)
        return 2

    res = api("apiListUseCases", source_app=args.source_app, limit=args.limit)
    items = res.get("items") or []
    if not items:
        log.warning("no use_case rows for source_app=%s — run "
                    "derive_use_cases.py first", args.source_app)
        return 0

    write_scaffolding(args.output_dir)

    # Set of every page that appears as a view step — used to detect detail
    # pages (a host page X whose plural `Xs` is also a page needs a real ?id=).
    list_pages = set()
    for uc in items:
        p = uc.get("action_path") or "[]"
        if isinstance(p, str):
            try:
                p = json.loads(p)
            except json.JSONDecodeError:
                p = []
        for st in (p or []):
            if is_navigation_step(st):
                list_pages.add((st.get("page") or "").strip())
    list_pages = frozenset(list_pages)

    spec_dir = args.output_dir / "tests" / "use-cases"
    written = 0
    for uc in items:
        spec = emit_spec(uc, args.source_app, args.base_url.rstrip("/"), list_pages)
        out = spec_dir / f"{uc['slug']}.spec.ts"
        out.write_text(spec)
        log.info("  wrote %s", out.relative_to(args.output_dir))
        written += 1

    log.info("Done. %d spec(s) written to %s", written, spec_dir)
    log.info("Next: cd %s/tests && npm install && npx playwright install chromium",
             args.output_dir)
    return 0


if __name__ == "__main__":
    sys.exit(main())

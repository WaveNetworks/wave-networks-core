#!/usr/bin/env python3
"""
derive_use_cases.py — Cluster the test user's recent action log into
named "use cases" for each child app, then UPSERT them into the
admin-core `use_case` table.

Per `admin/CLAUDE.md`, all action logging routes through
`log_user_action()` → `user_action_log` (sharded). Test-account rows
never expire, so we can scan months of replayable journeys.

Pipeline per source_app:
  1. apiListTestSessionActions  → grouped sessions for the test user
  2. dedupe consecutive identical (page, action) pairs (drowns out
     auto-fired view re-renders + double-submits)
  3. cluster sessions by their normalized (page, action) sequence —
     each unique sequence becomes one use_case, with action_path
     storing the ordered step records (page/action/result) for the
     Playwright generator to consume later
  4. apiUpsertUseCase           → write the row, idempotent on
                                  source_app+slug

Usage:
  derive_use_cases.py --apps nokemo,elevateher,pwt
  derive_use_cases.py --apps nokemo --since 2026-04-20  --dry-run

The script is read-mostly safe: --dry-run skips the UPSERT step and
just prints what it would emit.
"""

import argparse
import hashlib
import json
import logging
import os
import re
import sys
from collections import defaultdict
from pathlib import Path

import requests

WN_API_URL = os.environ.get(
    "WN_API_URL",
    "https://nokemo.com/admin/api/index.php",
)
WN_API_KEY = os.environ.get(
    "WN_API_KEY",
    "wn_sk_fYiOEvHPb8hMX2w3jsSYgkGo9ClO70pK2atiQ9rzqn23OIqgGOmyHuaOyh",
)

log = logging.getLogger("derive_use_cases")

SESSION = requests.Session()


def api(action: str, **params) -> dict:
    data = {"action": action, **{k: str(v) for k, v in params.items() if v is not None}}
    r = SESSION.post(
        WN_API_URL, data=data,
        headers={"Authorization": f"Bearer {WN_API_KEY}"},
        timeout=120,
    )
    try:
        body = r.json()
    except ValueError:
        raise RuntimeError(f"non-JSON response from {action}: {r.text[:200]}")
    if r.status_code >= 400 or body.get("error"):
        raise RuntimeError(
            f"api {action} → {r.status_code}: {body.get('error') or r.text[:200]}"
        )
    return body.get("results", {}) or {}


# ── Step normalization ──────────────────────────────────────────────────
def step_key(row: dict) -> tuple:
    """Stable (page, action) tuple used both for dedup of consecutive
    repeats AND for sequence-based session clustering."""
    return ((row.get("page") or "").strip().lower(),
            (row.get("action") or "").strip())


def collapse_repeats(actions: list) -> list:
    """Drop consecutive duplicate (page, action) rows. Keeps the first
    occurrence (with its timestamp + duration). View renders that fire
    multiple times in a row collapse to a single step."""
    out = []
    last = None
    for row in actions:
        k = step_key(row)
        if k == last:
            continue
        out.append(row)
        last = k
    return out


def slugify(text: str, max_len: int = 80) -> str:
    text = re.sub(r"[^A-Za-z0-9]+", "-", text or "").strip("-").lower()
    return text[:max_len] or "unnamed"


# ── Use-case naming ─────────────────────────────────────────────────────
def name_for_sequence(steps: list) -> tuple:
    """Return (slug, display_name, description) for a given step list.
    The slug encodes the start page + last action so two flows that
    end on different actions get distinct cases.

    Heuristic naming today; a Claude-based naming pass can be wired in
    once we have a few cases to test against."""
    if not steps:
        return ("empty", "Empty session", "")
    first = steps[0]
    last = steps[-1]
    start = (first.get("page") or "home").strip().lower() or "home"
    end_action = (last.get("action") or last.get("page") or "view").strip()
    n = len(steps)

    slug = slugify(f"{start}-to-{end_action}-{n}")
    name = f"{start.replace('-', ' ').title()} → {end_action} ({n} steps)"
    desc = (
        f"Auto-derived from {n}-step sessions starting on '{start}' and "
        f"ending with action '{end_action}'."
    )
    return (slug, name, desc)


def cluster_sessions(sessions: list, *, min_session_len: int = 2) -> dict:
    """Group sessions by their normalized step-key sequence. Returns
    {sequence_fingerprint: {sample_steps, count, total_actions}}."""
    clusters = defaultdict(lambda: {"steps": [], "count": 0,
                                     "total_actions": 0})
    for s in sessions:
        actions = collapse_repeats(s.get("actions", []) or [])
        if len(actions) < min_session_len:
            continue
        seq = tuple(step_key(a) for a in actions)
        fp = hashlib.sha1(json.dumps(seq).encode()).hexdigest()[:12]
        # Keep the most-action-rich sample we've seen so action_path
        # carries timing + results from a real session, not a trimmed one.
        c = clusters[fp]
        c["count"] += 1
        c["total_actions"] += len(actions)
        if not c["steps"] or len(actions) >= len(c["steps"]):
            c["steps"] = actions
    return dict(clusters)


# ── Build action_path payload ───────────────────────────────────────────
def build_action_path(actions: list) -> list:
    """Distill a Playwright-generator-friendly view of the session.
    Drops PII-shaped fields (user_agent, ip, session_id) — those have
    already been redacted upstream by actionLogPolicy.php, but be
    defensive in case of policy drift."""
    out = []
    for a in actions:
        params = a.get("params_json")
        if isinstance(params, str):
            try:
                params = json.loads(params) if params else {}
            except json.JSONDecodeError:
                params = {}
        out.append({
            "page":        a.get("page"),
            "action":      a.get("action"),
            "result":      a.get("result"),
            "duration_ms": a.get("duration_ms"),
            "params":      params,
            "at":          a.get("created"),
        })
    return out


# ── Main ────────────────────────────────────────────────────────────────
def derive_for_app(app: str, since: str | None, dry_run: bool,
                   email: str | None = None) -> int:
    log.info("── %s ──", app)
    res = api("apiListTestSessionActions",
              source_app=app, since=since, email=email, limit=2000)
    sessions = res.get("sessions") or []
    total_actions = res.get("action_count") or 0
    if not sessions:
        log.info("  no test-user actions for source_app=%s", app)
        return 0

    log.info("  test user: %s shard=%s",
             res.get("test_user", {}).get("email"),
             res.get("test_user", {}).get("shard_id"))
    log.info("  %d session(s), %d total actions",
             len(sessions), total_actions)

    clusters = cluster_sessions(sessions)
    if not clusters:
        log.info("  no sessions met min length")
        return 0

    written = 0
    for fp, c in clusters.items():
        steps = c["steps"]
        slug, name, desc = name_for_sequence(steps)
        starting = (steps[0].get("page") or "").strip() or None
        ending = (steps[-1].get("action") or "").strip() or None
        action_path = build_action_path(steps)

        log.info("  → %-12s %2dx  %d-step  %s",
                 slug[:12], c["count"], len(steps), name)
        if dry_run:
            continue

        api("apiUpsertUseCase",
            source_app=app,
            slug=slug,
            name=name,
            description=desc,
            requires_login=1,
            starting_page=starting,
            ending_action=ending,
            action_path=json.dumps(action_path),
            test_category="feature",
            derived_from_log_count=c["total_actions"])
        written += 1

    log.info("  wrote %d use_case row(s) (dry-run=%s)", written, dry_run)
    return written


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--apps", required=True,
                    help="Comma-separated source_app slugs (e.g. nokemo,elevateher,pwt)")
    ap.add_argument("--since", default=None, metavar="YYYY-MM-DD[ HH:MM:SS]",
                    help="Earliest action log timestamp to consider.")
    ap.add_argument("--email", default=None,
                    help="Derive from this user's logs instead of the "
                         "canonical is_test_account user. Useful before "
                         "the test user has generated real history.")
    ap.add_argument("--dry-run", action="store_true",
                    help="Print clusters; do not UPSERT.")
    ap.add_argument("--verbose", "-v", action="store_true")
    args = ap.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    apps = [a.strip() for a in args.apps.split(",") if a.strip()]
    if not apps:
        log.error("no apps specified")
        return 2

    total = 0
    for app in apps:
        try:
            total += derive_for_app(app, args.since, args.dry_run, args.email)
        except Exception as e:
            log.error("[%s] %s", app, e)
    log.info("Done. wrote %d use_case row(s) across %d app(s) %s",
             total, len(apps), "(dry-run)" if args.dry_run else "")
    return 0


if __name__ == "__main__":
    sys.exit(main())

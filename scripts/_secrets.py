"""Load credentials from the gitignored ~/.openclaw/secrets.env.

Why this exists: until 2026-08-03 the scripts in this directory carried live
`wn_sk_...` service keys as hardcoded `os.environ.get("WN_API_KEY", "<literal>")`
defaults, which meant every key was committed in plaintext. The defaults were
there for a good reason though — several of these scripts are launched straight
from cron (`/usr/bin/python3 .../drain_zoom_cloud.py ...`) with no shell to
source secrets.env first, so simply deleting the default would have broken the
nightly pipelines.

This module keeps that convenience without the literal: it reads the same
gitignored file the shell scripts source, and fills in only the variables that
are not already set, so an explicit env var from the caller always wins.

Usage:

    import os, sys
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from _secrets import load_secrets, require

    load_secrets()
    WN_API_KEY = require("WN_API_KEY")
"""

import os

SECRETS_PATH = os.path.expanduser("~/.openclaw/secrets.env")

_loaded = False


def load_secrets(path: str = SECRETS_PATH) -> None:
    """Populate os.environ from secrets.env for any var not already set.

    Idempotent, and a no-op when the file is absent — callers that already
    have the variables in their environment (a shell wrapper that sourced
    secrets.env, or CI) keep working untouched.
    """
    global _loaded
    if _loaded:
        return
    _loaded = True

    try:
        with open(path) as fh:
            lines = fh.readlines()
    except OSError:
        return

    for raw in lines:
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        # Explicit environment always wins over the file.
        if key and key not in os.environ:
            os.environ[key] = value


def require(name: str, *fallbacks: str) -> str:
    """Return the first non-empty of $name / $fallbacks, or fail loudly.

    Failing loudly is deliberate: a silently-empty API key turns into a
    confusing 401 several calls later, which is exactly the failure mode the
    old hardcoded defaults were hiding.
    """
    load_secrets()
    for candidate in (name,) + fallbacks:
        value = os.environ.get(candidate)
        if value:
            return value
    names = " / ".join((name,) + fallbacks)
    raise SystemExit(
        f"FATAL: {names} is not set and was not found in {SECRETS_PATH}.\n"
        f"Add it there (chmod 600) or export it before running this script."
    )

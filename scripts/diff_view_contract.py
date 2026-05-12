#!/usr/bin/env python3
"""
diff_view_contract.py — diff a desktop view contract against the mobile
shell + mobile JS, emit per-element parity rows.

For each desktop element, decide:
  wired    — same signature exists in mobile/index.html (or via the JS
             selector usage scanner) with all the desktop's id/data-*
             keys represented
  partial  — same id present but expected attrs differ / a referenced
             selector exists in JS but no static element backs it
  missing  — no match

Rows go to mobile_parity with category='element', feature_key
`{view}/{normalized_signature}`. The signature normalization collapses
PHP/JS placeholders so `#card-{$index}` (desktop) matches `#card-{n}`
(mobile JS doing `getElementById('card-' + i)`).

Usage:

  python3 diff_view_contract.py \\
    --source-app pwt \\
    --desktop-views /home/jeevan/Desktop/pwt/views \\
    --mobile-root  /home/jeevan/Desktop/pwt/mobile \\
    --wn-api-url   https://playwithtarot.com/admin/api/index.php \\
    --wn-api-key   wn_sk_...

  Add --dry-run to print the diff without posting to admin.
"""
import argparse, json, os, re, sys, glob
import urllib.request, urllib.parse

# Import the extractor from the same scripts dir
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from extract_view_contract import extract_contract  # type: ignore


# ─── Signature normalization ──────────────────────────────────────────────

# Replace PHP placeholders {$expr} and JS template parts (anything that
# looks dynamic) with a single '{n}' token so desktop patterns can match
# mobile patterns regardless of variable names.
PLACEHOLDER_RX = re.compile(r"\{[^}]*\}")
JS_TEMPLATE_RX = re.compile(r"\$\{[^}]*\}")


def normalize_signature(sig: str) -> str:
    sig = PLACEHOLDER_RX.sub("{n}", sig)
    sig = JS_TEMPLATE_RX.sub("{n}", sig)
    return sig


# ─── Scan mobile JS for getElementById / querySelector usage ──────────────
# A selector used by JS is effectively a contract: mobile EXPECTS an
# element with that id/class/data-attr. If desktop produces it but mobile's
# static index.html doesn't, AND mobile JS never builds it dynamically,
# we have a hole.

GET_BY_ID_RX  = re.compile(r"""getElementById\s*\(\s*['"]([^'"]+)['"]""")
QUERY_RX      = re.compile(r"""querySelector(?:All)?\s*\(\s*['"]([^'"]+)['"]""")
# `getElementById('card-' + i)` style — extract the string-literal prefix
# so we can match templated ids
GET_BY_ID_CONCAT_RX = re.compile(r"""getElementById\s*\(\s*['"]([^'"]+)['"]\s*\+""")


def scan_js_selectors(mobile_root: str) -> dict:
    out = {"ids": set(), "id_prefixes": set(), "selectors": set()}
    for path in glob.glob(os.path.join(mobile_root, "js", "*.js")):
        try:
            txt = open(path, "r", encoding="utf-8", errors="replace").read()
        except OSError:
            continue
        for m in GET_BY_ID_RX.finditer(txt):
            out["ids"].add(m.group(1))
        for m in GET_BY_ID_CONCAT_RX.finditer(txt):
            out["id_prefixes"].add(m.group(1))
        for m in QUERY_RX.finditer(txt):
            out["selectors"].add(m.group(1))
    return out


def signature_in_mobile(sig: str, mobile_index_sigs: set, mobile_js: dict) -> str:
    """
    Decide a status string for a desktop signature.
    Returns one of: 'wired', 'partial', 'missing'.
    """
    norm = normalize_signature(sig)

    # Plain id signature like '#cardImg-{n}' or '#oracleChat'
    if norm.startswith("#"):
        bare_id = norm[1:]
        # Exact match in mobile static HTML
        if any(normalize_signature(s) == norm for s in mobile_index_sigs):
            return "wired"
        # Match against JS-built ids
        if "{n}" in bare_id:
            # `cardImg-{n}` — strip the {n} suffix to get a prefix
            prefix = bare_id.split("{n}")[0]
            if prefix in mobile_js["id_prefixes"]:
                return "wired"   # mobile builds this dynamically
            # Did mobile JS reference the exact literal at some point?
            for j in mobile_js["ids"]:
                if j.startswith(prefix.rstrip("-")):
                    return "partial"
        else:
            if bare_id in mobile_js["ids"]:
                return "partial"   # JS references it but no static element
        return "missing"

    # data-* signature like '[data-card-id={n}]'
    if norm.startswith("["):
        attr_key = norm[1:].split("=")[0]
        # Mobile static element exists with that attr?
        for s in mobile_index_sigs:
            if attr_key in s:
                return "wired"
        # Mobile JS uses a `[data-X]` querySelector?
        for js_sel in mobile_js["selectors"]:
            if attr_key in js_sel:
                return "partial"
        return "missing"

    # tag.classname or tag[name=...] — looser match
    if any(normalize_signature(s) == norm for s in mobile_index_sigs):
        return "wired"
    return "missing"


def diff_view(desktop_contract: dict, mobile_contract: dict, mobile_js: dict, source_app: str) -> list:
    """Emit a mobile_parity row for each desktop element."""
    view = desktop_contract["view"]
    mobile_index_sigs = {e["signature"] for e in mobile_contract["elements"]}

    rows = []
    for e in desktop_contract["elements"]:
        sig = e["signature"]
        status = signature_in_mobile(sig, mobile_index_sigs, mobile_js)
        rows.append({
            "source_app":    source_app,
            "category":      "element",
            "feature_key":   f"{view}/{normalize_signature(sig)}",
            "feature_name":  f"{view}: {sig}",
            "desktop_source": f"{desktop_contract['source']}:{e['line']}",
            "mobile_source":  "" if status == "missing" else "mobile/index.html",
            "mobile_status":  status if status != "partial" else "partial",
            "priority":       _priority_for(e),
            "notes":          _notes_for(e),
        })

    # API call rows — one per desktop apiPost / apiClient call
    desktop_calls = {s["target"] for s in desktop_contract["scripts"] if s["kind"].startswith(("api_post","api_client"))}
    mobile_calls = set()
    for path in glob.glob(os.path.join(os.path.dirname(mobile_contract["source"]), "js", "*.js")):
        try:
            mj = open(path).read()
        except OSError:
            continue
        for m in re.finditer(r"""apiPost\s*\(\s*['"]([^'"]+)['"]""", mj):
            mobile_calls.add(m.group(1))
        for m in re.finditer(r"""apiClient\s*\.\s*(?:post|get|put|delete)\s*\(\s*['"]([^'"]+)['"]""", mj):
            mobile_calls.add(m.group(1))
    for target in desktop_calls:
        rows.append({
            "source_app":    source_app,
            "category":      "action",
            "feature_key":   f"{view}/api:{target}",
            "feature_name":  f"{view}: api call '{target}'",
            "desktop_source": desktop_contract["source"],
            "mobile_source":  "mobile/js" if target in mobile_calls else "",
            "mobile_status":  "wired" if target in mobile_calls else "missing",
            "priority":       "high",
            "notes":          "",
        })

    return rows


def _priority_for(e: dict) -> str:
    # Stuff JS clearly depends on is high; merely-decorative elements low.
    a = e["attrs"]
    if "id" in a or any(k.startswith("data-") for k in a) or any(k.startswith("on") for k in a):
        return "high"
    if "name" in a:
        return "high"
    return "medium"


def _notes_for(e: dict) -> str:
    bits = []
    if e.get("in_loop"):
        bits.append(f"loop: {e['in_loop'].get('expr', '?')}")
    if e.get("in_cond"):
        bits.append(f"cond: {e['in_cond'].get('expr', '?')}")
    return "; ".join(bits)


def post_rows(api_url: str, api_key: str, rows: list, chunk_size: int = 25) -> int:
    """Bulk upsert in chunks. Retries 503/504/timeout up to 3 times with
    exponential backoff — shared hosts intermittently throttle when we
    push hundreds of UPSERTs back-to-back."""
    import time
    total = 0
    for i in range(0, len(rows), chunk_size):
        chunk = rows[i:i+chunk_size]
        body = urllib.parse.urlencode({
            "action": "apiBulkUpsertMobileParity",
            "rows":   json.dumps(chunk),
        }).encode()
        delay = 1.0
        for attempt in range(4):
            try:
                req = urllib.request.Request(api_url, data=body, method="POST")
                req.add_header("Authorization", "Bearer " + api_key)
                with urllib.request.urlopen(req, timeout=90) as r:
                    resp = json.loads(r.read().decode())
                if resp.get("error"):
                    print(f"  chunk[{i}] ERROR: {resp['error']}", file=sys.stderr)
                    return -1
                total += resp.get("results", {}).get("inserted", 0)
                print(f"  chunk[{i:4d}:{i+chunk_size:4d}]  +{resp.get('results',{}).get('inserted',0)} rows")
                break
            except urllib.error.HTTPError as e:
                if e.code in (503, 504, 502) and attempt < 3:
                    print(f"  chunk[{i}] HTTP {e.code}, retrying in {delay}s…", file=sys.stderr)
                    time.sleep(delay)
                    delay *= 2
                    continue
                raise
            except (urllib.error.URLError, TimeoutError) as e:
                if attempt < 3:
                    print(f"  chunk[{i}] {e}, retrying in {delay}s…", file=sys.stderr)
                    time.sleep(delay)
                    delay *= 2
                    continue
                raise
        time.sleep(0.3)   # gentle pacing between successful chunks
    return total


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--source-app", required=True)
    ap.add_argument("--desktop-views", required=True, help="dir with views/*.php")
    ap.add_argument("--mobile-root", required=True, help="dir with index.html and js/")
    ap.add_argument("--wn-api-url", required=True)
    ap.add_argument("--wn-api-key", required=True)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--only", help="only diff this view slug (debugging)")
    args = ap.parse_args()

    mobile_index = os.path.join(args.mobile_root, "index.html")
    if not os.path.isfile(mobile_index):
        print(f"ERR: mobile index.html not found at {mobile_index}", file=sys.stderr)
        sys.exit(1)

    mobile_contract = extract_contract(mobile_index)
    mobile_js = scan_js_selectors(args.mobile_root)

    print(f"mobile/index.html: {len(mobile_contract['elements'])} elements")
    print(f"mobile/js scan:    {len(mobile_js['ids'])} getElementById literals, "
          f"{len(mobile_js['id_prefixes'])} dynamic id prefixes, "
          f"{len(mobile_js['selectors'])} querySelectors")
    print()

    all_rows = []
    by_view_summary = []
    for path in sorted(glob.glob(os.path.join(args.desktop_views, "*.php"))):
        view = os.path.basename(path)[:-4]
        if view in ("template", "404"): continue
        if args.only and view != args.only: continue

        desktop_contract = extract_contract(path)
        rows = diff_view(desktop_contract, mobile_contract, mobile_js, args.source_app)
        all_rows.extend(rows)

        n_total   = len(rows)
        n_missing = sum(1 for r in rows if r["mobile_status"] == "missing")
        n_partial = sum(1 for r in rows if r["mobile_status"] == "partial")
        n_wired   = sum(1 for r in rows if r["mobile_status"] == "wired")
        by_view_summary.append((view, n_total, n_missing, n_partial, n_wired))
        print(f"  {view:24s}  total={n_total:4d}  missing={n_missing:4d}  partial={n_partial:3d}  wired={n_wired:4d}")

    print()
    print(f"total rows: {len(all_rows)}")

    if args.dry_run:
        print("\n--- dry-run, first 5 missing ---")
        for r in [x for x in all_rows if x["mobile_status"] == "missing"][:5]:
            print(json.dumps({k: r[k] for k in ("feature_key","desktop_source","priority","notes")}))
        return

    posted = post_rows(args.wn_api_url, args.wn_api_key, all_rows)
    print(f"posted {posted} rows to {args.wn_api_url}")


if __name__ == "__main__":
    main()

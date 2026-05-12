#!/usr/bin/env python3
"""
extract_view_contract.py — produce a structured DOM contract from a PHP
view file (or a static HTML file like mobile/index.html).

The contract is the input to the desktop↔mobile diff.

Each emitted "element" row captures:
  - tag
  - attribute dict (with PHP <?= $expr ?> and <?php echo … ?> rendered
    as {$expr} placeholders so dynamic values stay legible)
  - in_loop — the surrounding PHP foreach/while/for context, or null
  - in_cond — the surrounding PHP if/elseif/else context (informational)
  - line  — 1-based line of the opening tag in the original source

Plus a `scripts` section listing every apiPost / apiClient.{post,get}
call, every getElementById / querySelector(All) call, and every
form-submit / onclick handler reference found in inline <script>
content.

Mobile/index.html and any plain HTML file go through the same path —
the PHP regex stage is a no-op when no <?php / <?= tags exist.

Usage:
  python3 extract_view_contract.py views/reading.php > reading.contract.json
  python3 extract_view_contract.py mobile/index.html mobile/index.contract.json

  python3 extract_view_contract.py --dir views/ --out /tmp/contracts/
    Batch — one .contract.json per .php file.
"""

import argparse, json, os, re, sys
from html.parser import HTMLParser


# ─── PHP region classification ────────────────────────────────────────────

# Matches the entire region from <?php or <?= up to ?>.
PHP_RX = re.compile(r"<\?(?:php\b|=)(.*?)\?>", re.DOTALL)


def classify_php_block(content: str, is_short_echo: bool) -> tuple[str, str]:
    """
    Inspect the inside of a <?…?> block and decide what it does.

    `is_short_echo` is true when the opening tag was `<?=` (PHP_RX
    consumes the `=` so we can't recover it from the content alone).

    Returns (kind, payload) where kind is one of:
      'echo'        — emits a value (we keep it as a {$expr} placeholder)
      'ctrl_start'  — opens a control structure (foreach / while / for / if)
      'ctrl_end'    — closes one
      'ctrl_else'   — else / elseif inside an open if
      'other'       — pure PHP statements, no output (strip from contract)
    """
    s = content.strip()
    if is_short_echo:
        return "echo", s.rstrip(";").strip()
    if s.startswith("echo "):
        return "echo", s[5:].rstrip(";").strip()

    head = re.match(r"(foreach|for|while|if|elseif|else|switch|endforeach|endfor|endwhile|endif|endswitch)\b", s)
    if head:
        kw = head.group(1)
        if kw in ("foreach", "for", "while", "if", "switch"):
            return "ctrl_start", s
        if kw in ("elseif", "else"):
            return "ctrl_else", s
        if kw in ("endforeach", "endfor", "endwhile", "endif", "endswitch"):
            return "ctrl_end", s

    # Brace-style opens (`<?php foreach($x as $y) { ?>`) are caught by
    # the keyword check above. Brace-style closes show up as a `}` block.
    if s in ("}", "} else {", "} else"):
        return "ctrl_end", s

    return "other", s


# ─── Preprocess PHP source into placeholder-HTML + loop markers ───────────

LOOP_START_MARKER = "<!--PHP-LOOP-START {idx}-->"
LOOP_END_MARKER   = "<!--PHP-LOOP-END {idx}-->"
COND_START_MARKER = "<!--PHP-COND-START {idx}-->"
COND_END_MARKER   = "<!--PHP-COND-END {idx}-->"


def preprocess(source: str):
    """
    Walk the source, substitute PHP blocks for placeholders or markers.

    Returns:
      processed_text — HTML-shaped string with placeholders / comment markers
      php_table      — list of {kind, payload, line, marker_idx} entries
    """
    php_table = []
    out_parts = []
    last_end = 0
    loop_idx = 0
    cond_idx = 0

    for m in PHP_RX.finditer(source):
        # carry across the static HTML before this PHP block
        out_parts.append(source[last_end:m.start()])
        line = source.count("\n", 0, m.start()) + 1
        is_short_echo = source[m.start():m.start()+3] == "<?="
        kind, payload = classify_php_block(m.group(1), is_short_echo)

        if kind == "echo":
            # Render <?= $expr ?> as a {$expr} placeholder. payload
            # already starts with the leading $ for variables / 0 for
            # function calls, so we don't add one — just wrap in braces.
            placeholder = "{" + payload + "}"
            out_parts.append(placeholder)
            php_table.append({"kind": "echo", "payload": payload, "line": line})
        elif kind == "ctrl_start":
            # foreach / for / while / if / switch — we differentiate
            # loops from conditionals because looped elements are
            # template patterns, while conditional elements are still
            # one-of-them.
            is_loop = bool(re.match(r"(foreach|for|while)\b", payload))
            if is_loop:
                loop_idx += 1
                out_parts.append(f"<!--PHP-LOOP-START {loop_idx} {payload[:200]}-->")
                php_table.append({"kind": "loop_start", "idx": loop_idx, "payload": payload, "line": line})
            else:
                cond_idx += 1
                out_parts.append(f"<!--PHP-COND-START {cond_idx} {payload[:200]}-->")
                php_table.append({"kind": "cond_start", "idx": cond_idx, "payload": payload, "line": line})
        elif kind == "ctrl_end":
            # We don't know if this closes the most recent loop or cond
            # without a stack. Emit BOTH end markers — the parser will
            # match the most recently opened one of whichever kind.
            # In practice this is unambiguous because the marker stack
            # is maintained while parsing.
            out_parts.append("<!--PHP-CTRL-END-->")
            php_table.append({"kind": "ctrl_end", "payload": payload, "line": line})
        elif kind == "ctrl_else":
            # else/elseif resets the conditional branch — informational.
            out_parts.append("<!--PHP-CTRL-ELSE-->")
            php_table.append({"kind": "ctrl_else", "payload": payload, "line": line})
        else:
            # pure PHP, no output — leave nothing behind.
            php_table.append({"kind": "other", "payload": payload, "line": line})

        last_end = m.end()

    out_parts.append(source[last_end:])
    return "".join(out_parts), php_table


# ─── HTML walker that collects contract rows ──────────────────────────────

# Tags we don't care about for the contract even if they have attrs.
SKIP_TAGS = {"!doctype", "html", "head", "meta", "link", "title", "style"}


class ContractParser(HTMLParser):
    def __init__(self, source_lines):
        super().__init__(convert_charrefs=True)
        self.source_lines = source_lines
        self.elements = []
        self.loop_stack = []     # list of {idx, payload}
        self.cond_stack = []     # list of {idx, payload}
        self.ctrl_stack = []     # mixed stack of ('loop'|'cond', dict) for endings
        self.in_script = False
        self.script_buffer = []
        self.script_start_line = 0
        self.scripts = []

    # html.parser line-numbers are 1-based for `getpos()`
    def _cur_line(self) -> int:
        return self.getpos()[0]

    def handle_comment(self, data: str):
        d = data.strip()
        m = re.match(r"PHP-LOOP-START (\d+)\s*(.*)", d)
        if m:
            idx = int(m.group(1))
            entry = {"idx": idx, "kind": "loop", "expr": m.group(2)}
            self.loop_stack.append(entry)
            self.ctrl_stack.append(("loop", entry))
            return
        if re.match(r"PHP-LOOP-END \d+", d):
            return
        m = re.match(r"PHP-COND-START (\d+)\s*(.*)", d)
        if m:
            idx = int(m.group(1))
            entry = {"idx": idx, "kind": "cond", "expr": m.group(2)}
            self.cond_stack.append(entry)
            self.ctrl_stack.append(("cond", entry))
            return
        if d == "PHP-CTRL-END":
            if self.ctrl_stack:
                kind, entry = self.ctrl_stack.pop()
                if kind == "loop" and self.loop_stack:
                    self.loop_stack.pop()
                elif kind == "cond" and self.cond_stack:
                    self.cond_stack.pop()
            return
        if d == "PHP-CTRL-ELSE":
            return  # informational
        # ordinary HTML comment, ignore

    def handle_starttag(self, tag, attrs):
        if tag == "script":
            self.in_script = True
            self.script_buffer = []
            self.script_start_line = self._cur_line()
            return
        if tag in SKIP_TAGS:
            return
        self._record_element(tag, attrs)

    def handle_startendtag(self, tag, attrs):
        if tag in SKIP_TAGS:
            return
        self._record_element(tag, attrs)

    def handle_endtag(self, tag):
        if tag == "script":
            self.in_script = False
            content = "".join(self.script_buffer)
            self._scan_script(content, self.script_start_line)

    def handle_data(self, data):
        if self.in_script:
            self.script_buffer.append(data)

    def _record_element(self, tag, attrs):
        attr_dict = {}
        for k, v in attrs:
            if v is None:
                v = ""
            # html.parser leaves placeholders intact since we substituted
            # them as literal text earlier. {$expr} stays as {$expr}.
            attr_dict[k] = v

        # Contract-worthy if it has any of these signals.
        worthy = False
        if "id" in attr_dict: worthy = True
        if any(k.startswith("data-") for k in attr_dict): worthy = True
        if any(k.startswith("aria-") for k in attr_dict): worthy = True
        if "name" in attr_dict: worthy = True
        if "role" in attr_dict: worthy = True
        if any(k.startswith("on") for k in attr_dict): worthy = True
        if attr_dict.get("href", "").startswith("#"): worthy = True
        if tag in ("form", "input", "button", "select", "textarea", "a", "img", "iframe", "video", "audio", "canvas"):
            worthy = True
        if not worthy:
            return

        # Build a stable signature: prefer id, else first data-*, else
        # tag+name, else tag+class[0]. The signature is what diff keys on.
        signature = self._signature(tag, attr_dict)

        self.elements.append({
            "tag": tag,
            "attrs": attr_dict,
            "signature": signature,
            "in_loop": (self.loop_stack[-1] if self.loop_stack else None),
            "in_cond": (self.cond_stack[-1] if self.cond_stack else None),
            "line": self._cur_line(),
        })

    @staticmethod
    def _signature(tag, attrs):
        if "id" in attrs:
            return f"#{attrs['id']}"
        for k in sorted(attrs.keys()):
            if k.startswith("data-"):
                return f"[{k}={attrs[k]}]" if attrs[k] else f"[{k}]"
        if "name" in attrs:
            return f"{tag}[name={attrs['name']}]"
        if "role" in attrs:
            return f"{tag}[role={attrs['role']}]"
        if "href" in attrs and attrs["href"].startswith("#"):
            return f"a[href={attrs['href']}]"
        cls = attrs.get("class", "").split()
        if cls:
            return f"{tag}.{cls[0]}"
        return tag

    # ── Inline <script> mining ──────────────────────────────────────────
    API_POST_RX     = re.compile(r"""apiPost\s*\(\s*['"]([^'"]+)['"]""")
    API_CLIENT_RX   = re.compile(r"""apiClient\s*\.\s*(post|get|put|delete)\s*\(\s*['"]([^'"]+)['"]""")
    GET_BY_ID_RX    = re.compile(r"""getElementById\s*\(\s*['"]([^'"]+)['"]""")
    QUERY_RX        = re.compile(r"""querySelector(?:All)?\s*\(\s*['"]([^'"]+)['"]""")
    ADD_EVT_RX      = re.compile(r"""addEventListener\s*\(\s*['"]([^'"]+)['"]""")
    FORM_HIDDEN_RX  = re.compile(r"""<input[^>]*\bname=['"]action['"][^>]*\bvalue=['"]([^'"]+)['"]""")

    def _scan_script(self, content: str, start_line: int):
        def loc(match):
            # 1-based line within the script, mapped to source line
            return start_line + content[:match.start()].count("\n")
        for m in self.API_POST_RX.finditer(content):
            self.scripts.append({"kind": "api_post", "target": m.group(1), "line": loc(m)})
        for m in self.API_CLIENT_RX.finditer(content):
            self.scripts.append({"kind": f"api_client_{m.group(1)}", "target": m.group(2), "line": loc(m)})
        for m in self.GET_BY_ID_RX.finditer(content):
            self.scripts.append({"kind": "getElementById", "target": m.group(1), "line": loc(m)})
        for m in self.QUERY_RX.finditer(content):
            self.scripts.append({"kind": "querySelector", "target": m.group(1), "line": loc(m)})
        for m in self.ADD_EVT_RX.finditer(content):
            self.scripts.append({"kind": "addEventListener", "target": m.group(1), "line": loc(m)})


def extract_form_actions(source: str):
    """Find hidden <input name=action value=X> in the raw source — these
    are form-POST entry points and are easy to miss in the <script> scan."""
    return [{"action": m.group(1)} for m in ContractParser.FORM_HIDDEN_RX.finditer(source)]


# ─── Top-level driver ─────────────────────────────────────────────────────

def extract_contract(path: str) -> dict:
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        source = f.read()

    processed, php_table = preprocess(source)
    parser = ContractParser(source.splitlines())
    parser.feed(processed)

    form_actions = extract_form_actions(source)

    base = os.path.basename(path)
    view = re.sub(r"\.(php|html|tpl)$", "", base)

    return {
        "view":     view,
        "source":   path,
        "elements": parser.elements,
        "scripts":  parser.scripts,
        "form_actions": form_actions,
        "php_table":   php_table,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("inputs", nargs="*", help="files or - for stdin path list")
    ap.add_argument("--dir", help="batch every .php in this directory")
    ap.add_argument("--out", help="output directory for batch mode (one .contract.json per input)")
    ap.add_argument("--summary", action="store_true", help="emit per-view counts instead of full JSON")
    args = ap.parse_args()

    targets = list(args.inputs)
    if args.dir:
        for name in sorted(os.listdir(args.dir)):
            if name.endswith(".php") and name not in ("template.php",):
                targets.append(os.path.join(args.dir, name))

    if not targets:
        ap.error("no input files; pass paths or --dir")

    for path in targets:
        contract = extract_contract(path)
        if args.summary:
            view = contract["view"]
            n_el = len(contract["elements"])
            n_id = sum(1 for e in contract["elements"] if "id" in e["attrs"])
            n_data = sum(1 for e in contract["elements"] if any(k.startswith("data-") for k in e["attrs"]))
            n_api = sum(1 for s in contract["scripts"] if s["kind"].startswith(("api_post","api_client")))
            print(f"  {view:24s} elements={n_el:4d} ids={n_id:3d} data-*={n_data:3d} api_calls={n_api:3d}")
            continue
        if args.out:
            os.makedirs(args.out, exist_ok=True)
            base = os.path.basename(path)
            outname = re.sub(r"\.(php|html|tpl)$", ".contract.json", base)
            with open(os.path.join(args.out, outname), "w", encoding="utf-8") as f:
                json.dump(contract, f, indent=2)
        else:
            json.dump(contract, sys.stdout, indent=2)
            sys.stdout.write("\n")


if __name__ == "__main__":
    main()

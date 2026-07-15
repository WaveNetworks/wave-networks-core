<?php
/**
 * split_view.php — the splitter (spec 05).
 *
 * Splits a rendered view into MARKUP (content, may travel over the wire) and
 * JS (code, must ship inside the app bundle).
 *
 *   markup  → returned by the mobile fragment endpoint, cached on device
 *   js      → hoisted at build time into m/js/screens/<view>.js
 *   deps    → <script src> the view pulls in; the bundle vendors these
 *
 * This is the ONLY splitter. It has exactly two callers — build time
 * (scripts/build_mobile.php) and request time (app/index.php?mobile=1) — so what
 * is stripped from a fragment is precisely what was shipped in the bundle. There
 * is no third place for the two halves to disagree.
 *
 * The mobile shell runs under CSP script-src 'self': a <script> or an inline
 * handler arriving in a fragment cannot execute. Stripping is therefore not the
 * safety measure (the CSP is) — it is how the fragment stays honest, and how we
 * detect that a view's behavior changed and a store build is due.
 */

/**
 * Split rendered view HTML into markup + hoisted JS + vendor deps.
 *
 * @param string $html    Rendered view output (or raw view source when $lenient).
 * @param bool   $lenient Build-time mode: tolerate PHP echo tags inside handler
 *                        args (the source has not been through PHP yet).
 * @return array{markup:string, js:string, deps:array<int,string>, unmapped:array<int,string>}
 */
function wn_split_view($html, $lenient = false)
{
    $unmapped = [];
    $deps     = [];
    $js       = '';

    // 0. Drop HTML comments. Two reasons, and the first one is not cosmetic: a
    //    commented-out (or merely mentioned) script tag would otherwise be matched
    //    by the hoist below and dragged into the bundle. Second: developer comments
    //    have no business travelling to a user's phone on every fragment fetch.
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    // 1. Hoist <script> blocks.
    $markup = preg_replace_callback(
        '#<script\b([^>]*)>(.*?)</script>#is',
        function ($m) use (&$js, &$deps) {
            $attrs = $m[1];

            // A <script src> is a dependency, not code we can lift. The bundle
            // vendors it (m/ must be asset-complete and CDN-free); we only need
            // to know it is required.
            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $s)) {
                $deps[] = preg_replace('/\?.*$/', '', $s[1]); // drop cache-buster
                return '';
            }

            // A data block (type="application/json", text/template) is not code.
            if (preg_match('/\btype\s*=\s*["\']?(application\/json|text\/template)/i', $attrs)) {
                return $m[0];
            }

            $js .= trim($m[2]) . "\n\n";
            return '';
        },
        $html
    );

    // 2. Rewrite inline handlers into declarative attributes the bundled dispatcher
    //    understands. No code crosses the wire, and nothing is eval'd on the far
    //    side — data-act names a function that already exists in the shipped
    //    bundle, or it does not run at all.
    $markup = preg_replace_callback(
        '/\son(click|change|submit|input)\s*=\s*"([^"]*)"/i',
        function ($m) use (&$unmapped, $lenient) {
            $rewritten = wn_rewrite_handler($m[2], $lenient);
            if ($rewritten === null) {
                $unmapped[] = 'on' . strtolower($m[1]) . '="' . $m[2] . '"';
                return $m[0]; // leave it visible; the build fails on it anyway
            }
            return ' data-on="' . strtolower($m[1]) . '"' . $rewritten;
        },
        $markup
    );

    return [
        'markup'   => $markup,
        'js'       => $js,
        'deps'     => array_values(array_unique($deps)),
        'unmapped' => $unmapped,
    ];
}

/**
 * Rewrite one inline handler body into data-* attributes.
 *
 * Shapes the views actually use, all mappable:
 *   vivAct('claim', 42)                              → data-act data-args
 *   vivAct('rsvp', {dinner_id: 42, response: 'yes'}) → object/array literals are data
 *   vivClaim(42, this)                               → `this` → the clicked element
 *   event.preventDefault(); vivSwitch(3)             → data-prevent
 *   return confirm('Sure?') && vivCancel(3)          → data-confirm
 *   if (confirm('Sure?')) vivPost('x', {...})        → data-confirm
 *   return confirm('Remove this member?')            → data-confirm alone (guards a form submit)
 *
 * Anything else returns null and is reported as unmapped: a handler we cannot map
 * is a control that would look fine and do nothing, so it fails the build rather
 * than shipping dead.
 *
 * @return string|null Attribute string (leading space), or null if unmappable.
 */
function wn_rewrite_handler($body, $lenient = false)
{
    $body    = trim(rtrim(trim($body), ';'));
    $confirm = null;
    $prevent = false;

    // Leading event.preventDefault();
    if (preg_match('/^event\.preventDefault\(\)\s*;\s*(.*)$/is', $body, $m)) {
        $prevent = true;
        $body    = trim(rtrim(trim($m[1]), ';'));
    }

    // return confirm('…') && fn(args)   /   if (confirm('…')) fn(args)
    if (preg_match('/^return\s+confirm\(\s*([\'"])(.*?)\1\s*\)\s*&&\s*(.+)$/is', $body, $m)) {
        $confirm = $m[2];
        $body    = trim(rtrim(trim($m[3]), ';'));
    } elseif (preg_match('/^if\s*\(\s*confirm\(\s*([\'"])(.*?)\1\s*\)\s*\)\s*(.+)$/is', $body, $m)) {
        $confirm = $m[2];
        $body    = trim(rtrim(trim($m[3]), ';'));
    } elseif (preg_match('/^return\s+confirm\(\s*([\'"])(.*?)\1\s*\)$/is', $body, $m)) {
        // Bare confirm guarding a native form submit — no function to call.
        return ' data-confirm="' . htmlspecialchars($m[2], ENT_QUOTES) . '"';
    }

    // A single call to a named function: fn(arg, arg, …)
    if (!preg_match('/^([A-Za-z_$][\w$]*)\s*\((.*)\)$/s', $body, $m)) {
        return null;
    }
    $args = wn_parse_args($m[2], $lenient);
    if ($args === null) {
        return null; // an expression, not data — cannot travel as an attribute
    }

    $out = ' data-act="' . htmlspecialchars($m[1], ENT_QUOTES) . '"'
         . " data-args='" . htmlspecialchars(json_encode($args), ENT_QUOTES) . "'";
    if ($confirm !== null) $out .= ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"';
    if ($prevent)          $out .= ' data-prevent="1"';

    return $out;
}

/**
 * Parse a JS argument list of LITERALS into PHP values.
 * Returns null if any argument is an expression — an expression is code, and code
 * does not travel.
 *
 * `this` is the one exception: it is not a literal but it is not code either, it
 * is a reference to the element that was clicked. It becomes a sentinel the
 * dispatcher substitutes at call time.
 */
function wn_parse_args($src, $lenient = false)
{
    $src = trim($src);
    if ($src === '') return [];

    $out = [];
    foreach (wn_split_top_level($src, ',') as $a) {
        $v = wn_parse_literal(trim($a), $lenient);
        if ($v === WN_NOT_LITERAL) return null;
        $out[] = $v;
    }
    return $out;
}

/** Sentinel: this token is not a literal. */
define('WN_NOT_LITERAL', "\0__wn_not_literal__\0");

/**
 * Parse one JS literal — string, number, bool, null, `this`, array, or object.
 * @return mixed|string The value, or WN_NOT_LITERAL.
 */
function wn_parse_literal($a, $lenient = false)
{
    $a = trim($a);

    // Build-time: the source still has PHP echo tags where values will be. Stand
    // in a number so the SHAPE can be validated without rendering the view.
    if ($lenient) {
        $a = trim(preg_replace('/<\?=.*?\?>/s', '0', $a));
        $a = trim(preg_replace('/<\?php.*?\?>/s', '0', $a));
    }

    if ($a === '')      return WN_NOT_LITERAL;
    if ($a === 'true')  return true;
    if ($a === 'false') return false;
    if ($a === 'null')  return null;

    // The clicked element. Substituted by the dispatcher at call time — this is
    // how vivRsvp(42,'yes',this) keeps working without shipping an expression.
    if ($a === 'this') return ['__wn' => 'el'];

    if (is_numeric($a)) return $a + 0;

    // String literal (must be the whole token, not 'a' + b)
    if (preg_match('/^([\'"])(.*)\1$/s', $a, $m) && strpos($m[2], $m[1]) === false) {
        return $m[2];
    }

    // Array literal
    if ($a[0] === '[' && substr($a, -1) === ']') {
        $inner = trim(substr($a, 1, -1));
        if ($inner === '') return [];
        $items = [];
        foreach (wn_split_top_level($inner, ',') as $item) {
            $v = wn_parse_literal($item, $lenient);
            if ($v === WN_NOT_LITERAL) return WN_NOT_LITERAL;
            $items[] = $v;
        }
        return $items;
    }

    // Object literal — {dinner_id: 42, response: 'yes'}. Keys are bare JS
    // identifiers or quoted strings; values are literals, recursively.
    if ($a[0] === '{' && substr($a, -1) === '}') {
        $inner = trim(substr($a, 1, -1));
        if ($inner === '') return new stdClass();
        $obj = [];
        foreach (wn_split_top_level($inner, ',') as $pair) {
            $kv = wn_split_top_level(trim($pair), ':');
            if (count($kv) !== 2) return WN_NOT_LITERAL;
            $k = trim($kv[0]);
            if (preg_match('/^([\'"])(.*)\1$/s', $k, $m)) $k = $m[2];
            if (!preg_match('/^[A-Za-z_$][\w$]*$/', $k)) return WN_NOT_LITERAL;
            $v = wn_parse_literal($kv[1], $lenient);
            if ($v === WN_NOT_LITERAL) return WN_NOT_LITERAL;
            $obj[$k] = $v;
        }
        return $obj;
    }

    return WN_NOT_LITERAL; // identifier, member expression, arithmetic, call…
}

/**
 * Split on a delimiter at nesting depth 0, respecting quotes.
 * @return array<int,string>
 */
function wn_split_top_level($src, $delim)
{
    $parts = [];
    $depth = 0;
    $quote = null;
    $buf   = '';

    for ($i = 0, $n = strlen($src); $i < $n; $i++) {
        $c = $src[$i];

        if ($quote !== null) {
            $buf .= $c;
            if ($c === $quote && ($i === 0 || $src[$i - 1] !== '\\')) $quote = null;
            continue;
        }
        if ($c === '"' || $c === "'") { $quote = $c; $buf .= $c; continue; }
        if ($c === '(' || $c === '[' || $c === '{') $depth++;
        if ($c === ')' || $c === ']' || $c === '}') $depth--;
        if ($c === $delim && $depth === 0) { $parts[] = $buf; $buf = ''; continue; }

        $buf .= $c;
    }
    if (trim($buf) !== '') $parts[] = $buf;

    return $parts;
}

/**
 * Stable hash of a view's SOURCE — the deviation gauge's unit of comparison.
 * Markup drift means the screen is running wet (fine). Screen-JS drift means the
 * behavior changed and a store build is required.
 */
function wn_view_hash($file)
{
    return is_readable($file) ? substr(sha1_file($file), 0, 12) : '';
}

/**
 * Hash of a screen's BEHAVIOR — the thing the device compares against what it shipped.
 *
 * MUST be computed from the view's SOURCE, never from a rendered response, and both the
 * build and the fragment endpoint must call THIS function. Two reasons, and the second
 * one is the subtle one:
 *
 *  1. Trivially, the two sides have to normalise identically or every screen mismatches
 *     and the app refuses every fragment ("This screen needs an app update").
 *
 *  2. A view's rendered output does not always contain its script. Views return early
 *     (dinner.php bails when you are not a member of the circle) and wrap scripts in
 *     conditionals, so the JS present in a render legitimately varies from user to user
 *     and state to state. Hashing that would make the gauge flap: the same binary would
 *     be told it is current on one screen and stale on the next. The source is the only
 *     thing that is the same for everyone — and the source is what the bundle was built
 *     from, which is exactly the question being asked.
 */
function wn_screen_js_hash($file)
{
    if (!is_readable($file)) { return ''; }

    $split = wn_split_view(file_get_contents($file), true);
    $js    = trim($split['js']);

    return $js === '' ? '' : substr(sha1($js), 0, 12);
}

/**
 * wn_mobile_fragment_emit — the request-side endpoint (child-app spec 05).
 *
 * A child app's app/index.php calls this when ?mobile=1 is present, AFTER it has rendered
 * the view into $html at GLOBAL scope (so the view keeps its access to $child_db, session,
 * app helpers). It returns the MARKUP half only (behavior ships in the binary) plus the
 * deviation-gauge hashes the device compares against what it was built with, then exits.
 *
 * This is deliberately the SAME view file + session/permission context as the desktop
 * ?page= render — a mobile client can never see anything the web client couldn't.
 *
 *   if (($_GET['mobile'] ?? '') === '1' && function_exists('wn_mobile_fragment_emit')) {
 *       ob_start();
 *       if (file_exists($current_page_file)) include($current_page_file);
 *       wn_mobile_fragment_emit(ob_get_clean(), $page, $current_page_file);
 *   }
 */
function wn_mobile_fragment_emit($html, $page, $view_file)
{
    if (!headers_sent()) { header('Content-Type: application/json'); }
    $split = wn_split_view($html);
    echo json_encode([
        'markup'    => $split['markup'],
        'page'      => $page,
        'js_hash'   => wn_screen_js_hash($view_file),
        'view_hash' => wn_view_hash($view_file),
        'toast'     => [
            'success' => $_SESSION['success'] ?? '', 'error'   => $_SESSION['error']   ?? '',
            'warning' => $_SESSION['warning'] ?? '', 'info'    => $_SESSION['info']    ?? '',
        ],
    ]);
    $_SESSION['success'] = $_SESSION['error'] = $_SESSION['warning'] = $_SESSION['info'] = null;
    exit;
}

<?php
/**
 * scripts/legal-versioning-probe.php — Privacy Policy / Terms of Service versions are a
 * history, not a document that gets overwritten.
 *
 * Holds include/common/legalFunctions.php to what the admin screen, the public pages and
 * the acceptance records all depend on:
 *   1. nothing in the code UPDATEs, DELETEs, REPLACEs or TRUNCATEs consent_version (a
 *      published version is immutable; drafts live in consent_version_draft);
 *   2. migration main/5.3 is declared and adds what the code reads;
 *   3. RUN against an in-memory SQLite database (the real functions, not a copy):
 *      publishing creates a NEW row with the next number while the old row stays
 *      byte-identical (and its SHA-256 still matches); a taken number, a past date and an
 *      empty text are refused; a scheduled version is not in force before its date; a
 *      draft never creates a version;
 *   4. acceptance: sign-up records the version ids in force with source + IP hash; a
 *      version published WITH re-acceptance makes it owed, one published without does
 *      not; accepting stale ids is refused; accepting the shown ids records them;
 *   5. the markdown renderer escapes everything (no <script>, no javascript: links);
 *   6. the actions are dispatched (api-envelope would otherwise 404 them).
 *
 * Usage: php scripts/legal-versioning-probe.php [--root=/path/to/admin]
 * Exit 0 clean, 1 on a problem. DB-free (needs pdo_sqlite, present on CI runners).
 */

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$root = rtrim((string) ($opts['root'] ?? dirname(__DIR__)), '/');

$fails = []; $oks = [];
function ok($m)  { global $oks;   $oks[] = $m;   echo "  \u{2713} $m\n"; }
function bad($m) { global $fails; $fails[] = $m; echo "  \u{2717} $m\n"; }

echo "legal-versioning-probe ($root)\n";

// ── 1. immutability in the code ──────────────────────────────────────────────
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$hits = [];
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || strpos($p, '/node_modules/') !== false) continue;
    if (realpath($p) === realpath(__FILE__)) continue;
    $src = (string) @file_get_contents($p);
    if (preg_match_all('/\b(UPDATE|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE(?:\s+TABLE)?)\s+`?consent_version`?(?![A-Za-z0-9_])/i', $src, $m)) {
        $hits[] = substr($p, strlen($root) + 1) . ' (' . implode(', ', array_unique($m[1])) . ')';
    }
    if (preg_match('/INSERT\s+INTO\s+`?consent_version`?\s*\([^;]*ON\s+DUPLICATE\s+KEY\s+UPDATE/is', $src)) {
        $hits[] = substr($p, strlen($root) + 1) . ' (upsert)';
    }
}
$hits ? bad('published versions are rewritten by: ' . implode('; ', $hits)) : ok('nothing in the code updates, deletes or upserts a consent_version row');

// ── 2. migration ─────────────────────────────────────────────────────────────
$mig = (string) @file_get_contents($root . '/db_migrations/main/5.3.sql');
$need = ['app_slug', 'requires_reacceptance', 'content_sha256', 'published_by', 'published_at', 'consent_version_draft', 'ip_hash', 'source'];
$miss = array_filter($need, function ($c) use ($mig) { return stripos($mig, $c) === false; });
$mig !== '' && !$miss ? ok('main/5.3 adds the version, draft and acceptance columns') : bad('main/5.3 missing or lacks: ' . implode(', ', $miss));
$boot = (string) @file_get_contents($root . '/include/bootstrap.php');
preg_match('/\$db_version\s*=\s*\$db_version\s*\?\?\s*([0-9.]+)/', $boot, $bm);
(isset($bm[1]) && (float) $bm[1] >= 5.3) ? ok('bootstrap targets main ' . $bm[1]) : bad('bootstrap $db_version is below 5.3 — the migration would never run');

// ── 3–5. run the real functions on SQLite ────────────────────────────────────
if (!extension_loaded('pdo_sqlite')) {
    bad('pdo_sqlite is not available — the behaviour checks cannot run (install php-sqlite3)');
} else {
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_USER_AGENT'] = 'legal-probe';
    $GLOBALS['db'] = $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE user (user_id INTEGER PRIMARY KEY, is_test_account INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE consent_version (version_id INTEGER PRIMARY KEY AUTOINCREMENT, consent_type TEXT, version_label TEXT,
        effective_date TEXT, document_url TEXT, summary TEXT, content TEXT, is_active INTEGER DEFAULT 1, created TEXT,
        app_slug TEXT NOT NULL DEFAULT '', title TEXT, requires_reacceptance INTEGER NOT NULL DEFAULT 1, content_sha256 TEXT,
        published_by INTEGER, published_by_name TEXT, published_at TEXT)");
    $db->exec("CREATE TABLE consent_version_draft (draft_id INTEGER PRIMARY KEY AUTOINCREMENT, consent_type TEXT, app_slug TEXT NOT NULL DEFAULT '',
        title TEXT, content TEXT, summary TEXT, requires_reacceptance INTEGER DEFAULT 0, effective_date TEXT, updated_by INTEGER,
        updated_by_name TEXT, updated TEXT, UNIQUE (consent_type, app_slug))");
    $db->exec("CREATE TABLE user_consent (consent_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, consent_type TEXT,
        consent_version_id INTEGER, action TEXT, ip_address TEXT, user_agent TEXT, ip_hash TEXT, source TEXT,
        created TEXT DEFAULT CURRENT_TIMESTAMP)");
    // The 2.5 seed rows every deployment has: deployment-wide 1.0, no text, re-acceptance on.
    $db->exec("INSERT INTO consent_version (consent_type, version_label, effective_date, summary, created) VALUES
        ('terms_of_service', '1.0', '2026-03-15', 'Initial Terms of Service', '2026-03-15 00:00:00'),
        ('privacy_policy', '1.0', '2026-03-15', 'Initial Privacy Policy', '2026-03-15 00:00:00')");
    $db->exec("INSERT INTO user (user_id) VALUES (7), (8)");

    if (!function_exists('sanitize')) { define('SQL', 1); function sanitize($v, $t = null) { return str_replace("'", "''", (string) $v); } }
    if (!function_exists('h')) { function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
    require $root . '/include/common/mysqlFunctions.php';
    require $root . '/include/common/gdprFunctions.php';
    require $root . '/include/common/loginHistoryFunctions.php';
    require $root . '/include/common/legalFunctions.php';
    wn_legal_set_app('probeapp');   // no legal.json on disk for it: nothing is seeded

    $row = function ($id) use ($db) { $s = $db->prepare("SELECT * FROM consent_version WHERE version_id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };
    $count = function () use ($db) { return (int) $db->query("SELECT COUNT(*) FROM consent_version")->fetchColumn(); };

    // An app with no version of its own is served the deployment's.
    $c0 = wn_legal_current('privacy_policy');
    ($c0 && $c0['app_slug'] === '' && $c0['version_label'] === '1.0') ? ok('an app with no version of its own gets the whole-site version') : bad('fallback to the whole-site version failed');

    // Sign-up: record what is in force.
    foreach (['terms_of_service', 'privacy_policy'] as $t) { $v = get_latest_consent_version($t); record_consent(7, $t, 'granted', $v ? (int) $v['version_id'] : null, 'register_web'); }
    $h = wn_legal_acceptance_history(7);
    (count($h) === 2 && $h[0]['version_id'] && $h[0]['source'] === 'register_web' && $h[0]['ip_hash'] === hash('sha256', 'wn-consent-ip|203.0.113.9'))
        ? ok('sign-up records the version ids in force, the door (register_web) and an IP hash')
        : bad('sign-up acceptance is missing version/source/ip hash: ' . json_encode($h));
    wn_legal_pending(7) === [] ? ok('a user who accepted at sign-up owes nothing') : bad('a fresh sign-up is asked to accept again');
    count(wn_legal_pending(8)) === 2 ? ok('a user with no acceptance at all owes both documents') : bad('a user who never accepted is not asked');

    // Drafts never publish.
    $n = $count();
    wn_legal_save_draft('privacy_policy', 'probeapp', ['content' => "# Draft\n\nNot yet.", 'summary' => 'x'], 1, 'admin@nokemo.com');
    wn_legal_save_draft('privacy_policy', 'probeapp', ['content' => "# Draft\n\nStill not.", 'summary' => 'y'], 1, 'admin@nokemo.com');
    ($count() === $n && wn_legal_draft('privacy_policy', 'probeapp')['content'] === "# Draft\n\nStill not.") ? ok('saving a draft (twice) creates no version') : bad('a draft created or lost a version');

    // Publish 1.0 for the app (no re-acceptance).
    $r1 = wn_legal_publish('privacy_policy', 'probeapp', ['content' => "# Privacy\n\nWe keep **your** contacts.", 'summary' => 'First version.', 'requires_reacceptance' => 0], 1, 'admin@nokemo.com');
    $v1 = !empty($r1['version_id']) ? $row($r1['version_id']) : null;
    ($v1 && $v1['version_label'] === '1.0' && $v1['app_slug'] === 'probeapp' && $v1['published_by_name'] === 'admin@nokemo.com' && $v1['published_at'])
        ? ok('publishing creates a new version 1.0 with who and when') : bad('first publish: ' . json_encode($r1));
    wn_legal_draft('privacy_policy', 'probeapp') === null ? ok('publishing clears the draft') : bad('the draft survived publishing');
    (wn_legal_current('privacy_policy')['version_id'] ?? 0) == ($r1['version_id'] ?? -1) ? ok("the app's own version replaces the whole-site one") : bad('the app version is not in force');
    wn_legal_pending(7) === [] ? ok('a version published without re-acceptance asks nobody') : bad('a minor version made users accept again');

    // Publish 2.0 with re-acceptance: a NEW row; 1.0 untouched.
    $before = $v1;
    $r2 = wn_legal_publish('privacy_policy', 'probeapp', ['content' => "# Privacy\n\nWe keep **your** contacts.\n\nAnd now photos.", 'summary' => 'Adds photos.', 'requires_reacceptance' => 1], 1, 'admin@nokemo.com');
    $v2 = !empty($r2['version_id']) ? $row($r2['version_id']) : null;
    ($v2 && (int) $v2['version_id'] !== (int) $v1['version_id'] && $v2['version_label'] === '2.0')
        ? ok('publishing again creates a NEW row numbered 2.0 (re-acceptance = major)') : bad('second publish: ' . json_encode($r2));
    $row($before['version_id']) === $before ? ok('the earlier version is byte-identical after the next publish') : bad('publishing changed an earlier version');
    (wn_legal_intact($v1) === true && wn_legal_intact($v2) === true) ? ok('each published text matches its SHA-256') : bad('content_sha256 does not match the stored text');
    count(wn_legal_versions('privacy_policy', 'probeapp')) === 2 ? ok('history lists both versions') : bad('history is wrong');
    $d = wn_legal_diff($v1['content'], $v2['content']);
    (count(array_filter($d, fn($x) => $x['op'] === '+')) === 2 && !array_filter($d, fn($x) => $x['op'] === '-')) ? ok('the diff between 1.0 and 2.0 shows exactly the added lines') : bad('diff: ' . json_encode($d));

    // Refusals.
    $e1 = wn_legal_publish('privacy_policy', 'probeapp', ['content' => 'x', 'summary' => 's', 'version_label' => '2.0']);
    $e2 = wn_legal_publish('privacy_policy', 'probeapp', ['content' => 'x', 'summary' => 's', 'effective_date' => '2001-01-01']);
    $e3 = wn_legal_publish('privacy_policy', 'probeapp', ['content' => "  \n", 'summary' => 's']);
    (!empty($e1['errors']['label']) && !empty($e2['errors']['effective']) && !empty($e3['errors']['content']) && count(wn_legal_versions('privacy_policy', 'probeapp')) === 2)
        ? ok('a taken number, a past date and an empty text are refused and publish nothing') : bad('refusals: ' . json_encode([$e1, $e2, $e3]));

    // Acceptance of the re-acceptance version.
    $p = wn_legal_pending(7);
    (array_keys($p) === ['privacy_policy'] && (int) $p['privacy_policy']['version_id'] === (int) $v2['version_id'])
        ? ok('2.0 (re-acceptance) is owed by a user who accepted earlier versions') : bad('pending after 2.0: ' . json_encode(array_keys($p)));
    $st = wn_legal_accept(7, (string) $v1['version_id'], 'notice_web');
    !empty($st['errors']['stale']) && count(wn_legal_pending(7)) === 1 ? ok('accepting a stale version id is refused and records nothing') : bad('a stale acceptance was recorded');
    $ac = wn_legal_accept(7, (string) $v2['version_id'], 'notice_app');
    $h = wn_legal_acceptance_history(7);
    (($ac['accepted']['privacy_policy'] ?? 0) == $v2['version_id'] && $h[0]['version_label'] === '2.0' && $h[0]['source'] === 'notice_app' && wn_legal_pending(7) === [])
        ? ok('accepting the shown version records it (2.0, notice_app) and nothing is owed after') : bad('acceptance: ' . json_encode([$ac, $h[0] ?? null]));
    check_reconsent_needed(7) === [] ? ok('check_reconsent_needed() agrees') : bad('check_reconsent_needed() disagrees with wn_legal_pending()');
    $db->exec("UPDATE user SET is_test_account = 1 WHERE user_id = 8");
    check_reconsent_needed(8) === [] ? ok('test accounts stay exempt from the re-consent gate') : bad('test-account exemption lost');

    // Scheduled.
    $tomorrow = gmdate('Y-m-d', time() + 86400);
    $r3 = wn_legal_publish('privacy_policy', 'probeapp', ['content' => "# Privacy\n\nv3", 'summary' => 'Scheduled.', 'requires_reacceptance' => 1, 'effective_date' => $tomorrow]);
    ((wn_legal_current('privacy_policy')['version_id'] ?? 0) == $v2['version_id'] && (wn_legal_upcoming('privacy_policy')['version_id'] ?? 0) == ($r3['version_id'] ?? -1) && wn_legal_pending(7) === [])
        ? ok('a version scheduled for tomorrow is announced but not in force, and not yet owed') : bad('scheduling: ' . json_encode($r3));

    // Public page data + URLs.
    $pg = wn_legal_page('privacy_policy', 'probeapp', '1.0');
    ($pg['version'] && $pg['version']['version_label'] === '1.0' && !$pg['is_current'] && count($pg['history']) === 2)
        ? ok('a permalink serves that exact version, marked as not current, with the history (scheduled one hidden)') : bad('permalink page: ' . json_encode(['v' => $pg['version']['version_label'] ?? null, 'h' => count($pg['history'])]));
    wn_legal_public_url('privacy_policy', 'probeapp', '2.0') === '/admin/legal/privacy/v/2.0' ? ok('without legal.json the public URL is /admin/legal/privacy[/v/<n>]') : bad('public url: ' . wn_legal_public_url('privacy_policy', 'probeapp', '2.0'));

    // Markdown safety.
    $html = wn_legal_markdown("# T <script>alert(1)</script>\n\n[x](javascript:alert(1)) [ok](https://example.org) **b**\n\n- one\n- two");
    (strpos($html, '<script') === false && stripos($html, 'javascript:') === false && strpos($html, '<a href="https://example.org"') !== false && strpos($html, '<li>one</li>') !== false && strpos($html, '<h2>') !== false)
        ? ok('markdown is escaped first: no script, no javascript: links; links, lists, headings render') : bad('markdown: ' . $html);
}

// ── 5b. apps that never opted in: no notice from the 2.5 seed rows ───────────
$la = (string) @file_get_contents($root . '/include/actions/memberActions/legalActions.php');
(strpos($la, 'if (empty($v[\'published_at\'])) continue;') !== false)
    ? ok('the in-app notice ignores unpublished seed rows (apps that never opted in see none)') : bad('getLegalStatus would raise the notice from the 2.5 seed rows');
$lp = (string) @file_get_contents($root . '/legal.php');
(strpos($lp, '\'site/\' . $word . \'.php\'') !== false) ? ok('/admin/legal/<doc> falls back to an app\'s existing page when nothing is published') : bad('legal.php has no fallback to existing site/<doc>.php pages');

// ── 6. dispatched ────────────────────────────────────────────────────────────
$acts = '';
foreach (glob($root . '/include/actions/memberActions/*.php') ?: [] as $f) $acts .= file_get_contents($f);
$missing = [];
foreach (['saveLegalDraft', 'discardLegalDraft', 'publishLegalVersion', 'getLegalStatus', 'acceptLegalUpdate'] as $a) {
    if (!preg_match('~\$_POST\[[\'"]action[\'"]\][^;{]{0,40}?==\s*[\'"]' . $a . '[\'"]~', $acts)) $missing[] = $a;
}
$missing ? bad('actions not dispatched in the idiom api-envelope reads: ' . implode(', ', $missing)) : ok('the five legal actions are dispatched where the API can see them');

echo $fails
    ? "legal-versioning-probe: FAIL — " . count($fails) . " problem(s).\n"
    : "legal-versioning-probe: OK — " . count($oks) . " checks. Published versions are new rows that never change; acceptance records the version.\n";
exit($fails ? 1 : 0);

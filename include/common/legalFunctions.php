<?php
/**
 * legalFunctions.php — versioned legal documents (Privacy Policy, Terms of Service).
 *
 * One published version = one consent_version row, and that row never changes again:
 * nothing in this repo UPDATEs or DELETEs consent_version (scripts/legal-versioning-probe.php
 * fails the deploy if anything does), and content_sha256 makes a change made outside the
 * code visible in the admin history. Editing happens on a draft (consent_version_draft, one
 * per document and app); publishing copies the draft into a NEW row with the next version
 * number and removes the draft.
 *
 * Scope. A document belongs to one app on the deployment (app_slug = the child app's
 * directory under public_html) or to the whole deployment (app_slug = ''). A request is
 * served the newest published version whose effective_date has arrived, preferring the
 * app's own over the deployment-wide one, so an app that never wrote its own keeps the
 * deployment's.
 *
 * Child-app extension point: legal.json at the app's repo root —
 *   {
 *     "privacy_policy":   {"path": "privacy", "default": "legal/privacy_policy.md"},
 *     "terms_of_service": {"path": "terms",   "default": "legal/terms_of_service.md"}
 *   }
 * "path" is the app-relative public URL (the app routes /<slug>/<path> and
 * /<slug>/<path>/v/<label> to its page, which renders with wn_legal_page()); without it the
 * public URL is core's /admin/legal/<privacy|terms>. "default" is the text published as
 * version 1.0 the first time the app has no version of its own (published_by_name
 * 'app default', requires_reacceptance 0 — nobody is asked to accept it again).
 *
 * Acceptance is user_consent (append-only): sign-up records the versions current at that
 * moment, wn_legal_pending() names versions a user still has to accept (a version
 * published with requires_reacceptance, or anything at all when they never accepted),
 * and wn_legal_accept() records them with the door it came through (source) and a hash
 * of the IP.
 */

if (!function_exists('wn_legal_types')) {

/** The documents this system versions: consent_type => labels and the public path word. */
function wn_legal_types() {
    return [
        'privacy_policy'   => ['title' => 'Privacy Policy',   'word' => 'privacy', 'icon' => 'bi-shield-lock'],
        'terms_of_service' => ['title' => 'Terms of Service', 'word' => 'terms',   'icon' => 'bi-file-earmark-text'],
    ];
}

function wn_legal_type_from_word($word) {
    foreach (wn_legal_types() as $type => $t) {
        if ($word === $t['word'] || $word === $type) return $type;
    }
    return null;
}

function wn_legal_today() { return gmdate('Y-m-d'); }

/** The web root (public_html): this file is admin/include/common/. */
function wn_legal_webroot() { return dirname(__DIR__, 3); }

/** Child apps on this deployment: slug => display name. */
function wn_legal_apps() {
    static $apps = null;
    if ($apps !== null) return $apps;
    $apps = [];
    foreach (glob(wn_legal_webroot() . '/*/app/index.php') ?: [] as $file) {
        $slug = basename(dirname($file, 2));
        if ($slug === 'admin' || $slug === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slug)) continue;
        $cfg = wn_legal_config($slug);
        $apps[$slug] = (string) ($cfg['name'] ?? $slug);
    }
    return $apps;
}

/** A child app's legal.json, or []. */
function wn_legal_config($app) {
    static $cache = [];
    $app = (string) $app;
    if ($app === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $app)) return [];
    if (isset($cache[$app])) return $cache[$app];
    $file = wn_legal_webroot() . '/' . $app . '/legal.json';
    $cfg  = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    return $cache[$app] = is_array($cfg) ? $cfg : [];
}

/**
 * The app this request belongs to. Explicit (wn_legal_set_app) wins; then the app whose
 * directory the running script lives in; then — for admin's own pages, the device API and
 * cron — the deployment's child app, the same one-app-per-deployment rule
 * get_post_login_home() uses; '' when there is none.
 */
function wn_legal_set_app($slug) { $GLOBALS['__wn_legal_app'] = (string) $slug; }

function wn_legal_app() {
    if (isset($GLOBALS['__wn_legal_app'])) return $GLOBALS['__wn_legal_app'];
    $apps = wn_legal_apps();
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $root   = realpath(wn_legal_webroot()) ?: wn_legal_webroot();
    if ($script !== '' && strpos($script, $root . '/') === 0) {
        $first = explode('/', substr($script, strlen($root) + 1))[0];
        if (isset($apps[$first])) return $first;
    }
    foreach ($apps as $slug => $_) return $slug;
    return '';
}

/** Low-level PDO access that never flashes a DB error into the session. */
function wn_legal_db() { global $db; return $db; }

function wn_legal_q($sql, array $params = []) {
    $db = wn_legal_db();
    if (!$db) return [];
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('legal: ' . $e->getMessage());
        return [];
    }
}

function wn_legal_exec($sql, array $params = []) {
    $db = wn_legal_db();
    if (!$db) return false;
    try {
        $st = $db->prepare($sql);
        return $st->execute($params);
    } catch (Throwable $e) {
        error_log('legal: ' . $e->getMessage());
        return false;
    }
}

function wn_legal_sha($content) { return hash('sha256', (string) $content); }

/** Is a published row still exactly what was published? null when it predates hashing. */
function wn_legal_intact(array $v) {
    if (empty($v['content_sha256'])) return null;
    return hash_equals((string) $v['content_sha256'], wn_legal_sha($v['content'] ?? ''));
}

// ── Reading versions ─────────────────────────────────────────────────────────

/**
 * The version in force for a document: newest published row whose effective date has
 * arrived, the app's own before the deployment-wide one. Seeds the app's default text
 * (legal.json) the first time the app has none. null when nothing is published at all.
 */
function wn_legal_current($type, $app = null) {
    $app = $app === null ? wn_legal_app() : (string) $app;
    if ($app !== '') wn_legal_seed_default($type, $app);
    $rows = wn_legal_q(
        "SELECT * FROM consent_version
          WHERE consent_type = ? AND app_slug IN (?, '') AND effective_date <= ?
          ORDER BY CASE WHEN app_slug = ? THEN 0 ELSE 1 END, version_id DESC
          LIMIT 1",
        [$type, $app, wn_legal_today(), $app]);
    return $rows[0] ?? null;
}

/** Published versions of one document in one scope, newest first (history). */
function wn_legal_versions($type, $app) {
    return wn_legal_q(
        "SELECT * FROM consent_version WHERE consent_type = ? AND app_slug = ? ORDER BY version_id DESC",
        [$type, (string) $app]);
}

function wn_legal_version($version_id) {
    $rows = wn_legal_q("SELECT * FROM consent_version WHERE version_id = ?", [(int) $version_id]);
    return $rows[0] ?? null;
}

/** A permalink lookup: the version with this label, the app's own before the deployment's. */
function wn_legal_version_by_label($type, $app, $label) {
    $rows = wn_legal_q(
        "SELECT * FROM consent_version
          WHERE consent_type = ? AND app_slug IN (?, '') AND version_label = ? AND effective_date IS NOT NULL
          ORDER BY CASE WHEN app_slug = ? THEN 0 ELSE 1 END, version_id DESC LIMIT 1",
        [$type, (string) $app, (string) $label, (string) $app]);
    return $rows[0] ?? null;
}

/** A published version that has not taken effect yet (scheduled), or null. */
function wn_legal_upcoming($type, $app = null) {
    $app = $app === null ? wn_legal_app() : (string) $app;
    $rows = wn_legal_q(
        "SELECT * FROM consent_version
          WHERE consent_type = ? AND app_slug IN (?, '') AND effective_date > ?
          ORDER BY effective_date ASC, version_id ASC LIMIT 1",
        [$type, $app, wn_legal_today()]);
    return $rows[0] ?? null;
}

/** How many users have accepted each version id (admin history). */
function wn_legal_acceptance_counts(array $version_ids) {
    $ids = array_values(array_filter(array_map('intval', $version_ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach (wn_legal_q("SELECT consent_version_id AS vid, COUNT(DISTINCT user_id) AS n FROM user_consent
                          WHERE action = 'granted' AND consent_version_id IN ($in) GROUP BY consent_version_id") as $r) {
        $out[(int) $r['vid']] = (int) $r['n'];
    }
    return $out;
}

// ── Version numbers ──────────────────────────────────────────────────────────

/**
 * Next label in a scope: a change that asks people to accept again is a major version
 * (1.3 → 2.0), any other change a minor one (1.3 → 1.4). The first is 1.0.
 */
function wn_legal_next_label($type, $app, $major) {
    $best = null;
    foreach (wn_legal_versions($type, $app) as $v) {
        if (!preg_match('/^(\d+)(?:\.(\d+))?$/', (string) $v['version_label'], $m)) continue;
        $cand = [(int) $m[1], (int) ($m[2] ?? 0)];
        if ($best === null || $cand[0] > $best[0] || ($cand[0] === $best[0] && $cand[1] > $best[1])) $best = $cand;
    }
    if ($best === null) return '1.0';
    return $major ? ($best[0] + 1) . '.0' : $best[0] . '.' . ($best[1] + 1);
}

function wn_legal_label_taken($type, $app, $label) {
    return (bool) wn_legal_q("SELECT version_id FROM consent_version WHERE consent_type = ? AND app_slug = ? AND version_label = ?",
                             [$type, (string) $app, (string) $label]);
}

// ── Drafts ───────────────────────────────────────────────────────────────────

function wn_legal_draft($type, $app) {
    $rows = wn_legal_q("SELECT * FROM consent_version_draft WHERE consent_type = ? AND app_slug = ?", [$type, (string) $app]);
    return $rows[0] ?? null;
}

/** Create or replace the draft. $f: title, content, summary, requires_reacceptance, effective_date. */
function wn_legal_save_draft($type, $app, array $f, $actor_id = null, $actor_name = null) {
    $vals = [
        (string) ($f['title'] ?? ''), (string) ($f['content'] ?? ''), (string) ($f['summary'] ?? ''),
        !empty($f['requires_reacceptance']) ? 1 : 0,
        !empty($f['effective_date']) ? (string) $f['effective_date'] : null,
        $actor_id !== null ? (int) $actor_id : null, $actor_name, gmdate('Y-m-d H:i:s'),
    ];
    if (wn_legal_draft($type, $app)) {
        return wn_legal_exec(
            "UPDATE consent_version_draft SET title = ?, content = ?, summary = ?, requires_reacceptance = ?, effective_date = ?,
                    updated_by = ?, updated_by_name = ?, updated = ? WHERE consent_type = ? AND app_slug = ?",
            array_merge($vals, [$type, (string) $app]));
    }
    return wn_legal_exec(
        "INSERT INTO consent_version_draft (title, content, summary, requires_reacceptance, effective_date, updated_by, updated_by_name, updated, consent_type, app_slug)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        array_merge($vals, [$type, (string) $app]));
}

function wn_legal_discard_draft($type, $app) {
    return wn_legal_exec("DELETE FROM consent_version_draft WHERE consent_type = ? AND app_slug = ?", [$type, (string) $app]);
}

// ── Publishing ───────────────────────────────────────────────────────────────

/**
 * Publish a NEW version. Never touches an existing row.
 * $f: content (markdown, required), title, summary (what changed — shown to users),
 *     requires_reacceptance, effective_date (Y-m-d, today or later; default today),
 *     version_label (default wn_legal_next_label).
 * @return array ['version_id' => int, 'version_label' => string] or ['errors' => [...]]
 */
function wn_legal_publish($type, $app, array $f, $actor_id = null, $actor_name = null) {
    $errs  = [];
    $types = wn_legal_types();
    $app   = (string) $app;
    if (!isset($types[$type])) $errs['type'] = 'Unknown document type.';
    if ($app !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $app)) $errs['app'] = 'Unknown app.';
    $content = str_replace("\r\n", "\n", (string) ($f['content'] ?? ''));
    if (trim($content) === '') $errs['content'] = 'The document is empty.';
    $major = !empty($f['requires_reacceptance']);
    $label = trim((string) ($f['version_label'] ?? ''));
    if ($label === '' && !$errs) $label = wn_legal_next_label($type, $app, $major);
    if ($label !== '' && !preg_match('/^[0-9][0-9A-Za-z.\-]{0,31}$/', $label)) $errs['label'] = 'Version numbers look like 2.0 or 1.4.';
    elseif ($label !== '' && wn_legal_label_taken($type, $app, $label)) $errs['label'] = 'Version ' . $label . ' already exists — published versions never change.';
    $eff = trim((string) ($f['effective_date'] ?? ''));
    if ($eff === '') $eff = wn_legal_today();
    $d = DateTime::createFromFormat('!Y-m-d', $eff, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $eff) $errs['effective'] = 'Effective date must be a date.';
    elseif ($eff < wn_legal_today()) $errs['effective'] = 'Effective date cannot be in the past.';
    if ($errs) return ['errors' => $errs];

    $title = trim((string) ($f['title'] ?? '')) ?: $types[$type]['title'];
    $ok = wn_legal_exec(
        "INSERT INTO consent_version (consent_type, app_slug, version_label, effective_date, summary, content, title,
                                      requires_reacceptance, content_sha256, published_by, published_by_name, published_at, is_active, created)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)",
        [$type, $app, $label, $eff, trim((string) ($f['summary'] ?? '')), $content, $title,
         $major ? 1 : 0, wn_legal_sha($content), $actor_id !== null ? (int) $actor_id : null, $actor_name,
         gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
    if (!$ok) return ['errors' => ['db' => 'The version could not be saved.']];
    $vid = (int) wn_legal_db()->lastInsertId();
    wn_legal_discard_draft($type, $app);
    return ['version_id' => $vid, 'version_label' => $label];
}

/**
 * First visit of an app with no version of its own: publish its declared default text
 * (legal.json "default") as 1.0. Nobody is asked to accept it again.
 */
function wn_legal_seed_default($type, $app) {
    static $done = [];
    $key = $type . '|' . $app;
    if (isset($done[$key])) return;
    $done[$key] = true;
    $cfg = wn_legal_config($app);
    $rel = (string) ($cfg[$type]['default'] ?? '');
    if ($rel === '' || strpos($rel, '..') !== false) return;
    $file = wn_legal_webroot() . '/' . $app . '/' . ltrim($rel, '/');
    if (!is_file($file)) return;
    if (wn_legal_q("SELECT version_id FROM consent_version WHERE consent_type = ? AND app_slug = ? LIMIT 1", [$type, $app])) return;
    $md = (string) file_get_contents($file);
    if (trim($md) === '') return;
    wn_legal_publish($type, $app, [
        'content' => $md, 'summary' => 'First published version.', 'requires_reacceptance' => 0,
        'version_label' => '1.0', 'effective_date' => wn_legal_today(),
    ], null, 'app default');
}

// ── Acceptance ───────────────────────────────────────────────────────────────

function wn_legal_ip_hash($ip = null) {
    $ip = $ip === null ? (string) ($_SERVER['REMOTE_ADDR'] ?? '') : (string) $ip;
    return $ip === '' ? null : hash('sha256', 'wn-consent-ip|' . $ip);
}

/**
 * Versions this user still has to accept: type => version row (+ 'url', 'kind').
 *   kind 'none'   — never accepted this document at all (as before 5.3: the sign-in gate)
 *   kind 'first'  — accepted only another scope's version (e.g. the whole-site seed) and never
 *                   this app's own: an app that publishes its own text asks everyone once
 *   kind 'update' — a version published with requires_reacceptance has taken effect since the
 *                   newest one they accepted in this scope
 * A version published without re-acceptance asks nobody who accepted an earlier one.
 * An app with no versions of its own is served the whole-site rows, so nothing changes for it.
 */
function wn_legal_pending($user_id, $app = null) {
    $uid = (int) $user_id;
    $app = $app === null ? wn_legal_app() : (string) $app;
    $out = [];
    foreach (wn_legal_types() as $type => $t) {
        $cur = wn_legal_current($type, $app);
        if (!$cur) continue;
        $scope = (string) $cur['app_slug'];
        $any = wn_legal_q("SELECT consent_version_id FROM user_consent WHERE user_id = ? AND consent_type = ? AND action = 'granted' LIMIT 1", [$uid, $type]);
        if (!$any) { $out[$type] = $cur + ['kind' => 'none']; continue; }
        $inScope = wn_legal_q(
            "SELECT MAX(cv.version_id) AS vid FROM user_consent uc JOIN consent_version cv ON cv.version_id = uc.consent_version_id
              WHERE uc.user_id = ? AND uc.consent_type = ? AND uc.action = 'granted' AND cv.app_slug = ?",
            [$uid, $type, $scope]);
        $have = (int) ($inScope[0]['vid'] ?? 0);
        if ($have === 0) {
            // The whole-site seed rows (no text) were all anyone accepted before an app had
            // its own: only an app's OWN document makes that a 'first' acceptance.
            if ($scope !== '') $out[$type] = $cur + ['kind' => 'first'];
            elseif (!empty($cur['requires_reacceptance'])) $out[$type] = $cur + ['kind' => 'update'];
            continue;
        }
        $req = wn_legal_q(
            "SELECT version_id FROM consent_version
              WHERE consent_type = ? AND app_slug = ? AND requires_reacceptance = 1 AND effective_date <= ?
              ORDER BY version_id DESC LIMIT 1",
            [$type, $scope, wn_legal_today()]);
        if ($req && $have < (int) $req[0]['version_id']) $out[$type] = $cur + ['kind' => 'update'];
    }
    foreach ($out as $type => $v) $out[$type]['url'] = wn_legal_public_url($type, $app, null, true);
    return $out;
}

/**
 * What the SIGN-IN gate (auth/consent.php) blocks on: everything owed except a 'first'
 * acceptance, which the in-app notice asks for in one tap instead of stopping sign-in.
 */
function wn_legal_gate_needed($user_id) {
    $p = function_exists('check_reconsent_needed') ? check_reconsent_needed($user_id) : [];
    return array_filter($p, function ($v) { return ($v['kind'] ?? '') !== 'first'; });
}

/** The versions in force, for a sign-up form to show and post back (legal_version_ids). */
function wn_legal_signup_versions($app = null) {
    $out = [];
    foreach (wn_legal_types() as $type => $t) {
        $v = wn_legal_current($type, $app);
        if (!$v) continue;
        $out[$type] = ['version_id' => (int) $v['version_id'], 'version_label' => (string) $v['version_label'],
                       'title' => $t['title'], 'url' => wn_legal_public_url($type, $app, null, true)];
    }
    return $out;
}

/**
 * The version id to record for a sign-up: the one the form showed (posted legal_version_ids)
 * when it is a published, in-force-or-earlier version of that document in this app's scope;
 * otherwise the one in force now.
 */
function wn_legal_signup_version_id($type, $posted, $app = null) {
    $app = $app === null ? wn_legal_app() : (string) $app;
    $cur = wn_legal_current($type, $app);
    $ids = array_filter(array_map('intval', is_array($posted) ? $posted : explode(',', (string) $posted)));
    foreach ($ids as $id) {
        $v = wn_legal_version($id);
        if ($v && $v['consent_type'] === $type && $cur && (string) $v['app_slug'] === (string) $cur['app_slug']
            && $v['effective_date'] <= wn_legal_today()) return (int) $id;
    }
    return $cur ? (int) $cur['version_id'] : null;
}

/**
 * Record acceptance of exactly the versions that are pending now. $version_ids, when given,
 * must name them (what the person was shown); a stale id is refused, never recorded.
 * @return array ['accepted' => [type => version_id]] or ['errors' => [...]]
 */
function wn_legal_accept($user_id, $version_ids = null, $source = 'notice', $app = null) {
    $pending = wn_legal_pending($user_id, $app);
    if (!$pending) return ['accepted' => []];
    if ($version_ids !== null) {
        $want = array_map('intval', is_array($version_ids) ? $version_ids : explode(',', (string) $version_ids));
        $owed = array_map(function ($v) { return (int) $v['version_id']; }, array_values($pending));
        sort($want); sort($owed);
        if ($want !== $owed) return ['errors' => ['stale' => 'These policies changed while you were reading. Please review the latest version.']];
    }
    $done = [];
    foreach ($pending as $type => $v) {
        record_consent($user_id, $type, 'granted', (int) $v['version_id'], $source);
        $done[$type] = (int) $v['version_id'];
    }
    return ['accepted' => $done];
}

/** Acceptance history for a user, newest first, with the version each event refers to. */
function wn_legal_acceptance_history($user_id) {
    return wn_legal_q(
        "SELECT uc.consent_id, uc.consent_type, uc.action, uc.created, uc.source, uc.ip_hash,
                cv.version_id, cv.version_label, cv.app_slug, cv.effective_date
           FROM user_consent uc LEFT JOIN consent_version cv ON cv.version_id = uc.consent_version_id
          WHERE uc.user_id = ? AND uc.consent_type IN ('privacy_policy', 'terms_of_service')
          ORDER BY uc.created DESC, uc.consent_id DESC",
        [(int) $user_id]);
}

// ── Public URLs and pages ────────────────────────────────────────────────────

function wn_legal_origin() {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) return '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * Public URL of a document (current version) or of one version (permalink).
 * Root-relative unless $absolute.
 */
function wn_legal_public_url($type, $app = null, $label = null, $absolute = false) {
    $app   = $app === null ? wn_legal_app() : (string) $app;
    $types = wn_legal_types();
    if (!isset($types[$type])) return '';
    $cfg  = wn_legal_config($app);
    $path = (string) ($cfg[$type]['path'] ?? '');
    if ($app !== '' && $path !== '' && preg_match('#^[A-Za-z0-9_\-/]+$#', $path)) {
        $url = '/' . $app . '/' . trim($path, '/');
    } else {
        $url = '/admin/legal/' . $types[$type]['word'];
    }
    if ($label !== null && $label !== '') $url .= '/v/' . rawurlencode((string) $label);
    return ($absolute ? wn_legal_origin() : '') . $url;
}

/**
 * Everything a public page needs: the version to show ($label = a permalink), its HTML,
 * the history list with links, and a scheduled successor. 'version' is null when nothing
 * is published (or the label is unknown).
 */
function wn_legal_page($type, $app = null, $label = null) {
    $app = $app === null ? wn_legal_app() : (string) $app;
    $cur = wn_legal_current($type, $app);
    $v   = ($label !== null && $label !== '') ? wn_legal_version_by_label($type, $app, $label) : $cur;
    if ($v && $v['effective_date'] > wn_legal_today() && ($label === null || $label === '')) $v = null;
    $scope = $cur ? (string) $cur['app_slug'] : $app;
    $hist = [];
    foreach (wn_legal_versions($type, $scope) as $h) {
        if ($h['effective_date'] > wn_legal_today()) continue;
        $hist[] = $h + ['url' => wn_legal_public_url($type, $app, $h['version_label'])];
    }
    $types = wn_legal_types();
    return [
        'type'     => $type,
        'title'    => $v && !empty($v['title']) ? $v['title'] : $types[$type]['title'],
        'version'  => $v,
        'is_current' => $v && $cur && (int) $v['version_id'] === (int) $cur['version_id'],
        'current'  => $cur,
        'html'     => $v ? wn_legal_markdown((string) ($v['content'] ?? '')) : '',
        'history'  => $hist,
        'upcoming' => wn_legal_upcoming($type, $app),
        'url'      => wn_legal_public_url($type, $app),
        'other_url'=> wn_legal_public_url($type === 'privacy_policy' ? 'terms_of_service' : 'privacy_policy', $app),
    ];
}

// ── Markdown (a small, safe subset) ──────────────────────────────────────────

/**
 * Headings (#..####), paragraphs, - / * / 1. lists, **bold**, *italic*, `code`,
 * [text](url) with http(s)/mailto/relative URLs only, --- rules and > quotes.
 * Everything is escaped first, so admin-written text can never inject markup.
 */
function wn_legal_markdown($md) {
    $esc = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $inline = function ($s) use ($esc) {
        $s = $esc($s);
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![*\w])\*([^*\s][^*]*)\*(?!\*)/', '<em>$1</em>', $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^(https?://|mailto:|/|\#|[A-Za-z0-9_\-./]+$)#i', $url) || preg_match('#^\s*(javascript|data|vbscript):#i', $url)) return $m[1];
            $ext = preg_match('#^https?://#i', $url) ? ' rel="noopener" target="_blank"' : '';
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $ext . '>' . $m[1] . '</a>';
        }, $s);
        return $s;
    };
    $lines = explode("\n", str_replace("\r\n", "\n", (string) $md));
    $html = ''; $para = []; $list = null; $quote = [];
    $flushP = function () use (&$para, &$html, $inline) {
        if ($para) { $html .= '<p>' . $inline(implode(' ', $para)) . "</p>\n"; $para = []; }
    };
    $flushL = function () use (&$list, &$html) {
        if ($list) { $html .= '<' . $list['tag'] . '>' . implode('', $list['items']) . '</' . $list['tag'] . ">\n"; $list = null; }
    };
    $flushQ = function () use (&$quote, &$html, $inline) {
        if ($quote) { $html .= '<blockquote class="blockquote small"><p>' . $inline(implode(' ', $quote)) . "</p></blockquote>\n"; $quote = []; }
    };
    foreach ($lines as $line) {
        $t = rtrim($line);
        if (trim($t) === '') { $flushP(); $flushL(); $flushQ(); continue; }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $flushP(); $flushL(); $flushQ();
            $lvl = min(6, strlen($m[1]) + 1);   // # → h2: the page owns the h1
            $html .= "<h$lvl>" . $inline($m[2]) . "</h$lvl>\n";
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})$/', trim($t))) { $flushP(); $flushL(); $flushQ(); $html .= "<hr>\n"; continue; }
        if (preg_match('/^>\s?(.*)$/', $t, $m)) { $flushP(); $flushL(); $quote[] = $m[1]; continue; }
        if (preg_match('/^\s*([-*]|\d+[.)])\s+(.*)$/', $t, $m)) {
            $flushP(); $flushQ();
            $tag = ctype_digit(substr($m[1], 0, 1)) ? 'ol' : 'ul';
            if ($list && $list['tag'] !== $tag) $flushL();
            if (!$list) $list = ['tag' => $tag, 'items' => []];
            $list['items'][] = '<li>' . $inline($m[2]) . '</li>';
            continue;
        }
        if ($list && preg_match('/^\s{2,}(.*)$/', $t, $m)) {   // continuation of a list item
            $last = array_pop($list['items']);
            $list['items'][] = substr($last, 0, -5) . ' ' . $inline($m[1]) . '</li>';
            continue;
        }
        $flushL(); $flushQ();
        $para[] = trim($t);
    }
    $flushP(); $flushL(); $flushQ();
    return $html;
}

// ── Diff ─────────────────────────────────────────────────────────────────────

/**
 * Line diff between two texts: a list of ['op' => '=', '-' or '+', 'line' => string].
 * LCS over lines; texts in this system are a few hundred lines at most.
 */
function wn_legal_diff($old, $new) {
    $a = explode("\n", str_replace("\r\n", "\n", (string) $old));
    $b = explode("\n", str_replace("\r\n", "\n", (string) $new));
    // Trim the shared head and tail so the table stays small.
    $pre = 0;
    while ($pre < count($a) && $pre < count($b) && $a[$pre] === $b[$pre]) $pre++;
    $suf = 0;
    while ($suf < count($a) - $pre && $suf < count($b) - $pre && $a[count($a) - 1 - $suf] === $b[count($b) - 1 - $suf]) $suf++;
    $am = array_slice($a, $pre, count($a) - $pre - $suf);
    $bm = array_slice($b, $pre, count($b) - $pre - $suf);
    $n = count($am); $m = count($bm);
    $out = [];
    foreach (array_slice($a, 0, $pre) as $l) $out[] = ['op' => '=', 'line' => $l];
    if ($n * $m > 4000000) {   // pathological size: show it as replaced wholesale
        foreach ($am as $l) $out[] = ['op' => '-', 'line' => $l];
        foreach ($bm as $l) $out[] = ['op' => '+', 'line' => $l];
    } else {
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--)
            for ($j = $m - 1; $j >= 0; $j--)
                $L[$i][$j] = $am[$i] === $bm[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
        $i = 0; $j = 0;
        while ($i < $n && $j < $m) {
            if ($am[$i] === $bm[$j]) { $out[] = ['op' => '=', 'line' => $am[$i]]; $i++; $j++; }
            elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $out[] = ['op' => '-', 'line' => $am[$i]]; $i++; }
            else { $out[] = ['op' => '+', 'line' => $bm[$j]]; $j++; }
        }
        for (; $i < $n; $i++) $out[] = ['op' => '-', 'line' => $am[$i]];
        for (; $j < $m; $j++) $out[] = ['op' => '+', 'line' => $bm[$j]];
    }
    foreach (array_slice($a, count($a) - $suf) as $l) $out[] = ['op' => '=', 'line' => $l];
    return $out;
}

}

if (!function_exists('wn_legal_render_public')) {
/**
 * Send a whole public page for a document (no login, no session needed): the current
 * version, or one version when $label is given, with the history of permalinks below it.
 * Self-contained (root-relative / CDN URLs only), so it renders the same at /admin/legal/…,
 * at an app's own /<slug>/privacy and at any /v/<label> depth.
 * $opts: home_url, home_label, brand (site name override).
 */
function wn_legal_render_public($type, $app = null, $label = null, array $opts = []) {
    $legal = wn_legal_page($type, $app, $label);
    if (!$legal['version'] && !headers_sent()) http_response_code(($label !== null && $label !== '') ? 404 : 200);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $branding = function_exists('get_branding') ? (get_branding() ?: []) : [];
    $brand    = (string) ($opts['brand'] ?? ($branding['site_name'] ?? ''));
    $home_url = (string) ($opts['home_url'] ?? '/');
    $home_label = (string) ($opts['home_label'] ?? ('Back to ' . ($brand !== '' ? $brand : 'the site')));
    include dirname(__DIR__, 2) . '/snippets/legal_page.php';
}
}

<?php
/**
 * scripts/i18n-probe.php — the translation contract (include/common/i18nFunctions.php).
 *
 * Builds a throwaway catalog directory and checks: no catalog means English and no behaviour
 * change; lookups fall back pt-BR → pt → default; the cookie and Accept-Language pick only
 * published locales; a hostile locale can never reach a file outside the catalog directory; a
 * broken catalog or translation shows the English default. DB-free.
 *
 * Usage: php scripts/i18n-probe.php    Exit 0 clean, 1 on a finding.
 */
$dir = sys_get_temp_dir() . '/i18n-probe-' . getmypid();
define('WN_I18N_DIR', $dir);
require __DIR__ . '/../include/common/i18nFunctions.php';

$fail = 0;
function ip_check($label, $got, $want) {
    global $fail;
    if ($got === $want) { echo "ok   $label\n"; return; }
    $fail++;
    echo "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}
function ip_reset() {
    wn_i18n_locales(true);
    wn_i18n_catalog('en', true);
    wn_set_locale('');
    unset($_COOKIE[WN_I18N_COOKIE], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
}

// No sub-admin: nothing changes.
ip_check('no catalogs: only the default locale', wn_i18n_locales(), ['en']);
ip_check('no catalogs: locale is en', wn_locale(), 'en');
ip_check('no catalogs: default text', wn_t('a.b', 'Hello'), 'Hello');
ip_check('no catalogs: params still fill', wn_t('a.c', 'Hi {name}', ['name' => 'Ann']), 'Hi Ann');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
ip_check('no catalogs: browser language ignored', wn_locale(), 'en');
ip_reset();

// Normalising.
ip_check('normalise underscore', wn_locale_normalize('pt_br'), 'pt-BR');
ip_check('normalise script', wn_locale_normalize('ZH-hant-tw'), 'zh-Hant-TW');
foreach (['../../etc/passwd', 'en/../x', 'en.json', "en\0", '', 'a', 'en-'] as $bad) {
    ip_check('refused tag ' . json_encode($bad), wn_locale_normalize($bad), '');
}
ip_check('fallback chain', wn_locale_fallbacks('zh-Hant-TW'), ['zh-Hant-TW', 'zh-Hant', 'zh']);

// Catalogs.
mkdir($dir, 0700, true);
file_put_contents("$dir/es.json", json_encode([
    'greet' => 'Hola {name}',
    'people' => '{n, plural, one {# persona} other {# personas}}',
    'broken' => '{n, plural, one {',
    'blank' => '',
]));
file_put_contents("$dir/pt.json", json_encode(['only.pt' => 'Olá']));
file_put_contents("$dir/pt-BR.json", json_encode(['greet' => 'Oi {name}']));
file_put_contents("$dir/ar.json", json_encode(['greet' => 'مرحبا']));
file_put_contents("$dir/de.json", 'this is not json');
file_put_contents("$dir/secret.txt", '{"greet":"leak"}');
file_put_contents(dirname($dir) . '/i18n-outside-' . getmypid() . '.json', '{"greet":"leak"}');
ip_reset();

ip_check('published locales', (function () { $l = wn_i18n_locales(); sort($l); return $l; })(), ['ar', 'de', 'en', 'es', 'pt', 'pt-BR']);
ip_check('explicit locale', wn_t('greet', 'Hello {name}', ['name' => 'Ann'], 'es'), 'Hola Ann');
ip_check('region wins', wn_t('greet', 'Hello {name}', ['name' => 'Ann'], 'pt-BR'), 'Oi Ann');
ip_check('region falls back to language', wn_t('only.pt', 'Hi', [], 'pt-BR'), 'Olá');
ip_check('missing key falls back to English', wn_t('nope', 'Hello', [], 'es'), 'Hello');
ip_check('blank translation falls back to English', wn_t('blank', 'Hello', [], 'es'), 'Hello');
ip_check('unreadable catalog falls back to English', wn_t('greet', 'Hello', [], 'de'), 'Hello');
ip_check('broken translation falls back to English', wn_t('broken', 'Hello', ['n' => 1], 'es'), 'Hello');
ip_check('unpublished locale falls back to English', wn_t('greet', 'Hello', [], 'fr'), 'Hello');
ip_check('hostile locale reads nothing', wn_t('greet', 'Hello', [], '../i18n-outside-' . getmypid()), 'Hello');
if (class_exists('MessageFormatter')) {
    ip_check('plural one', wn_t('people', 'x', ['n' => 1], 'es'), '1 persona');
    ip_check('plural other', wn_t('people', 'x', ['n' => 3], 'es'), '3 personas');
} else {
    ip_check('no intl: plural falls back to English', wn_t('people', 'People', ['n' => 3], 'es'), 'People');
}

// Choosing the locale.
ip_check('browser picks by q', wn_locale_negotiate('fr;q=0.9, es;q=0.8, pt;q=0.95', ['en', 'es', 'pt']), 'pt');
ip_check('browser q=0 is a refusal', wn_locale_negotiate('es;q=0', ['en', 'es']), '');
ip_check('browser region served by language', wn_locale_negotiate('es-MX', ['en', 'es']), 'es');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es';
ip_check('browser language', wn_locale(), 'es');
$_COOKIE[WN_I18N_COOKIE] = 'pt-BR';
ip_check('cookie beats browser', wn_locale(), 'pt-BR');
$_COOKIE[WN_I18N_COOKIE] = '../../secret';
ip_check('hostile cookie is ignored', wn_locale(), 'es');
wn_set_locale('ar');
ip_check('explicit beats cookie', wn_locale(), 'ar');
ip_check('rtl', wn_locale_dir(), 'rtl');
ip_check('ltr', wn_locale_dir('es'), 'ltr');
ip_reset();

// i18n.php's payload: published locales only, fallback merged, nothing from outside the directory.
$body = fn($raw) => json_decode(wn_i18n_payload($raw)['body'], true);
ip_check('wanted drops hostile tags', wn_i18n_wanted('../x, es_MX ,en.json,pt-br'), ['es-MX', 'pt-BR']);
ip_check('wanted stops at 8', count(wn_i18n_wanted(implode(',', ['aa', 'ab', 'ac', 'ad', 'ae', 'af', 'ag', 'ah', 'ai']))), 8);
ip_check('payload: nothing usable', wn_i18n_payload('../i18n-outside-' . getmypid())['status'], 400);
ip_check('payload: empty', wn_i18n_payload('')['status'], 400);
ip_check('payload: unpublished', wn_i18n_payload('fr,sv')['status'], 404);
ip_check('payload: first published wins', $body('fr,pt,es')['locale'], 'pt');
ip_check('payload: region served by language', $body('es-MX')['locale'], 'es');
ip_check('payload: region laid over language', $body('pt-BR')['strings'], ['greet' => 'Oi {name}', 'only.pt' => 'Olá']);
ip_check('payload: blank translation left out', isset($body('es')['strings']['blank']), false);
ip_check('payload: english wanted first', $body('en-GB,es'), ['locale' => 'en', 'dir' => 'ltr', 'strings' => []]);
ip_check('payload: rtl', $body('ar')['dir'], 'rtl');
ip_check('payload: unreadable catalog is empty', $body('de')['strings'], []);
ip_check('payload: etag follows content', wn_i18n_payload('es')['etag'] === wn_i18n_payload('es')['etag'] && wn_i18n_payload('es')['etag'] !== wn_i18n_payload('pt')['etag'], true);
$etag = wn_i18n_payload('es')['etag'];
ip_check('etag match', wn_i18n_etag_matches($etag, $etag), true);
ip_check('etag weak + gzip quoted', wn_i18n_etag_matches('"x", W/"' . substr($etag, 1, -1) . '-gzip"', $etag), true);
ip_check('etag miss', wn_i18n_etag_matches('"nope"', $etag), false);
ip_check('etag empty', wn_i18n_etag_matches('""', ''), false);

array_map('unlink', glob("$dir/*"));
rmdir($dir);
@unlink(dirname($dir) . '/i18n-outside-' . getmypid() . '.json');
exit($fail ? 1 : 0);

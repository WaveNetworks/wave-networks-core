<?php
/**
 * legal.php — public Privacy Policy / Terms of Service, every deployment.
 *
 *   /admin/legal/privacy            current version   (.htaccess → legal.php?doc=privacy)
 *   /admin/legal/privacy/v/<label>  that version, forever (a permalink)
 *   /admin/legal/terms[/v/<label>]
 *
 * No login. When the deployment's app serves its own page (legal.json "path"), the
 * current-version URL forwards there, so there is one canonical address per document.
 * Versions and their text: include/common/legalFunctions.php, edited in Admin › Legal.
 */
include(__DIR__ . '/include/common_auth.php');

$type  = wn_legal_type_from_word((string) ($_GET['doc'] ?? ''));
$label = trim((string) ($_GET['v'] ?? ''));
if (!$type) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
$app = isset($_GET['app']) && isset(wn_legal_apps()[$_GET['app']]) ? (string) $_GET['app'] : wn_legal_app();
$own = wn_legal_public_url($type, $app, $label !== '' ? $label : null);
if (strpos($own, '/admin/legal/') !== 0) {
    header('Location: ' . $own, true, 302);
    exit;
}
// An app that has not published its text here keeps the page it already had: the
// marketing site's /site/<doc>.php, or the app's own /<app>/<doc>.php. (Device sign-up links
// here for every app; before 5.3 they went to ../site/<doc>.php.)
$cur = wn_legal_current($type, $app);
if ($label === '' && (!$cur || trim((string) ($cur['content'] ?? '')) === '')) {
    $word = wn_legal_types()[$type]['word'];
    foreach (['site/' . $word . '.php', ($app !== '' ? $app . '/' : '') . $word . '.php'] as $rel) {
        if ($rel !== $word . '.php' && is_file(wn_legal_webroot() . '/' . $rel)) {
            header('Location: /' . $rel, true, 302);
            exit;
        }
    }
}
wn_legal_render_public($type, $app, $label !== '' ? $label : null);

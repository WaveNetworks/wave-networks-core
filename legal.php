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
wn_legal_render_public($type, $app, $label !== '' ? $label : null);

<?php
// One-shot opcache flush — token-guarded. Lets a freshly-deployed code change
// take effect on this opcache host without an FPM reload. Remove after use.
// (A brand-new file is always compiled fresh, so this runs even when other
// files are served stale from opcache.)
if ((($_GET['t'] ?? '') !== 'oc_7dd680a27b05d985fb59d06f')) { http_response_code(403); echo 'forbidden'; exit; }
header('Content-Type: application/json');
$ok = function_exists('opcache_reset') ? opcache_reset() : null;
echo json_encode(['opcache_reset' => $ok, 'enabled' => function_exists('opcache_reset')]);

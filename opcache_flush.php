<?php
if ((($_GET['t'] ?? '') !== 'oc_76ff10059ef9b6d348fa1b0d')) { http_response_code(403); echo 'forbidden'; exit; }
header('Content-Type: application/json');
echo json_encode(['opcache_reset' => function_exists('opcache_reset') ? opcache_reset() : null]);

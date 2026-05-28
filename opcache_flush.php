<?php
if ((($_GET['t'] ?? '') !== 'oc_b776df257359486c8af6faa4')) { http_response_code(403); echo 'forbidden'; exit; }
header('Content-Type: application/json');
echo json_encode(['opcache_reset' => function_exists('opcache_reset') ? opcache_reset() : null]);

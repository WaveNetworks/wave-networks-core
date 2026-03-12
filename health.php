<?php
header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
]);

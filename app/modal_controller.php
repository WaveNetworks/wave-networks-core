<?php
/**
 * modal_controller.php
 * AJAX modal content loader.
 * Returns HTML snippets for modals loaded via JavaScript fetch().
 */
include(__DIR__ . '/../include/common.php');

$modal = $_GET['modal'] ?? '';

// Map modal names to snippet files
$modals = [
    'edit_api_key_scopes' => __DIR__ . '/../snippets/modal_edit_api_key_scopes.php',
];

if (isset($modals[$modal]) && file_exists($modals[$modal])) {
    include($modals[$modal]);
} else {
    if (!headers_sent()) { http_response_code(404); }
    echo '<p>Modal not found.</p>';
}

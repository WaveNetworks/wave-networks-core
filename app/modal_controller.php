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
    // Add modal mappings as needed
    // 'user_edit' => __DIR__ . '/../snippets/modal_user_edit.php',
];

if (isset($modals[$modal]) && file_exists($modals[$modal])) {
    include($modals[$modal]);
} else {
    http_response_code(404);
    echo '<p>Modal not found.</p>';
}

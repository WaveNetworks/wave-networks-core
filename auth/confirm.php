<?php
include(__DIR__ . '/../include/common_auth.php');

$hash = $_GET['hash'] ?? '';
if ($hash) {
    // Trigger the confirmAccount action
    $_POST['action'] = 'confirmAccount';
    $_POST['hash']   = $hash;
    // Action is already included via common_auth.php → loginActions.php
}

header('Location: login.php');
exit;

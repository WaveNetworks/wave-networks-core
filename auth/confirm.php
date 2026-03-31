<?php
// Set action BEFORE including common_auth.php so definition.php picks it up
// and loginActions.php fires the confirmAccount action during the include chain.
$hash = $_GET['hash'] ?? '';
if ($hash) {
    $_REQUEST['action'] = 'confirmAccount';
}

include(__DIR__ . '/../include/common_auth.php');

header('Location: login.php');
exit;

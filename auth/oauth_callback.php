<?php
// OAuth sign-in/link. The flow lives in oauthFunctions.php (oauth_handle_callback_page) so every
// child app's copy of this page is these two lines.
include(__DIR__ . '/../include/common_auth.php');
oauth_handle_callback_page();

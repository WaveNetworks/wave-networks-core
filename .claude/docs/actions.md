# Action files — invocation, patterns, API responses

## ACTION FILES — TWO INVOCATION METHODS
Action files are auto-included via glob in common.php / common_api.php / common_auth.php.
They run on EVERY request before views render. No routing config needed.

CORRECT: Add action files to admin/include/actions/
  Authenticated: admin/include/actions/memberActions/yourFeatureActions.php
  Public API:    admin/include/actions/apiActions/yourFeatureActions.php
  Auth flow:     admin/include/actions/loginActions/yourFeatureActions.php

Two ways to invoke an action:
  1. Plain form POST: <form method="post"> on any view page (no action attr).
     common.php runs the action, sets session flash, view re-renders. No JS needed.
     Preferred for settings forms, file uploads, any full-page action.
  2. AJAX: POST to admin/api/index.php via apiPost() from bs-init.js.
     Returns JSON. Use for inline UI updates (mark read, delete row, live search).

WRONG:
  - Creating new endpoint files in admin/api/
  - Creating custom routing systems
  - Pointing <form action=""> at admin/api/index.php (navigates to raw JSON)

See: admin/include/actions/CLAUDE.md for full action file rules.

## Action file pattern
```php
if ($_POST['action'] == 'addUser') {
    $errs = array();
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }
    if (!$_POST['email'])      { $errs['email'] = 'Email required.'; }
    if (count($errs) <= 0) {
        // do the thing
        $_SESSION['success'] = 'User added.';
        $data['user_id'] = $newId; // optional: return data to JS
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
```

## Template pattern
```php
<?php if ($someCondition) { ?>
<div class="something"><?= h($userData['field']) ?></div>
<?php } ?>
```

## API response format (from admin/api/index.php)
```json
{
  "error":   "",
  "success": "Action completed.",
  "info":    "",
  "warning": "",
  "results": { /* $data array */ }
}
```

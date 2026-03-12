# admin/include/actions — CLAUDE.md

## ONE SET OF ACTION FILES — TWO WAYS TO INVOKE
Action files are auto-included by common.php, common_api.php, and common_auth.php.
They run on EVERY request that loads one of those bootstraps — before any view renders.

### Invocation methods
1. **AJAX / JS**: POST to admin/api/index.php with action=yourActionName
   Returns JSON: { error, success, info, warning, results }
   Use apiPost() helper from bs-init.js for JS callers.

2. **Plain form POST**: `<form method="post">` on any view page (no action attr needed)
   The form POSTs to the current URL (e.g. index.php?page=settings).
   common.php runs the action, sets $_SESSION flash messages,
   then the view re-renders showing the result. No AJAX needed.
   Preferred for forms with file uploads or full-page actions.

### Which to use
- **Plain form POST** — settings forms, file uploads, any form where a page reload is fine
- **AJAX via apiPost()** — inline UI updates (mark read, delete row, load more, live search)

NEVER create new endpoint files in admin/api/ or new routing systems.

### CRITICAL: form action attribute
Forms MUST NOT have action="../api/index.php" — that navigates to raw JSON.
Use <form method="post"> with NO action attribute. The form posts to the
current page URL (index.php?page=whatever). common.php processes the action,
sets session flash, and the view re-renders showing the result.

WRONG:  <form method="post" action="../api/index.php">
RIGHT:  <form method="post">

## Directory structure and when to use each
admin/include/actions/
  memberActions/   — authenticated users only. Always check $_SESSION['user_id'].
  apiActions/      — public API, no login required. Included in both auth + app contexts.
  loginActions/    — authentication flow (login, register, forgot, reset).

## How auto-inclusion works (findfile())
common.php and common_api.php call findfile() which recursively scans:
  admin/include/actions/apiActions/    — all .php files included
  admin/include/actions/memberActions/ — all .php files included
common_auth.php additionally includes:
  admin/include/actions/loginActions/  — all .php files included

Just add your file to the correct directory — no routing config, no manual include.
The action is immediately live on any page that loads common.php.

## Action file template
<?php
/**
 * [Feature] Actions
 * Actions: actionName1, actionName2
 */

if ($_POST['action'] == 'actionName1') {
    $errs = array();

    // 1. Auth check (memberActions only)
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    // 2. Validate inputs
    if (empty($_POST['required_field'])) { $errs['field'] = 'Field required.'; }

    // 3. Process only if no errors
    if (count($errs) <= 0) {
        $s_field = sanitize($_POST['required_field'], SQL);
        // Use sanitize($val, SQL) for all DB values — never raw addslashes()
        db_query("INSERT INTO table_name (col) VALUES ('$s_field')");
        $_SESSION['success'] = 'Done.';
        $data['result'] = $someValue; // optional — returned as JSON to JS callers
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
?>

## Session message variables
$_SESSION['error']   → HTTP 400 (highest priority, shown first)
$_SESSION['success'] → HTTP 200
$_SESSION['info']    → HTTP 200
$_SESSION['warning'] → HTTP 200

## $data array → returned in JSON as "results": { ... }
Set $data['key'] = $value in your action to return data to the frontend.

## API JSON response format
{
  "error":   "",
  "success": "Done.",
  "info":    "",
  "warning": "",
  "results": { "result": "value" }
}

## Rules
- Use $errs array — collect ALL validation errors before setting session message
- Always set $_SESSION['success'] when action completes
- Always catch DB errors: if (!$r) { $_SESSION['error'] = db_error(); }
- Check $_SESSION['user_id'] first in memberActions
- Optional: check role with has_role('admin') for privileged actions
- Never create new endpoint files in admin/api/
- Never set $_SESSION['error'] immediately and return — collect all errors first
- Never overwrite $_SESSION['error'] in a loop (use .= or $errs array)
- Never put public actions in memberActions or authenticated actions in apiActions

## Verification checklist
[ ] File is in correct directory based on auth requirements
[ ] Uses if($_POST['action'] == '...') pattern
[ ] memberActions: validates $_SESSION['user_id'] exists
[ ] Collects errors in $errs array before setting session message
[ ] Sets $_SESSION['success'] on completion
[ ] Handles DB errors via db_error()
[ ] No new endpoint files created

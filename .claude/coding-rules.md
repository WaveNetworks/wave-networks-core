# Active Coding Rules — follow when writing any code in this repo

## DO
- Escape out of PHP for HTML — never echo HTML from within PHP strings
- Use <?= $var ?> shorthand for output, always h() for user-supplied data
- Use sanitize($val, SQL) for any DB value not already sanitized
- Flat if($_POST['action'] == 'x') blocks in action files — no dispatcher
- Collect validation errors in $errs array before setting $_SESSION['error']
- Always set $_SESSION['success'] when an action completes successfully
- New helpers go in admin/include/common/ — glob picks them up automatically
- New actions go in admin/include/actions/[memberActions|apiActions|loginActions]/
- Migration files: decimal versioning (1.0, 1.1, 2.0), no START TRANSACTION/COMMIT (runner handles it)
- Update $db_version or $shard_version in common.php WITH EVERY MIGRATION
- Use IF NOT EXISTS in CREATE TABLE statements (makes migrations rerunnable)
- Asset paths: relative not absolute (../admin/assets/ from child, assets/ from admin)
- __DIR__-based paths for all includes (never relative paths like ../../)

## DO NOT
- Edit vendor/ ever
- Write to core DB tables (user, device, api_key, etc.) from child app code
- Echo HTML strings from PHP — escape out instead
- Use absolute asset paths
- Skip h() or sanitize() for any output
- Add business domain logic (plans, billing, coaching) to this repo
- Create a new $db PDO connection — use the global $db set in common.php
- Store credentials anywhere except admin/config/config.php (gitignored)
- Create new API endpoint files — use action files instead
- Edit existing migration files — always create a new version
- Manually update the db_version table — the migration system handles it
- Set $_SESSION['error'] immediately on first validation failure (collect all errors first)
- Use JavaScript, computed styles, or DOM inspection to confirm visual fixes — trust screenshots as source of truth

## Template pattern
<?php if ($someCondition) { ?>
<div class="something"><?= h($userData['field']) ?></div>
<?php } ?>

## Action file pattern
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

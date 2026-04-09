# wave-networks-core — Development Patterns

## Template pattern

Views are loaded by `app/index.php` via a page map. The template wraps every view.

**app/index.php** maps `?page=` to view files and includes `views/template.php`.
Template provides the full HTML shell (sidebar, topnav, theme, branding).
The view file is included inside the template via `$current_page_file`.

### View file example
```php
<?php
/**
 * views/your_page.php
 * Description of this view.
 */
$page_title = 'Your Page';
?>

<div class="container-fluid px-4">
    <h1 class="mt-3 mb-3"><?= h($page_title) ?></h1>

    <?php include(__DIR__ . '/../snippets/flash_messages.php'); ?>

    <div class="card">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="yourActionName">
                <!-- form fields -->
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>
</div>
```

Key points:
- Set `$page_title` for the `<title>` tag
- Escape out of PHP for HTML — never echo HTML strings
- Use `<?= h($var) ?>` for output, always `h()` for user-supplied data
- Include `flash_messages.php` snippet to show session messages
- Forms POST to current URL (no `action` attribute) with `action` hidden field

### Adding a new page
1. Create `views/your_page.php`
2. Add the route to `$views` array in `app/index.php`
3. Create action file in `include/actions/memberActions/` for form handling
4. Add sidebar link in `views/template.php` if needed

## Action file pattern

Action files run on EVERY request before views render. No routing config needed.
Full rules and checklist in [include/actions/CLAUDE.md](include/actions/CLAUDE.md).

### Action file example
```php
<?php
/**
 * yourFeatureActions.php
 * Actions: saveYourFeature, deleteYourFeature
 */

if ($_POST['action'] == 'saveYourFeature') {
    $errs = array();

    // 1. Auth check (memberActions only)
    if (!$_SESSION['user_id']) { $errs['auth'] = 'Login required.'; }

    // 2. Validate inputs — collect ALL errors
    if (empty($_POST['name'])) { $errs['name'] = 'Name is required.'; }
    if (empty($_POST['email']) || !valid_email($_POST['email'])) {
        $errs['email'] = 'Valid email is required.';
    }

    // 3. Process only if no errors
    if (count($errs) <= 0) {
        $s_name  = sanitize($_POST['name'], SQL);
        $s_email = sanitize($_POST['email'], SQL);
        $r = db_query("INSERT INTO your_table (name, email) VALUES ('$s_name', '$s_email')");
        if (!$r) {
            $_SESSION['error'] = db_error();
        } else {
            $_SESSION['success'] = 'Saved successfully.';
            $data['result'] = db_insert_id(); // returned as JSON to AJAX callers
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errs);
    }
}
```

### Two invocation methods
1. **Plain form POST** — form posts to current URL, common.php runs action, sets
   session flash, view re-renders. Preferred for settings forms and file uploads.
2. **AJAX via apiPost()** — POST to `admin/api/index.php` with `action=name`.
   Returns JSON `{ error, success, info, warning, results }`. Use for inline updates.

### Directories
- `include/actions/memberActions/` — authenticated users (check `$_SESSION['user_id']`)
- `include/actions/apiActions/` — public API, no login required
- `include/actions/loginActions/` — auth flow (login, register, forgot, reset)

## API response format

All API responses from `admin/api/index.php` return this JSON structure:
```json
{
  "error":   "",
  "success": "Done.",
  "info":    "",
  "warning": "",
  "results": { "key": "value" }
}
```

Session message variables and their HTTP status codes:
- `$_SESSION['error']` — HTTP 400 (highest priority)
- `$_SESSION['success']` — HTTP 200
- `$_SESSION['info']` — HTTP 200
- `$_SESSION['warning']` — HTTP 200

Set `$data['key'] = $value` in your action to populate the `results` object.

## Migration file template

Full migration rules and checklist in [db_migrations/CLAUDE.md](db_migrations/CLAUDE.md).

### Template
```sql
-- Migration X.X for [Main/Shard] Database
-- [Brief description of changes]
-- ⚠️ REMINDER: Update admin/include/common.php $db_version = X.X;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Your SQL here
ALTER TABLE tablename ADD COLUMN col datatype;
```

### Two-step process (critical)
1. Create SQL file in `db_migrations/main/{version}.sql` or `shard/{version}.sql`
2. Update `$db_version` or `$shard_version` in `admin/include/common.php`

**If you skip step 2, the migration will never run.**

### Version numbering
- Decimal format: 1.0, 1.1, 1.2, 2.0
- Increment by 0.1 for minor changes, bump integer for breaking changes
- Main DB and shard DB versions are independent
- Do NOT wrap in START TRANSACTION / COMMIT (runner handles transactions)
- Use IF NOT EXISTS for CREATE TABLE (idempotent on retry)

## Shared assets

### Asset path rules
- From admin views/auth: `assets/css/style.css`, `assets/js/app.js`
- From child app: `../admin/assets/css/style.css` (relative, never absolute)
- All paths relative — no leading slash

### Key asset files
| File | Purpose |
|------|---------|
| `assets/css/style.css` | Main stylesheet (sidebar, layout, components) |
| `assets/css/bs-theme-overrides.css` | Bootstrap dark/light mode patches |
| `assets/js/bs-init.js` | Theme switcher, color mode, sidebar toggle, apiPost() helper |
| `assets/bootstrap/` | Local Bootstrap CSS (sandstone default theme) |
| `assets/scss/` | SCSS sources (compiled to CSS) |

### Detailed references
- [db_migrations/CLAUDE.md](db_migrations/CLAUDE.md) — full migration rules, version numbering, verification checklist
- [include/actions/CLAUDE.md](include/actions/CLAUDE.md) — full action file rules, auto-inclusion, session messages, checklist

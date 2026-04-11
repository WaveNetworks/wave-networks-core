# wave-networks-core — CLAUDE.md

Standalone auth and user admin for Wave Networks. Knows nothing about plans,
billing, or business domain. Child apps are separate repos deployed as siblings
in the same webroot, reaching this repo via ../admin/include/common.php.

## Topic imports (auto-loaded)

@.claude/architecture.md
@.claude/actions-and-migrations.md
@.claude/coding-rules.md
@.claude/email-notifications.md
@.claude/child-apps.md
@.claude/error-logging.md
@.claude/api-and-mcp.md
@.claude/themes-and-branding.md
@.claude/gdpr.md
@.claude/versioning.md

## Top-level rules (must be seen first)

- Action files live in admin/include/actions/ (memberActions, apiActions, loginActions).
  Never create new endpoint files in admin/api/. See actions-and-migrations.md.
- EVERY DB migration requires TWO steps: the SQL file AND bumping $db_version or
  $shard_version in admin/include/common.php. The version bump is frequently
  forgotten — do not skip it. See actions-and-migrations.md.
- This repo has NO business domain logic (no plans, billing, coaching).
  Add those to child apps, not here.
- Never write to core DB tables (user, device, api_key, etc.) from child app code.
- Always h() user-supplied output and sanitize() DB values. Escape out of PHP
  for HTML — never echo HTML from within PHP strings. See coding-rules.md.

## Specialized sub-CLAUDE.md files
db_migrations/CLAUDE.md            — migration version rules (read before any DB work)
admin/include/actions/CLAUDE.md    — action file patterns (read before any API work)

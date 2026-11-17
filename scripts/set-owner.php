<?php
/**
 * set-owner.php — the ONLY way to set user.is_owner / user.is_agent (task #2779).
 *
 * Run over SSH on the app's host. Deliberately not an admin-UI toggle (an admin could
 * give it to themselves) and not an API action (a key could mint an owner).
 *
 *   php admin/scripts/set-owner.php --list
 *   php admin/scripts/set-owner.php <email>              make this person the owner (and admin)
 *   php admin/scripts/set-owner.php --revoke <email>     take owner away
 *   php admin/scripts/set-owner.php --agent <email>      mark an automation/agent account: never
 *                                                        owner, never passes a human approval gate
 *   php admin/scripts/set-owner.php --not-agent <email>  undo --agent
 *   add --dry-run to any of them to see what would change
 *
 * Refuses to make an agent account or the test account the owner.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$args    = array_slice($argv, 1);
$dry_run = in_array('--dry-run', $args, true);
$args    = array_values(array_diff($args, ['--dry-run']));
$mode    = 'owner';
foreach (['--list' => 'list', '--revoke' => 'revoke', '--agent' => 'agent', '--not-agent' => 'not-agent'] as $flag => $m) {
    if (in_array($flag, $args, true)) { $mode = $m; $args = array_values(array_diff($args, [$flag])); }
}
$email = trim((string) ($args[0] ?? ''));
if ($mode !== 'list' && $email === '') {
    fwrite(STDERR, "Usage: php set-owner.php [--list | --revoke | --agent | --not-agent] <email> [--dry-run]\n");
    exit(1);
}

require __DIR__ . '/../include/common_readonly.php';
global $db;

$has_agent_col = (bool) db_fetch(db_query("SHOW COLUMNS FROM user LIKE 'is_agent'"));
$agent_sel     = $has_agent_col ? 'is_agent' : '0 AS is_agent';

if ($mode === 'list') {
    $rows = db_fetch_all(db_query(
        "SELECT user_id, email, is_admin, is_owner, $agent_sel, is_test_account FROM user
          WHERE is_owner = 1 OR is_admin = 1" . ($has_agent_col ? " OR is_agent = 1" : "") . " ORDER BY user_id"
    ));
    foreach ($rows as $r) {
        $tags = [];
        foreach (['is_owner' => 'owner', 'is_admin' => 'admin', 'is_agent' => 'agent', 'is_test_account' => 'test'] as $c => $t) {
            if (!empty($r[$c])) { $tags[] = $t; }
        }
        echo "{$r['user_id']}\t{$r['email']}\t" . implode(',', $tags) . "\n";
    }
    exit(0);
}

$st = $db->prepare("SELECT user_id, email, is_admin, is_owner, $agent_sel, is_test_account FROM user WHERE email = ?");
$st->execute([$email]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    fwrite(STDERR, "No user with email $email\n");
    exit(1);
}
$uid = (int) $u['user_id'];

if (($mode === 'agent' || $mode === 'not-agent') && !$has_agent_col) {
    fwrite(STDERR, "user.is_agent does not exist yet (core main/7.2): load any admin page once to migrate, then re-run.\n");
    exit(1);
}

switch ($mode) {
    case 'owner':
        if (!empty($u['is_agent']))        { fwrite(STDERR, "Refused: $email is an agent account; an agent is never the owner.\n"); exit(1); }
        if (!empty($u['is_test_account'])) { fwrite(STDERR, "Refused: $email is the test account.\n"); exit(1); }
        $sql = "UPDATE user SET is_owner = 1, is_admin = 1 WHERE user_id = ?";
        $msg = "owner set: user_id=$uid $email";
        break;
    case 'revoke':
        $sql = "UPDATE user SET is_owner = 0 WHERE user_id = ?";
        $msg = "owner revoked: user_id=$uid $email";
        break;
    case 'agent':
        $sql = "UPDATE user SET is_agent = 1, is_owner = 0 WHERE user_id = ?";
        $msg = "marked agent (owner cleared): user_id=$uid $email";
        break;
    case 'not-agent':
        $sql = "UPDATE user SET is_agent = 0 WHERE user_id = ?";
        $msg = "agent mark removed: user_id=$uid $email";
        break;
}

if ($dry_run) {
    echo "[dry-run] would run: $sql  [$uid]\n";
    exit(0);
}
$db->prepare($sql)->execute([$uid]);
echo "$msg\n";
echo "The person must sign out and in again for their session to pick it up.\n";
exit(0);

<?php
/**
 * schemaAuditApiActions.php
 * Action: apiSchemaAudit (scope: monitoring:read) — READ-ONLY.
 *
 * Compares every database this deployment owns (core main + shards, each sibling
 * child app's main + shards) against the schema its migration files say it should
 * have at its ledger version. See include/common/schemaAuditFunctions.php.
 *
 * Params (optional):
 *   only         — substring filter on the database label, e.g. "contactsweep/shard"
 *   include_info — 0 to omit informational findings (extra tables/columns, wider types)
 *
 * A database whose migration loop stopped at a failed file carries
 * migration_failure {migration, version, file, code, error, at} (cleared once it
 * reaches its target); summary.migration_failures counts them.
 *
 * Never returns hosts, users or passwords; a connection failure reports only its code.
 */

if (($action ?? null) == 'apiSchemaAudit') {
    if (require_api_scope('monitoring:read')) {
        $only         = trim((string)($_POST['only'] ?? $_GET['only'] ?? ''));
        $include_info = (string)($_POST['include_info'] ?? $_GET['include_info'] ?? '1') !== '0';
        $data['audit'] = schema_audit_run($only, $include_info);
        $s = $data['audit']['summary'];
        $_SESSION['success'] = "Audited {$s['databases']} database(s): {$s['drift']} with drift, "
            . "{$s['uncertain_only']} uncertain, {$s['errors']} unreadable, "
            . ($s['migration_failures'] ?? 0) . " with a failed migration.";
    }
}

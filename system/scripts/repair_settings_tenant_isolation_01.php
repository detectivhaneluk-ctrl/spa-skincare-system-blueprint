<?php

declare(strict_types=1);

/**
 * SETTINGS-TENANT-ISOLATION-01 repair/backfill for existing DBs.
 *
 * Usage: php scripts/repair_settings_tenant_isolation_01.php
 */

require dirname(__DIR__) . '/bootstrap.php';

$db = app(\Core\App\Database::class);

$hasOrgColumn = $db->fetchOne(
    'SELECT 1 AS ok
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
     LIMIT 1',
    ['settings', 'organization_id']
) !== null;

if (!$hasOrgColumn) {
    fwrite(STDERR, "settings.organization_id is missing. Run migrations first.\n");
    exit(1);
}

$db->query(
    'UPDATE settings s
     INNER JOIN branches b ON b.id = s.branch_id
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     SET s.organization_id = b.organization_id
     WHERE s.branch_id <> 0'
);

$db->query('UPDATE settings SET organization_id = 0 WHERE branch_id = 0');

$crossTenant = $db->fetchAll(
    'SELECT s.id, s.`key`, s.organization_id, s.branch_id, b.organization_id AS branch_organization_id
     FROM settings s
     INNER JOIN branches b ON b.id = s.branch_id
     WHERE s.branch_id <> 0 AND s.organization_id <> b.organization_id
     ORDER BY s.id ASC
     LIMIT 50'
);

if (!empty($crossTenant)) {
    fwrite(STDERR, "cross-tenant settings scope mismatch remains:\n");
    foreach ($crossTenant as $row) {
        fwrite(STDERR, sprintf(
            "  id=%d key=%s organization_id=%d branch_id=%d branch_organization_id=%d\n",
            (int) $row['id'],
            (string) $row['key'],
            (int) $row['organization_id'],
            (int) $row['branch_id'],
            (int) $row['branch_organization_id']
        ));
    }
    exit(1);
}

$totals = $db->fetchOne(
    'SELECT
        SUM(CASE WHEN branch_id = 0 AND organization_id = 0 THEN 1 ELSE 0 END) AS platform_defaults,
        SUM(CASE WHEN branch_id = 0 AND organization_id > 0 THEN 1 ELSE 0 END) AS org_defaults,
        SUM(CASE WHEN branch_id > 0 AND organization_id > 0 THEN 1 ELSE 0 END) AS branch_overrides
     FROM settings'
);

echo "SETTINGS-TENANT-ISOLATION-01 repair complete.\n";
echo "- platform defaults: " . (int) ($totals['platform_defaults'] ?? 0) . "\n";
echo "- organization defaults: " . (int) ($totals['org_defaults'] ?? 0) . "\n";
echo "- branch overrides: " . (int) ($totals['branch_overrides'] ?? 0) . "\n";
echo "- verification OK: no cross-tenant branch override mismatches.\n";

<?php

declare(strict_types=1);

/**
 * FOUNDATION-100 RBAC repair for existing installs.
 *
 * Idempotent corrective script:
 * - ensures platform_founder role exists
 * - grants platform.* permissions only to platform_founder
 * - removes platform.* permissions from all other roles (including legacy owner)
 *
 * Usage: php scripts/repair_control_plane_rbac_foundation_100.php
 */

require dirname(__DIR__) . '/bootstrap.php';

$db = app(\Core\App\Database::class);

$platformCodes = ['platform.organizations.view', 'platform.organizations.manage'];

$db->query('INSERT IGNORE INTO roles (code, name) VALUES (?, ?)', ['platform_founder', 'Platform founder']);
$platformFounderId = $db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL', ['platform_founder'])['id'] ?? null;

if ($platformFounderId === null) {
    fwrite(STDERR, "FOUNDATION-100 repair failed: missing platform_founder role.\n");
    exit(1);
}

$grantsAdded = 0;
$grantsRemoved = 0;

foreach ($platformCodes as $code) {
    $perm = $db->fetchOne('SELECT id FROM permissions WHERE code = ?', [$code]);
    if (!$perm) {
        fwrite(STDERR, "FOUNDATION-100 repair failed: missing permission '{$code}'. Run php scripts/seed.php first.\n");
        exit(1);
    }
    $permissionId = (int) $perm['id'];

    $db->query(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
        [(int) $platformFounderId, $permissionId]
    );
    $grantsAdded++;

    $db->query(
        'DELETE FROM role_permissions WHERE permission_id = ? AND role_id <> ?',
        [$permissionId, (int) $platformFounderId]
    );
    $grantsRemoved++;
}

$leaks = $db->fetchAll(
    'SELECT r.code AS role_code, p.code AS permission_code
     FROM role_permissions rp
     INNER JOIN roles r ON r.id = rp.role_id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE p.code LIKE ? AND r.code <> ?
     ORDER BY r.code ASC, p.code ASC',
    ['platform.%', 'platform_founder']
);

echo "FOUNDATION-100 repair complete.\n";
echo "- platform founder ensured: role_id={$platformFounderId}\n";
echo "- platform grants upserted: {$grantsAdded}\n";
echo "- platform grants removed from non-platform roles: {$grantsRemoved} permission sweep(s)\n";
if (!empty($leaks)) {
    fwrite(STDERR, "- verification FAILED: platform permission leaks remain on non-platform roles.\n");
    foreach ($leaks as $leak) {
        fwrite(STDERR, "  * role={$leak['role_code']} permission={$leak['permission_code']}\n");
    }
    exit(1);
}
echo "- verification OK: no platform permission leaks on non-platform roles.\n";

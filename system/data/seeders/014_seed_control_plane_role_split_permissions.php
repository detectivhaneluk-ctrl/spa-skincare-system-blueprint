<?php

declare(strict_types=1);

/**
 * FOUNDATION-98/100: platform-only founder role; tenant roles stay tenant-only.
 * Idempotent and corrective: grants desired permissions and removes platform.* from tenant roles.
 *
 * Run after 001_seed_roles_permissions.php (roles + permission rows exist).
 */

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$db->query('INSERT IGNORE INTO roles (code, name) VALUES (?, ?)', ['platform_founder', 'Platform founder']);

$platformFounderId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['platform_founder'])['id'] ?? null;
$adminId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['admin'])['id'] ?? null;
$receptionId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['reception'])['id'] ?? null;

$platformCodes = ['platform.organizations.view', 'platform.organizations.manage'];
if ($platformFounderId) {
    foreach ($platformCodes as $code) {
        $perm = $db->fetchOne('SELECT id FROM permissions WHERE code = ?', [$code]);
        if ($perm) {
            $db->query(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                [$platformFounderId, $perm['id']]
            );
        }
    }
}

// FOUNDATION-100 repair: remove platform.* from every non-platform role (legacy + future tenant roles).
foreach ($platformCodes as $code) {
    $db->query(
        'DELETE rp
         FROM role_permissions rp
         INNER JOIN permissions p ON p.id = rp.permission_id
         INNER JOIN roles r ON r.id = rp.role_id
         WHERE p.code = ? AND r.code <> ?',
        [$code, 'platform_founder']
    );
}

if ($adminId) {
    $tenantRows = $db->fetchAll(
        'SELECT id FROM permissions WHERE code NOT IN (?, ?)',
        $platformCodes
    );
    foreach ($tenantRows as $row) {
        $db->query(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
            [$adminId, $row['id']]
        );
    }
}

$receptionCodes = [
    'appointments.view',
    'appointments.create',
    'appointments.edit',
    'clients.view',
    'clients.create',
    'clients.edit',
    'staff.view',
    'services-resources.view',
    'notifications.view',
    'intake.view',
];

if ($receptionId) {
    foreach ($receptionCodes as $code) {
        $perm = $db->fetchOne('SELECT id FROM permissions WHERE code = ?', [$code]);
        if ($perm) {
            $db->query(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                [$receptionId, $perm['id']]
            );
        }
    }
}

echo "Control plane role split permissions seeded/repaired (FOUNDATION-98/100).\n";

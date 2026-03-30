<?php

declare(strict_types=1);

/**
 * Ensures marketing and payroll permissions exist and are assigned to owner and admin.
 * Mirrors migration 077 for environments that rely on seed.php instead of (or after) SQL migrations.
 * Idempotent: safe to run multiple times.
 */

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$codes = [
    ['marketing.view', 'View marketing campaigns and run history'],
    ['marketing.manage', 'Create and run marketing campaigns'],
    ['payroll.view', 'View payroll runs and own commission lines'],
    ['payroll.manage', 'Manage compensation rules and payroll runs'],
];

foreach ($codes as [$code, $name]) {
    $db->query('INSERT IGNORE INTO permissions (code, name) VALUES (?, ?)', [$code, $name]);
}

$ownerId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['owner'])['id'] ?? null;
$adminId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['admin'])['id'] ?? null;

foreach (array_filter([$ownerId, $adminId]) as $roleId) {
    foreach ($codes as [$code]) {
        $perm = $db->fetchOne('SELECT id FROM permissions WHERE code = ?', [$code]);
        if ($perm) {
            $db->query(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                [$roleId, $perm['id']]
            );
        }
    }
}

echo "Marketing and payroll permissions synced (owner and admin).\n";

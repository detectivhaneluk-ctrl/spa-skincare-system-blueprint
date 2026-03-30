<?php

declare(strict_types=1);

/**
 * Ensures settings-related permissions exist and are assigned to owner and admin.
 * Fixes 403 on /settings/vat-rates and /settings/payment-methods when seed was run
 * before these permissions were added, or when user has admin role.
 * Includes branches.view / branches.manage so GET /branches matches other settings-zone modules
 * for admin (same class of fix as payment_methods / vat_rates).
 * Idempotent: safe to run multiple times.
 *
 * Also assigns reports.view to admin so /reports/* is not owner-only by default seed path.
 */

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$settingsPermissions = [
    ['payment_methods.view', 'View payment methods'],
    ['payment_methods.manage', 'Manage payment methods (create, edit)'],
    ['price_modification_reasons.view', 'View price modification reasons catalog'],
    ['price_modification_reasons.manage', 'Manage price modification reasons catalog'],
    ['vat_rates.view', 'View VAT types'],
    ['vat_rates.manage', 'Manage VAT types (create, edit)'],
    ['memberships.view', 'View memberships'],
    ['memberships.manage', 'Manage membership definitions and assign to clients'],
    ['reports.view', 'View reports'],
    ['branches.view', 'View branches'],
    ['branches.manage', 'Create, edit, and deactivate branches'],
];

foreach ($settingsPermissions as [$code, $name]) {
    $db->query('INSERT IGNORE INTO permissions (code, name) VALUES (?, ?)', [$code, $name]);
}

$ownerId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['owner'])['id'] ?? null;
$adminId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['admin'])['id'] ?? null;

foreach (array_filter([$ownerId, $adminId]) as $roleId) {
    foreach ($settingsPermissions as [$code]) {
        $perm = $db->fetchOne('SELECT id FROM permissions WHERE code = ?', [$code]);
        if ($perm) {
            $db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $perm['id']]);
        }
    }
}

echo "Settings and branches permissions synced (owner and admin).\n";

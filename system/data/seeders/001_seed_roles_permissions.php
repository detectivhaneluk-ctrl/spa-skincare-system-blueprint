<?php

declare(strict_types=1);

/**
 * Seeds default roles and permissions.
 *
 * Can run standalone (with bootstrap) or be included from another script
 * that already prepared $db.
 */

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$permissions = [
    ['settings.view', 'View settings'],
    ['settings.edit', 'Edit settings'],
    ['clients.view', 'View clients'],
    ['clients.create', 'Create clients'],
    ['clients.edit', 'Edit clients'],
    ['clients.delete', 'Delete clients'],
    ['staff.view', 'View staff'],
    ['staff.create', 'Create staff'],
    ['staff.edit', 'Edit staff'],
    ['staff.delete', 'Delete staff'],
    ['services-resources.view', 'View services and resources'],
    ['services-resources.create', 'Create services and resources'],
    ['services-resources.edit', 'Edit services and resources'],
    ['services-resources.delete', 'Delete services and resources'],
    ['appointments.view', 'View appointments'],
    ['appointments.create', 'Create appointments'],
    ['appointments.edit', 'Edit appointments'],
    ['appointments.delete', 'Delete appointments'],
    ['sales.view', 'View sales'],
    ['sales.create', 'Create sales'],
    ['sales.edit', 'Edit sales'],
    ['sales.delete', 'Delete sales'],
    ['sales.pay', 'Record payments'],
    ['inventory.view', 'View inventory'],
    ['inventory.create', 'Create inventory records'],
    ['inventory.edit', 'Edit inventory records'],
    ['inventory.delete', 'Delete inventory records'],
    ['inventory.adjust', 'Adjust inventory stock'],
    ['gift_cards.view', 'View gift cards'],
    ['gift_cards.create', 'Create gift cards'],
    ['gift_cards.issue', 'Issue gift cards'],
    ['gift_cards.redeem', 'Redeem gift cards'],
    ['gift_cards.adjust', 'Adjust gift cards'],
    ['gift_cards.cancel', 'Cancel gift cards'],
    ['packages.view', 'View packages'],
    ['packages.create', 'Create package definitions'],
    ['packages.edit', 'Edit package definitions'],
    ['packages.assign', 'Assign packages to clients'],
    ['packages.use', 'Use package sessions'],
    ['packages.adjust', 'Adjust package sessions'],
    ['packages.reverse', 'Reverse package usages'],
    ['packages.cancel', 'Cancel client packages'],
    ['reports.view', 'View reports'],
    ['documents.view', 'View documents and consents'],
    ['documents.edit', 'Create definitions and sign consents'],
    ['appointments.cancel_override', 'Override cancellation policy (cancel inside min notice)'],
    ['notifications.view', 'View internal notifications'],
    ['payment_methods.view', 'View payment methods'],
    ['payment_methods.manage', 'Manage payment methods (create, edit)'],
    ['price_modification_reasons.view', 'View price modification reasons catalog'],
    ['price_modification_reasons.manage', 'Manage price modification reasons catalog'],
    ['vat_rates.view', 'View VAT types'],
    ['vat_rates.manage', 'Manage VAT types (create, edit)'],
    ['memberships.view', 'View memberships'],
    ['memberships.manage', 'Manage membership definitions and assign to clients'],
    ['intake.view', 'View intake forms and submissions'],
    ['intake.edit', 'Create and edit intake form templates'],
    ['intake.assign', 'Assign intake forms to clients and appointments'],
    ['marketing.view', 'View marketing campaigns and run history'],
    ['marketing.manage', 'Create and run marketing campaigns'],
    ['payroll.view', 'View payroll runs and own commission lines'],
    ['payroll.manage', 'Manage compensation rules and payroll runs'],
    ['branches.view', 'View branches'],
    ['branches.manage', 'Create, edit, and deactivate branches'],
    ['platform.organizations.view', 'View all organizations (platform operator)'],
    ['platform.organizations.manage', 'Create, suspend, and manage organizations across tenants (platform operator)'],
    ['organizations.profile.manage', 'Edit current organization profile (name/code) within resolved organization context'],
];

foreach ($permissions as [$code, $name]) {
    $db->query('INSERT IGNORE INTO permissions (code, name) VALUES (?, ?)', [$code, $name]);
}

$roles = [
    ['owner', 'Owner'],
    ['admin', 'Admin'],
    ['reception', 'Reception'],
];

foreach ($roles as [$code, $name]) {
    $db->query('INSERT IGNORE INTO roles (code, name) VALUES (?, ?)', [$code, $name]);
}

$ownerId = $db->fetchOne('SELECT id FROM roles WHERE code = ?', ['owner'])['id'] ?? null;
if ($ownerId) {
    // FOUNDATION-100: owner remains tenant-super role; platform.* belongs only to explicit platform roles.
    $permIds = $db->fetchAll('SELECT id FROM permissions WHERE code NOT LIKE ?', ['platform.%']);
    foreach ($permIds as $p) {
        $db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$ownerId, $p['id']]);
    }
}

echo "Roles and permissions seeded.\n";

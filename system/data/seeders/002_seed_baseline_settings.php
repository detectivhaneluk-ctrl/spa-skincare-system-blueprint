<?php

declare(strict_types=1);

/**
 * Seeds baseline settings required by implemented modules.
 *
 * Can run standalone (with bootstrap) or be included from another script
 * that already prepared $db.
 */

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$defaults = [
    ['currency_code', 'USD', 'string', 'billing', 0],
    ['company_name', 'SPA & Skincare', 'string', 'general', 0],
    ['timezone', 'UTC', 'string', 'general', 0],
    ['establishment.name', 'SPA & Skincare', 'string', 'establishment', 0],
    ['establishment.phone', '', 'string', 'establishment', 0],
    ['establishment.email', '', 'string', 'establishment', 0],
    ['establishment.address', '', 'string', 'establishment', 0],
    ['establishment.currency', 'USD', 'string', 'establishment', 0],
    ['establishment.timezone', 'UTC', 'string', 'establishment', 0],
    ['establishment.language', 'en', 'string', 'establishment', 0],
];

foreach ($defaults as [$key, $value, $type, $group, $branchId]) {
    $db->query(
        'INSERT IGNORE INTO settings (`key`, `value`, type, setting_group, branch_id) VALUES (?, ?, ?, ?, ?)',
        [$key, $value, $type, $group, $branchId]
    );
}

echo "Baseline settings seeded.\n";

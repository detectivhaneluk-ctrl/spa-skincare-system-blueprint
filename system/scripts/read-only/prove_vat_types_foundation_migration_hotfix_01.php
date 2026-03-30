<?php

declare(strict_types=1);

/**
 * VAT-TYPES-FOUNDATION-MIGRATION-HOTFIX-01 schema proof.
 *
 * Usage:
 *   php system/scripts/read-only/prove_vat_types_foundation_migration_hotfix_01.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();

$m = $pdo->prepare('SELECT migration FROM migrations WHERE migration = ? LIMIT 1');
$m->execute(['098_add_vat_rates_settings_foundation_fields.sql']);
$mRow = $m->fetch(\PDO::FETCH_ASSOC) ?: null;
echo 'migration_recorded=' . ($mRow ? 'yes' : 'no') . PHP_EOL;

$c = $pdo->prepare(
    "SELECT COLUMN_NAME
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND COLUMN_NAME IN (?, ?, ?)
     ORDER BY COLUMN_NAME ASC"
);
$c->execute(['vat_rates', 'applies_to_json', 'is_flexible', 'price_includes_tax']);
$cols = $c->fetchAll(\PDO::FETCH_COLUMN) ?: [];

echo 'columns_found=' . implode(',', $cols) . PHP_EOL;
echo 'all_present=' . (count($cols) === 3 ? 'yes' : 'no') . PHP_EOL;

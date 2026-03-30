<?php

declare(strict_types=1);

/**
 * PAYMENT-METHODS-TYPE-LABEL-MIGRATION-HOTFIX-01 proof script (requires DB).
 *
 * Usage:
 *   php system/scripts/read-only/prove_payment_methods_type_label_hotfix_01.php
 */

$base = dirname(__DIR__, 2);

require $base . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();

$m = $pdo->prepare('SELECT id, migration, run_at FROM migrations WHERE migration = ? LIMIT 1');
$m->execute(['097_add_type_label_to_payment_methods.sql']);
$mRow = $m->fetch(\PDO::FETCH_ASSOC) ?: null;

echo 'migrations_row=' . ($mRow ? 'yes' : 'no') . PHP_EOL;
if ($mRow) {
    echo 'migration=' . (string) ($mRow['migration'] ?? '') . PHP_EOL;
    echo 'run_at=' . (string) ($mRow['run_at'] ?? '') . PHP_EOL;
}

$c = $pdo->prepare(
    "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
);
$c->execute(['payment_methods', 'type_label']);
$col = $c->fetch(\PDO::FETCH_ASSOC) ?: null;

echo 'type_label_column=' . ($col ? 'yes' : 'no') . PHP_EOL;
if ($col) {
    echo 'data_type=' . (string) ($col['DATA_TYPE'] ?? '') . PHP_EOL;
    echo 'max_length=' . (string) ($col['CHARACTER_MAXIMUM_LENGTH'] ?? '') . PHP_EOL;
    echo 'nullable=' . (string) ($col['IS_NULLABLE'] ?? '') . PHP_EOL;
}


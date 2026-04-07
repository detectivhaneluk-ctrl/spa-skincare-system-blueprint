<?php

declare(strict_types=1);

/**
 * Applies migration 084 SQL idempotently (INSERT IGNORE), regardless of migrations table.
 * Use when the DB is missing branches.* permission rows or role_permissions links
 * but incremental migrate was skipped or drifted.
 *
 * Usage (from system/):
 *   php scripts/dev-only/apply_branch_permission_backfill.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/sql_statement_split.php';

use Core\App\Database;

$pdo = app(Database::class)->connection();
$file = dirname(__DIR__, 2) . '/data/migrations/084_branch_permissions_settings_zone_backfill.sql';
if (!is_file($file)) {
    fwrite(STDERR, "Missing file: {$file}\n");
    exit(1);
}

$sql = (string) file_get_contents($file);
$statements = spa_split_sql_statements($sql);
foreach ($statements as $stmt) {
    $pdo->exec($stmt . ';');
}

echo "Applied: " . basename($file) . " (" . count($statements) . " statements).\n";
echo "Done. Re-run: php scripts/dev-only/inspect_branch_permissions.php\n";

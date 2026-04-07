<?php

declare(strict_types=1);

/**
 * Safe local reset workflow:
 * - Drops all tables in current DB
 * - Applies canonical schema snapshot
 * - Stamps migrations as applied
 * - Runs baseline seeders (001, 002) and settings-zone permission sync (012) so `admin` matches payment/VAT/branches parity
 *
 * Usage (from `system/`):
 *   php scripts/dev-only/reset_dev.php --yes
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';

$args = array_slice($argv, 1);
$confirmed = in_array('--yes', $args, true);
$force = in_array('--force', $args, true);

$env = (string) config('app.env', 'production');
if (!$confirmed) {
    echo "This command is destructive for the current database.\n";
    echo "Run again with --yes to continue.\n";
    exit(1);
}

if (!$force && in_array(strtolower($env), ['production', 'prod'], true)) {
    echo "Refusing reset in production environment. Use --force only if you are absolutely sure.\n";
    exit(1);
}

$pdo = app(\Core\App\Database::class)->connection();
$db = app(\Core\App\Database::class);
$schemaPath = $systemPath . '/data/full_project_schema.sql';
$migrationsPath = $systemPath . '/data/migrations';

if (!is_file($schemaPath)) {
    throw new RuntimeException('Canonical schema not found: ' . $schemaPath);
}

require_once dirname(__DIR__) . '/sql_statement_split.php';

function parseSqlStatementsForReset(string $sql): array
{
    return spa_split_sql_statements($sql);
}

echo "Resetting database in env={$env} ...\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) ?: [];
foreach ($tables as $row) {
    $table = (string) $row[0];
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "Dropped " . count($tables) . " tables.\n";

$schemaSql = (string) file_get_contents($schemaPath);
$statements = parseSqlStatementsForReset($schemaSql);
foreach ($statements as $stmt) {
    $pdo->exec($stmt . ';');
}
echo "Canonical schema applied.\n";

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_migrations_migration (migration)
)');
$files = glob($migrationsPath . '/*.sql') ?: [];
sort($files);
$insert = $pdo->prepare('INSERT IGNORE INTO migrations (migration) VALUES (?)');
foreach ($files as $file) {
    $insert->execute([basename($file)]);
}
echo "Migrations stamped: " . count($files) . ".\n";

require $systemPath . '/data/seeders/001_seed_roles_permissions.php';
require $systemPath . '/data/seeders/002_seed_baseline_settings.php';
require $systemPath . '/data/seeders/012_seed_sync_settings_permissions.php';

echo "Reset complete.\n";

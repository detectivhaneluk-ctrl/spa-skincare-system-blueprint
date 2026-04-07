<?php

declare(strict_types=1);

/**
 * Run migrations.
 *
 * FND-MIG-02 — **Migration executed** (DDL ran) is not the same as **baseline verified / deploy-safe**:
 * without `--verify-baseline`, this script may exit 0 while the `migrations` table has orphan stamps (rows with no
 * matching file on disk) — only a STDERR warning. **Deploy / release:** use `--verify-baseline` or the canonical
 * wrapper `system/scripts/run_migration_baseline_deploy_gate_01.php` (see `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md`).
 *
 * Usage (from the `system/` directory, same as bootstrap):
 * - php scripts/migrate.php                      # incremental (legacy-tolerant); orphan stamps → warning only
 * - php scripts/migrate.php --strict             # incremental strict mode (fails on duplicate column / already applied)
 * - php scripts/migrate.php --verify-baseline    # after incremental run, exit non-zero if disk/DB baseline misaligned
 * - php scripts/migrate.php --strict --verify-baseline   # recommended production-style apply + deploy gate
 * - php scripts/migrate.php --canonical          # apply canonical schema snapshot to an empty DB and stamp migrations
 *
 * Production drift: if the app errors with unknown column membership_definitions.billing_enabled,
 * the schema is behind canonical (see migrations 067 and 070). Run incremental migrate.php once;
 * 070_membership_definitions_billing_columns_align.sql adds any missing billing columns one at a
 * time; environments that already have them log a tolerated duplicate-column message and continue.
 *
 * Read-only preflight: `php scripts/verify_memberships_schema.php` (exit 1 if membership schema
 * does not match code expectations; see DB-CANONICAL-PLAN.md).
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/migrate_end_state_verify.php';
require_once __DIR__ . '/sql_statement_split.php';

use Core\App\MigrationBaseline;

$pdo = app(\Core\App\Database::class)->connection();
$basePath = dirname(__DIR__);
$migrationsPath = $basePath . '/data/migrations';
$canonicalPath = $basePath . '/data/full_project_schema.sql';

$args = array_slice($argv, 1);
$strict = in_array('--strict', $args, true);
$canonical = in_array('--canonical', $args, true);
$verifyBaseline = in_array('--verify-baseline', $args, true);

function createMigrationsTable(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL,
        run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_migrations_migration (migration)
    )');
}

function parseSqlStatements(string $sql): array
{
    return spa_split_sql_statements($sql);
}

function isLegacyAlreadyAppliedError(Throwable $e): bool
{
    $m = strtolower($e->getMessage());
    return str_contains($m, 'already exists')
        || str_contains($m, 'duplicate column name')
        || str_contains($m, 'duplicate key name')
        || str_contains($m, 'duplicate entry')
        || str_contains($m, 'unknown column')
        || str_contains($m, "can't drop")
        || str_contains($m, 'check that column/key exists')
        || str_contains($m, 'duplicate foreign key constraint name');
}

function listAllTables(PDO $pdo): array
{
    $rows = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
    return array_values(array_map(static fn (array $r): string => (string) $r[0], $rows ?: []));
}

if ($canonical) {
    if (!is_file($canonicalPath)) {
        throw new RuntimeException('Canonical schema file not found: ' . $canonicalPath);
    }

    $tables = listAllTables($pdo);
    $nonMeta = array_values(array_filter($tables, static fn (string $t): bool => $t !== 'migrations'));
    if (!empty($nonMeta)) {
        echo "Canonical mode requires an empty database (except optional migrations table).\n";
        echo "Found existing tables: " . implode(', ', $nonMeta) . "\n";
        echo "Use scripts/reset_dev.php for a safe local rebuild.\n";
        exit(1);
    }

    $sql = file_get_contents($canonicalPath);
    $stmts = parseSqlStatements((string) $sql);
    foreach ($stmts as $stmt) {
        $pdo->exec($stmt . ';');
    }

    createMigrationsTable($pdo);
    $files = glob($migrationsPath . '/*.sql') ?: [];
    sort($files);
    $insert = $pdo->prepare('INSERT IGNORE INTO migrations (migration) VALUES (?)');
    foreach ($files as $file) {
        $insert->execute([basename($file)]);
    }

    echo "Canonical schema applied and migrations stamped.\n";
    $postCanon = MigrationBaseline::collect($basePath, $pdo);
    if (!$postCanon['baseline_aligned']) {
        echo 'ERROR: Baseline check failed after canonical apply: ' . MigrationBaseline::strictSummaryLine($postCanon) . "\n";
        exit(1);
    }
    echo "Done.\n";
    exit(0);
}

createMigrationsTable($pdo);
$migrated = $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$migratedMap = array_fill_keys($migrated ?: [], true);

$files = glob($migrationsPath . '/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (isset($migratedMap[$name])) {
        continue;
    }

    $sql = (string) file_get_contents($file);
    $statements = parseSqlStatements($sql);
    $hadToleratedLegacyError = false;

    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt . ';');
        } catch (Throwable $e) {
            if (!$strict && isLegacyAlreadyAppliedError($e)) {
                $hadToleratedLegacyError = true;
                echo "Legacy schema conflict tolerated for {$name}: {$e->getMessage()}\n";
                continue;
            }
            throw $e;
        }
    }

    if (!$strict && $hadToleratedLegacyError) {
        if (!migration_nonstrict_end_state_proof_passes($statements, $pdo)) {
            echo "ERROR: {$name} had tolerated legacy conflicts but schema end-state proof failed (or migration has no extractable DDL to verify). Migration was NOT stamped.\n";
            echo "Fix the database or run with --strict after resolving conflicts; do not insert into migrations manually without a matching schema.\n";
            exit(1);
        }
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO migrations (migration) VALUES (?)');
    $stmt->execute([$name]);
    echo ($hadToleratedLegacyError ? "Marked as applied (legacy-safe, proof-verified): {$name}\n" : "Migrated: {$name}\n");
}

$post = MigrationBaseline::collect($basePath, $pdo);
if ($post['pending_count'] > 0) {
    echo 'ERROR: After migrate loop, pending migrations remain (not stamped): ' . implode(', ', array_slice($post['pending'], 0, 10))
        . (count($post['pending']) > 10 ? ' ...' : '') . "\n";
    exit(1);
}

if ($post['orphan_stamp_count'] > 0 && !$verifyBaseline) {
    fwrite(
        STDERR,
        "WARNING: migrations table has {$post['orphan_stamp_count']} orphan stamp(s) (no matching file on disk). "
            . "Not deploy-safe (exit code still 0). Re-run with --verify-baseline or: "
            . "php system/scripts/run_migration_baseline_deploy_gate_01.php\n"
            . "Inspect: php system/scripts/read-only/verify_migration_baseline_readonly.php --json\n"
    );
}

if ($verifyBaseline && !$post['baseline_aligned']) {
    echo 'ERROR: --verify-baseline: migration baseline not aligned: ' . MigrationBaseline::strictSummaryLine($post) . "\n";
    echo "Hint: php system/scripts/read-only/verify_migration_baseline_readonly.php --json\n";
    exit(1);
}

echo $strict ? "Done (strict mode).\n" : "Done.\n";

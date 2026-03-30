<?php

declare(strict_types=1);

/**
 * CORE-RUNTIME-SCHEMA-TRUTH — read-only verifier for compatibility-shim targets.
 *
 * Reports whether the live DB includes structures that optional shims tolerate when missing.
 * Does not modify schema or data.
 *
 * Usage (from `system/` directory):
 *   php scripts/verify_core_schema_compat_readonly.php
 *
 * Exit codes:
 *   0 — all expected objects present (canonical install for shims listed in docs)
 *   1 — database not selected or query failure
 *   2 — one or more expected objects missing (install may still run via shims)
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "verify_core_schema_compat_readonly: no database selected (check .env / config).\n");
    exit(1);
}

/** @var list<string> */
$issues = [];

/**
 * @param list<string> $missingOut
 */
function tableExists(PDO $pdo, string $schema, string $table, array &$missingOut): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$schema, $table]);
    if ($stmt->fetchColumn() !== false) {
        return true;
    }
    $missingOut[] = "missing table `{$table}`";

    return false;
}

/**
 * @param list<string> $missingOut
 */
function columnExists(PDO $pdo, string $schema, string $table, string $column, array &$missingOut): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$schema, $table, $column]);
    if ($stmt->fetchColumn() !== false) {
        return true;
    }
    $missingOut[] = "missing column `{$table}.{$column}`";

    return false;
}

if (!tableExists($pdo, $dbName, 'users', $issues)) {
    fwrite(STDERR, "verify_core_schema_compat_readonly: users table missing — cannot check columns.\n");
    exit(1);
}
columnExists($pdo, $dbName, 'users', 'password_changed_at', $issues);

tableExists($pdo, $dbName, 'staff_groups', $issues);
tableExists($pdo, $dbName, 'staff_group_members', $issues);
tableExists($pdo, $dbName, 'staff_group_permissions', $issues);

if ($issues === []) {
    echo "verify_core_schema_compat_readonly: OK (canonical objects for documented shims are present).\n";
    echo "See system/docs/SCHEMA-COMPATIBILITY-SHIMS.md\n";
    exit(0);
}

echo "verify_core_schema_compat_readonly: DRIFT — install may rely on compatibility shims:\n";
foreach ($issues as $line) {
    echo "  - {$line}\n";
}
echo "See system/docs/SCHEMA-COMPATIBILITY-SHIMS.md and system/data/migrations/ (055, 058, 066).\n";
exit(2);

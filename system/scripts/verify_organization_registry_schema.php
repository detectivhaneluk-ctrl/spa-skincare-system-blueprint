<?php

declare(strict_types=1);

/**
 * FOUNDATION-38 — read-only schema verifier: organizations.suspended_at + user_organization_memberships pivot.
 *
 * Usage (from `system/`):
 *   php scripts/verify_organization_registry_schema.php
 *   php scripts/verify_organization_registry_schema.php --json
 *
 * Exit codes:
 *   0 — required DDL present (F-37 S1 shape)
 *   1 — DB not selected, missing table/column/FK/index, or users.branch_id missing
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);

$pdo = app(\Core\App\Database::class)->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "verify_organization_registry_schema: no database selected.\n");
    exit(1);
}

function tableExists(PDO $pdo, string $schema, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$schema, $table]);

    return $stmt->fetchColumn() !== false;
}

function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$schema, $table, $column]);

    return $stmt->fetchColumn() !== false;
}

function indexExists(PDO $pdo, string $schema, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $stmt->execute([$schema, $table, $indexName]);

    return $stmt->fetchColumn() !== false;
}

/**
 * @return list<string>
 */
function foreignKeysOnTable(PDO $pdo, string $schema, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?'
    );
    $stmt->execute([$schema, $table, 'FOREIGN KEY']);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_map(static fn ($n) => (string) $n, $rows ?: []));
}

$errors = [];

if (!tableExists($pdo, $dbName, 'organizations')) {
    $errors[] = 'table organizations missing';
}
if (!columnExists($pdo, $dbName, 'organizations', 'suspended_at')) {
    $errors[] = 'organizations.suspended_at missing';
}

if (!tableExists($pdo, $dbName, 'user_organization_memberships')) {
    $errors[] = 'table user_organization_memberships missing';
} else {
    foreach (['user_id', 'organization_id', 'status', 'default_branch_id', 'created_at', 'updated_at'] as $col) {
        if (!columnExists($pdo, $dbName, 'user_organization_memberships', $col)) {
            $errors[] = "user_organization_memberships.{$col} missing";
        }
    }
    if (!indexExists($pdo, $dbName, 'user_organization_memberships', 'PRIMARY')) {
        $errors[] = 'user_organization_memberships PRIMARY key missing';
    }
    if (!indexExists($pdo, $dbName, 'user_organization_memberships', 'idx_user_organization_memberships_organization_id')) {
        $errors[] = 'index idx_user_organization_memberships_organization_id missing';
    }
    if (!indexExists($pdo, $dbName, 'user_organization_memberships', 'idx_user_organization_memberships_status')) {
        $errors[] = 'index idx_user_organization_memberships_status missing';
    }
    $fks = foreignKeysOnTable($pdo, $dbName, 'user_organization_memberships');
    foreach (
        [
            'fk_user_organization_memberships_user',
            'fk_user_organization_memberships_organization',
            'fk_user_organization_memberships_default_branch',
        ] as $requiredFk
    ) {
        if (!in_array($requiredFk, $fks, true)) {
            $errors[] = "FK {$requiredFk} missing on user_organization_memberships (have: " . implode(', ', $fks) . ')';
        }
    }
}

if (!tableExists($pdo, $dbName, 'users')) {
    $errors[] = 'table users missing';
} elseif (!columnExists($pdo, $dbName, 'users', 'branch_id')) {
    $errors[] = 'users.branch_id missing (legacy column must remain)';
}

$coreTables = ['organizations', 'branches', 'users', 'settings', 'roles', 'permissions'];
foreach ($coreTables as $t) {
    if (!tableExists($pdo, $dbName, $t)) {
        $errors[] = "core table {$t} missing (unexpected drift)";
    }
}

$ok = $errors === [];

$payload = [
    'verifier' => 'verify_organization_registry_schema',
    'schema_version_hint' => '087_organization_registry_membership_foundation',
    'foundation_wave' => 'FOUNDATION-38',
    'checks_passed' => $ok,
    'errors' => $errors,
    'status_values_documented' => ['active', 'invited', 'revoked'],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo 'verifier: verify_organization_registry_schema' . "\n";
    echo 'checks_passed: ' . ($ok ? 'true' : 'false') . "\n";
    if ($errors !== []) {
        foreach ($errors as $e) {
            echo "ERROR: {$e}\n";
        }
    }
}

exit($ok ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * FOUNDATION-08 — read-only verifier: organization table + branch.organization_id ownership.
 *
 * Usage (from `system/`):
 *   php scripts/verify_organization_branch_ownership_readonly.php
 *   php scripts/verify_organization_branch_ownership_readonly.php --json
 *
 * Exit codes:
 *   0 — schema present and ownership clean (no NULL org on branches, no orphan FK targets)
 *   1 — DB not selected, missing table/column, or query failure
 *   2 — schema present but ownership not clean
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);

$pdo = app(\Core\App\Database::class)->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "verify_organization_branch_ownership_readonly: no database selected.\n");
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

if (!tableExists($pdo, $dbName, 'organizations')) {
    fwrite(STDERR, "verify_organization_branch_ownership_readonly: table `organizations` missing (run migrations).\n");
    exit(1);
}
if (!columnExists($pdo, $dbName, 'branches', 'organization_id')) {
    fwrite(STDERR, "verify_organization_branch_ownership_readonly: column `branches.organization_id` missing (run migrations).\n");
    exit(1);
}

$organizationsCount = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$branchesCount = (int) $pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();
$branchesNullOrg = (int) $pdo->query(
    'SELECT COUNT(*) FROM branches WHERE organization_id IS NULL'
)->fetchColumn();

$orphaned = (int) $pdo->query(
    'SELECT COUNT(*) FROM branches b
     LEFT JOIN organizations o ON o.id = b.organization_id
     WHERE b.organization_id IS NOT NULL AND o.id IS NULL'
)->fetchColumn();

$defaultOrg = $pdo->query(
    'SELECT id, name, code, deleted_at, created_at
     FROM organizations
     ORDER BY id ASC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

$defaultOrganizationRowSummary = $defaultOrg === false ? null : [
    'id' => (int) $defaultOrg['id'],
    'name' => (string) $defaultOrg['name'],
    'code' => $defaultOrg['code'],
    'deleted_at' => $defaultOrg['deleted_at'],
    'created_at' => $defaultOrg['created_at'],
];

$clean = $organizationsCount > 0 && $branchesNullOrg === 0 && $orphaned === 0;

$payload = [
    'verifier' => 'verify_organization_branch_ownership_readonly',
    'schema_version_hint' => '086_organizations_and_branch_ownership_foundation',
    'organizations_count' => $organizationsCount,
    'branches_count' => $branchesCount,
    'branches_with_null_organization_count' => $branchesNullOrg,
    'orphaned_branch_organization_refs_count' => $orphaned,
    'default_organization_row_summary' => $defaultOrganizationRowSummary,
    'ownership_clean' => $clean,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "organizations_count: {$organizationsCount}\n";
    echo "branches_count: {$branchesCount}\n";
    echo "branches_with_null_organization_count: {$branchesNullOrg}\n";
    echo "orphaned_branch_organization_refs_count: {$orphaned}\n";
    echo 'default_organization_row_summary: ' . json_encode($defaultOrganizationRowSummary, JSON_UNESCAPED_UNICODE) . "\n";
    echo 'ownership_clean: ' . ($clean ? 'true' : 'false') . "\n";
}

exit($clean ? 0 : 2);

<?php

declare(strict_types=1);

/**
 * FOUNDATION-41 — verifier: {@see \Modules\Organizations\Services\OrganizationRegistryMutationService} contract.
 *
 * Uses a DB transaction with {@code ROLLBACK} so no test rows remain (matches safe audit practice).
 *
 * Usage (from `system/`):
 *   php scripts/audit_organization_registry_mutation_service.php
 *   php scripts/audit_organization_registry_mutation_service.php --json
 *
 * Exit codes:
 *   0 — contract checks passed
 *   1 — assertion failure, DB error, or unexpected exception
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);

$requiredRowKeys = ['id', 'name', 'code', 'created_at', 'updated_at', 'suspended_at', 'deleted_at'];

/**
 * @param array<string, mixed> $row
 */
function validateRowShape(array $row, array $requiredKeys): ?string
{
    foreach ($requiredKeys as $k) {
        if (!array_key_exists($k, $row)) {
            return "missing key: {$k}";
        }
    }

    return null;
}

$errors = [];

try {
    $db = app(\Core\App\Database::class);
    $mut = app(\Modules\Organizations\Services\OrganizationRegistryMutationService::class);
    $read = app(\Modules\Organizations\Services\OrganizationRegistryReadService::class);

    $ghostId = 999_999_999;
    if ($mut->updateOrganizationProfile($ghostId, ['name' => 'X']) !== null) {
        $errors[] = 'updateOrganizationProfile(nonexistent id) expected null';
    }
    if ($mut->suspendOrganization(0) !== null) {
        $errors[] = 'suspendOrganization(0) expected null';
    }
    if ($mut->reactivateOrganization(-1) !== null) {
        $errors[] = 'reactivateOrganization(-1) expected null';
    }

    try {
        $mut->createOrganization(['name' => '   ']);
        $errors[] = 'createOrganization(empty trimmed name) expected InvalidArgumentException';
    } catch (InvalidArgumentException) {
        // expected
    }

    $pdo = $db->connection();
    $countBefore = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM organizations')['c'] ?? 0);

    $pdo->beginTransaction();

    $auditCode = 'F41_AUDIT_' . bin2hex(random_bytes(4));
    $created = $mut->createOrganization([
        'name' => 'F41 mutation audit org',
        'code' => $auditCode,
    ]);
    $shapeErr = validateRowShape($created, $requiredRowKeys);
    if ($shapeErr !== null) {
        $errors[] = "createOrganization row: {$shapeErr}";
    }
    if ($created['suspended_at'] !== null) {
        $errors[] = 'createOrganization: suspended_at expected null for new row';
    }

    $orgId = (int) $created['id'];
    $updated = $mut->updateOrganizationProfile($orgId, ['name' => 'F41 mutation audit org renamed']);
    if ($updated === null || $updated['name'] !== 'F41 mutation audit org renamed') {
        $errors[] = 'updateOrganizationProfile name mismatch';
    }

    $suspended = $mut->suspendOrganization($orgId);
    if ($suspended === null || $suspended['suspended_at'] === null) {
        $errors[] = 'suspendOrganization: suspended_at must be non-null';
    }

    $live = $mut->reactivateOrganization($orgId);
    if ($live === null || $live['suspended_at'] !== null) {
        $errors[] = 'reactivateOrganization: suspended_at must be null';
    }

    $clearedCode = $mut->updateOrganizationProfile($orgId, ['code' => null]);
    if ($clearedCode === null || $clearedCode['code'] !== null) {
        $errors[] = 'updateOrganizationProfile code null: expected code column null';
    }

    $pdo->rollBack();

    $countAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM organizations')['c'] ?? 0);
    if ($countAfter !== $countBefore) {
        $errors[] = "organization count after rollback expected {$countBefore}, got {$countAfter}";
    }
    if ($read->getOrganizationById($orgId) !== null) {
        $errors[] = 'rolled-back organization id should not be readable';
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errors[] = $e->getMessage();
}

$ok = $errors === [];

$payload = [
    'auditor' => 'audit_organization_registry_mutation_service',
    'foundation_wave' => 'FOUNDATION-41',
    'required_row_keys' => $requiredRowKeys,
    'checks_passed' => $ok,
    'errors' => $errors,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "auditor: audit_organization_registry_mutation_service\n";
    echo 'checks_passed: ' . ($ok ? 'true' : 'false') . "\n";
    foreach ($errors as $e) {
        echo "ERROR: {$e}\n";
    }
}

exit($ok ? 0 : 1);

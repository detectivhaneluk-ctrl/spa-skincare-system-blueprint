<?php

declare(strict_types=1);

/**
 * FOUNDATION-40 — read-only audit: {@see \Modules\Organizations\Services\OrganizationRegistryReadService} contract vs DB.
 *
 * Usage (from `system/`):
 *   php scripts/audit_organization_registry_read_service.php
 *   php scripts/audit_organization_registry_read_service.php --json
 *
 * Exit codes:
 *   0 — list/get shapes match contract; counts align with raw SQL
 *   1 — failure (missing keys, count mismatch, service error)
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);

$requiredRowKeys = ['id', 'name', 'code', 'created_at', 'updated_at', 'suspended_at', 'deleted_at'];

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
    $service = app(\Modules\Organizations\Services\OrganizationRegistryReadService::class);

    $list = $service->listOrganizations();
    if (!is_array($list)) {
        $errors[] = 'listOrganizations() did not return array';
    } else {
        $sqlCount = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM organizations')['c'];
        if (count($list) !== $sqlCount) {
            $errors[] = 'listOrganizations count ' . count($list) . " !== organizations table count {$sqlCount}";
        }
        foreach ($list as $i => $row) {
            if (!is_array($row)) {
                $errors[] = "listOrganizations[{$i}] is not array";
                break;
            }
            $shapeErr = validateRowShape($row, $requiredRowKeys);
            if ($shapeErr !== null) {
                $errors[] = "listOrganizations[{$i}]: {$shapeErr}";
                break;
            }
        }
    }

    $nullByZero = $service->getOrganizationById(0);
    if ($nullByZero !== null) {
        $errors[] = 'getOrganizationById(0) expected null';
    }

    if ($list !== [] && is_array($list)) {
        $firstId = (int) $list[0]['id'];
        $one = $service->getOrganizationById($firstId);
        if ($one === null) {
            $errors[] = 'getOrganizationById(first list id) returned null';
        } else {
            $shapeErr = validateRowShape($one, $requiredRowKeys);
            if ($shapeErr !== null) {
                $errors[] = "getOrganizationById shape: {$shapeErr}";
            }
        }
    }

    $maxRow = $db->fetchOne('SELECT COALESCE(MAX(id), 0) AS m FROM organizations');
    $ghostId = (int) ($maxRow['m'] ?? 0) + 1_000_000_000;
    $missingId = $service->getOrganizationById($ghostId);
    if ($missingId !== null) {
        $errors[] = 'getOrganizationById(nonexistent) expected null';
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$ok = $errors === [];

$payload = [
    'auditor' => 'audit_organization_registry_read_service',
    'foundation_wave' => 'FOUNDATION-40',
    'required_row_keys' => $requiredRowKeys,
    'checks_passed' => $ok,
    'errors' => $errors,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "auditor: audit_organization_registry_read_service\n";
    echo 'checks_passed: ' . ($ok ? 'true' : 'false') . "\n";
    foreach ($errors as $e) {
        echo "ERROR: {$e}\n";
    }
}

exit($ok ? 0 : 1);

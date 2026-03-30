<?php

declare(strict_types=1);

/**
 * FOUNDATION-09 — read-only verifier: DB preconditions + documented runtime resolution modes.
 *
 * Usage (from `system/`):
 *   php scripts/verify_organization_context_resolution_readonly.php
 *   php scripts/verify_organization_context_resolution_readonly.php --json
 *
 * Exit codes:
 *   0 — always (informational; does not assert production health by itself)
 *   1 — DB not selected or query failure
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);

$pdo = app(\Core\App\Database::class)->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "verify_organization_context_resolution_readonly: no database selected.\n");
    exit(1);
}

$runtimeResolutionModeSummary = <<<'TXT'
HTTP pipeline (after BranchContextMiddleware): OrganizationContextMiddleware calls OrganizationContextResolver.
- Branch context non-null: organization_id from INNER JOIN branches (active) + organizations (active). Mismatch or missing row => DomainException (fail closed).
- Branch context null: organization id set only if exactly one row in organizations with deleted_at IS NULL; zero or >1 active orgs => organization id null with mode unresolved_no_active_org or unresolved_ambiguous_orgs.
- No request/session organization_id parameter; no user org membership in this wave.
TXT;
$runtimeResolutionModeSummary = trim($runtimeResolutionModeSummary);

try {
    $activeOrganizationsCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL'
    )->fetchColumn();

    $resolvedDefaultOrgPossible = $activeOrganizationsCount === 1;

    $branchesWithInvalidOrgCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM branches b
         LEFT JOIN organizations o ON o.id = b.organization_id
         WHERE o.id IS NULL OR o.deleted_at IS NOT NULL'
    )->fetchColumn();

    $branchContextOrgMismatchRiskCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM branches b
         LEFT JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.deleted_at IS NULL
           AND (o.id IS NULL OR b.organization_id IS NULL)'
    )->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, 'verify_organization_context_resolution_readonly: query failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$payload = [
    'verifier' => 'verify_organization_context_resolution_readonly',
    'wave' => 'FOUNDATION-09',
    'active_organizations_count' => $activeOrganizationsCount,
    'resolved_default_org_possible' => $resolvedDefaultOrgPossible,
    'branches_with_invalid_org_count' => $branchesWithInvalidOrgCount,
    'branch_context_org_mismatch_risk_count' => $branchContextOrgMismatchRiskCount,
    'runtime_resolution_mode_summary' => $runtimeResolutionModeSummary,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    foreach ($payload as $k => $v) {
        if ($k === 'runtime_resolution_mode_summary') {
            echo $k . ":\n" . $v . "\n";
        } else {
            echo $k . ': ' . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

exit(0);

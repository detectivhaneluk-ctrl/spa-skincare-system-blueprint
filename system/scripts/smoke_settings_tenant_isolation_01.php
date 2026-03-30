<?php

declare(strict_types=1);

/**
 * SETTINGS-TENANT-ISOLATION-01 deterministic settings scope verifier.
 *
 * Verifies:
 * - tenant A org default resolves for tenant A
 * - tenant A branch override applies only in tenant A branch
 * - tenant B does not inherit tenant A org default
 * - tenant B does not inherit tenant A branch override
 * - cross-org branch write under mismatched org context is denied
 */

require dirname(__DIR__) . '/bootstrap.php';

$db = app(\Core\App\Database::class);
$settings = app(\Core\App\SettingsService::class);
$branchContext = app(\Core\Branch\BranchContext::class);
$orgContext = app(\Core\Organization\OrganizationContext::class);

$rowA = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id AS organization_id
     FROM branches b
     WHERE b.code = ? AND b.deleted_at IS NULL
     LIMIT 1',
    ['SMOKE_A']
);
$rowB = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id AS organization_id
     FROM branches b
     WHERE b.code = ? AND b.deleted_at IS NULL
     LIMIT 1',
    ['SMOKE_B']
);
if ($rowA === null || $rowB === null) {
    fwrite(STDERR, "SMOKE_A/SMOKE_B branches are required. Run scripts/dev-only/seed_branch_smoke_data.php first.\n");
    exit(2);
}
$branchA = (int) $rowA['branch_id'];
$orgA = (int) $rowA['organization_id'];
$branchB = (int) $rowB['branch_id'];
$orgB = (int) $rowB['organization_id'];
if ($branchA <= 0 || $branchB <= 0 || $orgA <= 0 || $orgB <= 0) {
    fwrite(STDERR, "Proof requires active SMOKE_A and SMOKE_B branches with organizations.\n");
    exit(2);
}
if ($orgA === $orgB) {
    // Deterministic proof fixture: force SMOKE_B under a second organization.
    $secondOrg = $db->fetchOne(
        'SELECT id FROM organizations WHERE deleted_at IS NULL AND id <> ? ORDER BY id ASC LIMIT 1',
        [$orgA]
    );
    if ($secondOrg === null) {
        $db->query(
            'INSERT INTO organizations (name, code, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
            ['Smoke Organization B', 'SMOKE_ORG_B']
        );
        $secondOrgId = (int) $db->lastInsertId();
    } else {
        $secondOrgId = (int) $secondOrg['id'];
    }
    if ($secondOrgId <= 0) {
        fwrite(STDERR, "Unable to create/resolve second organization for smoke proof.\n");
        exit(2);
    }
    $db->query('UPDATE branches SET organization_id = ? WHERE id = ?', [$secondOrgId, $branchB]);
    $orgB = $secondOrgId;
}

$key = 'settings_iso_01.proof_key';

$db->query('DELETE FROM settings WHERE `key` = ?', [$key]);

$passed = 0;
$failed = 0;
$fail = static function (string $name, string $detail) use (&$failed): void {
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
};
$pass = static function (string $name) use (&$passed): void {
    $passed++;
    fwrite(STDOUT, "PASS  {$name}\n");
};

try {
    // Platform default.
    $branchContext->setCurrentBranchId(null);
    $orgContext->setFromResolution(null, \Core\Organization\OrganizationContext::MODE_UNRESOLVED_NO_ACTIVE_ORG);
    $settings->set($key, 'platform-default', 'string', 'proof', null);

    // Org A default.
    $orgContext->setFromResolution($orgA, \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED);
    $settings->set($key, 'org-a-default', 'string', 'proof', null);

    // Branch A override.
    $branchContext->setCurrentBranchId($branchA);
    $orgContext->setFromResolution($orgA, \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED);
    $settings->set($key, 'branch-a-override', 'string', 'proof', $branchA);

    // Read branch A -> branch override
    $vA = (string) $settings->get($key, 'missing', $branchA);
    if ($vA === 'branch-a-override') {
        $pass('tenant_a_branch_override_applies');
    } else {
        $fail('tenant_a_branch_override_applies', "expected branch-a-override, got {$vA}");
    }

    // Read tenant B -> platform default (no orgB default set)
    $vB = (string) $settings->get($key, 'missing', $branchB);
    if ($vB === 'platform-default') {
        $pass('tenant_b_does_not_inherit_tenant_a_defaults');
        $pass('tenant_b_does_not_inherit_tenant_a_branch_override');
    } else {
        $fail('tenant_b_does_not_inherit_tenant_a_defaults', "expected platform-default, got {$vB}");
        $fail('tenant_b_does_not_inherit_tenant_a_branch_override', "expected platform-default, got {$vB}");
    }

    // Cross-org branch write must be denied.
    $orgContext->setFromResolution($orgB, \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED);
    try {
        $settings->set($key, 'illegal-cross-org-write', 'string', 'proof', $branchA);
        $fail('cross_org_branch_write_denied', 'expected DomainException for org mismatch');
    } catch (\DomainException) {
        $pass('cross_org_branch_write_denied');
    }
} finally {
    $db->query('DELETE FROM settings WHERE `key` = ?', [$key]);
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);

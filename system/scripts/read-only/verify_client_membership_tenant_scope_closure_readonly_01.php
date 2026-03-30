<?php

declare(strict_types=1);

/**
 * FOUNDATION-CLIENT-MEMBERSHIP-TENANCY-CANONICAL-CONTRACT-CLOSURE-05 — Tier A read-only proof:
 * `client_memberships` tenant visibility is centralized on {@see \Core\Organization\OrganizationRepositoryScope::clientMembershipVisibleFromBranchContextClause()}
 * and consumed from {@see \Modules\Memberships\Repositories\ClientMembershipRepository} (no duplicated hand-rolled OR trees).
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_client_membership_tenant_scope_closure_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$orgScope = (string) file_get_contents($system . '/core/Organization/OrganizationRepositoryScope.php');
$repo = (string) file_get_contents($system . '/modules/memberships/Repositories/ClientMembershipRepository.php');

$checks['OrganizationRepositoryScope defines clientMembershipVisibleFromBranchContextClause'] = str_contains($orgScope, 'function clientMembershipVisibleFromBranchContextClause');

$checks['ClientMembershipRepository delegates tenant reads/writes through clientMembershipTenantVisibility'] = str_contains($repo, 'clientMembershipTenantVisibility')
    && str_contains($repo, 'clientMembershipVisibleFromBranchContextClause')
    && substr_count($repo, 'clientMembershipTenantVisibility') >= 9;

$checks['ClientMembershipRepository does not re-hand-roll bctx.organization_id subquery'] = !str_contains($repo, 'bctx.organization_id');

$checks['ClientMembershipRepository has no inline cm.branch_id IS NULL tenant arm'] = !str_contains($repo, 'cm.branch_id IS NULL');

$checks['No duplicated tenant OR tree pattern (cm.branch_id IS NOT NULL AND cm.branch_id =)'] = substr_count($repo, 'cm.branch_id IS NOT NULL AND cm.branch_id =') === 0;

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

exit(0);

<?php

declare(strict_types=1);

/**
 * FOUNDATION-FINAL-TENANCY-INVARIANT-ENFORCER-MARKETING-APPOINTMENTS-02 — readonly surface truth for canonical org/branch scoping.
 *
 *   php system/scripts/read-only/verify_marketing_appointments_final_tenant_invariant_wave_02_readonly_01.php
 */

$root = dirname(__DIR__, 2);
$fail = [];

$targets = [
    'modules/marketing/repositories/MarketingContactListRepository.php',
    'modules/marketing/repositories/MarketingContactAudienceRepository.php',
    'modules/appointments/services/AvailabilityService.php',
    'modules/appointments/repositories/BlockedSlotRepository.php',
];

$badClientOr = '/c\.branch_id\s*=\s*\?\s+OR\s+c\.branch_id\s+IS\s+NULL/i';
$badStaffBranchOr = '/\bbranch_id\s*=\s*\?\s+OR\s+branch_id\s+IS\s+NULL\b/i';
$badBsOr = '/\bbs\.branch_id\s*=\s*\?\s+OR\s+bs\.branch_id\s+IS\s+NULL\b/i';

foreach ($targets as $rel) {
    $path = $root . '/' . $rel;
    if (!is_readable($path)) {
        $fail[] = "Missing {$rel}";
        continue;
    }
    $src = (string) file_get_contents($path);
    if (str_contains($rel, 'marketing/repositories')) {
        if (preg_match($badClientOr, $src) === 1) {
            $fail[] = "{$rel}: forbidden hand-rolled c.branch_id = ? OR c.branch_id IS NULL";
        }
        if (!str_contains($src, 'clientMarketingBranchScopedOrBranchlessTenantMemberClause')) {
            $fail[] = "{$rel}: must use clientMarketingBranchScopedOrBranchlessTenantMemberClause for marketing client plane";
        }
    }
    if (str_contains($rel, 'AvailabilityService.php')) {
        if (preg_match($badStaffBranchOr, $src) === 1) {
            $fail[] = "{$rel}: forbidden hand-rolled staff branch_id = ? OR branch_id IS NULL";
        }
        if (!str_contains($src, 'staffSelectableAtOperationBranchTenantClause')) {
            $fail[] = "{$rel}: must reference staffSelectableAtOperationBranchTenantClause for branch-scoped staff SQL";
        }
    }
    if (str_contains($rel, 'BlockedSlotRepository.php')) {
        if (preg_match($badBsOr, $src) === 1) {
            $fail[] = "{$rel}: forbidden hand-rolled bs.branch_id OR NULL";
        }
        if (!str_contains($src, 'settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause')) {
            $fail[] = "{$rel}: must use settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause for concrete branch";
        }
        if (!str_contains($src, 'settingsBackedCatalogGlobalNullBranchOrgAnchoredSql')) {
            $fail[] = "{$rel}: must use settingsBackedCatalogGlobalNullBranchOrgAnchoredSql when no operation branch";
        }
    }
}

$scope = $root . '/core/Organization/OrganizationRepositoryScope.php';
if (!is_readable($scope)) {
    $fail[] = 'Missing OrganizationRepositoryScope.php';
} else {
    $scopeSrc = (string) file_get_contents($scope);
    if (!str_contains($scopeSrc, 'function staffSelectableAtOperationBranchTenantClause')) {
        $fail[] = 'OrganizationRepositoryScope must define staffSelectableAtOperationBranchTenantClause';
    }
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL marketing/appointments final tenant invariant wave 02:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_marketing_appointments_final_tenant_invariant_wave_02_readonly_01\n";

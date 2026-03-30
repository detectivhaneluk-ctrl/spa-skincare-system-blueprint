<?php

declare(strict_types=1);

/**
 * ROOT-04 — static proof: strict tenant helpers are split from explicit repair/global helpers for membership invoice-plane SQL.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_root_04_strict_repair_split_membership_invoice_plane_readonly_01.php
 */

$root = dirname(__DIR__, 3);
$scope = (string) file_get_contents($root . '/system/core/Organization/OrganizationRepositoryScope.php');
$saleRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipSaleRepository.php');
$cycleRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipBillingCycleRepository.php');

/**
 * @return string|null
 */
function extractMethodBody(string $source, string $methodName): ?string
{
    $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::\s*[^{\s]+)?\s*\{([\s\S]*?)\n    \}/';
    if (preg_match($pattern, $source, $m) !== 1) {
        return null;
    }

    return $m[1];
}

$failed = [];
$checks = [];

$orgStrict = extractMethodBody($scope, 'globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause');
$orgCompat = extractMethodBody($scope, 'globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped');
$saleStrict = extractMethodBody($saleRepo, 'strictTenantInvoicePlaneBranchScope');
$saleRepair = extractMethodBody($saleRepo, 'resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable');
$cycleStrict = extractMethodBody($cycleRepo, 'strictTenantInvoicePlaneBranchScope');
$cycleRepair = extractMethodBody($cycleRepo, 'resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable');
$findByPeriod = extractMethodBody($cycleRepo, 'findByMembershipAndPeriod');

$checks['OrganizationRepositoryScope exposes honest strict global-admin helper'] =
    $orgStrict !== null
    && str_contains($scope, 'function globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause(')
    && str_contains($orgStrict, 'throw new AccessDeniedException')
    && !str_contains($orgStrict, "return ['sql' => '', 'params' => []]");

$checks['Legacy OrUnscoped helper no longer returns empty SQL'] =
    $orgCompat !== null
    && str_contains($orgCompat, 'globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause(')
    && !str_contains($orgCompat, "return ['sql' => '', 'params' => []]");

$checks['OrganizationRepositoryScope exposes non-throwing strict-context probe'] =
    str_contains($scope, 'function isBranchDerivedResolvedOrganizationContext(): bool');

$checks['MembershipSaleRepository strict helper is fail-closed only'] =
    $saleStrict !== null
    && str_contains($saleStrict, 'branchColumnOwnedByResolvedOrganizationExistsClause')
    && !str_contains($saleStrict, 'catch (AccessDeniedException)')
    && !str_contains($saleStrict, "return ['sql' => '', 'params' => []]");

$checks['MembershipSaleRepository repair helper is explicit and nullable, not empty SQL'] =
    $saleRepair !== null
    && str_contains($saleRepair, 'resolvedOrganizationId() === null')
    && str_contains($saleRepair, 'return null;')
    && str_contains($saleRepair, 'globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause')
    && !str_contains($saleRepair, "return ['sql' => '', 'params' => []]");

$checks['MembershipSaleRepository removed hidden catch-to-unscoped helper path'] =
    !str_contains($saleRepo, 'invoicePlaneExistsClauseForMembershipReconcileQueries')
    && !str_contains($saleRepo, 'catch (AccessDeniedException)')
    && !str_contains($saleRepo, 'globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped');

$checks['MembershipBillingCycleRepository strict helper is fail-closed only'] =
    $cycleStrict !== null
    && str_contains($cycleStrict, 'branchColumnOwnedByResolvedOrganizationExistsClause')
    && !str_contains($cycleStrict, 'catch (AccessDeniedException)')
    && !str_contains($cycleStrict, "return ['sql' => '', 'params' => []]");

$checks['MembershipBillingCycleRepository repair helper is explicit and nullable, not empty SQL'] =
    $cycleRepair !== null
    && str_contains($cycleRepair, 'resolvedOrganizationId() === null')
    && str_contains($cycleRepair, 'return null;')
    && str_contains($cycleRepair, 'globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause')
    && !str_contains($cycleRepair, "return ['sql' => '', 'params' => []]");

$checks['MembershipBillingCycleRepository removed hidden catch-to-unscoped helper path'] =
    !str_contains($cycleRepo, 'invoicePlaneExistsClauseForMembershipReconcileQueries')
    && !str_contains($cycleRepo, 'catch (AccessDeniedException)')
    && !str_contains($cycleRepo, 'globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped');

$checks['findByMembershipAndPeriod is strict-only with no raw fallback'] =
    $findByPeriod !== null
    && str_contains($findByPeriod, 'getAnyLiveBranchIdForResolvedTenantOrganization')
    && str_contains($findByPeriod, 'return null;')
    && str_contains($findByPeriod, 'clientMembershipVisibleFromBranchContextClause')
    && !preg_match('/SELECT\s+\*\s+FROM\s+membership_billing_cycles\s+WHERE\s+client_membership_id\s*=\s*\?/i', $findByPeriod);

foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_root_04_strict_repair_split_membership_invoice_plane_readonly_01: OK\n";
exit(0);

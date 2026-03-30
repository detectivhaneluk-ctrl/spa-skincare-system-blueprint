<?php

declare(strict_types=1);

/**
 * PLT-TNT-02 — Membership repository self-defense proof.
 *
 * Focus:
 * - ClientMembershipRepository explicit runtime vs repair split
 * - MembershipSaleRepository explicit runtime vs repair split
 * - MembershipBillingCycleRepository explicit invoice-plane mutation / aggregate vs repair split
 * - Services no longer rely on mixed-semantics generic membership repository verbs
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';
$cmRepo = (string) file_get_contents($modules . '/memberships/Repositories/ClientMembershipRepository.php');
$saleRepo = (string) file_get_contents($modules . '/memberships/Repositories/MembershipSaleRepository.php');
$cycleRepo = (string) file_get_contents($modules . '/memberships/Repositories/MembershipBillingCycleRepository.php');
$billingSvc = (string) file_get_contents($modules . '/memberships/Services/MembershipBillingService.php');
$lifecycleSvc = (string) file_get_contents($modules . '/memberships/Services/MembershipLifecycleService.php');
$membershipSvc = (string) file_get_contents($modules . '/memberships/Services/MembershipService.php');
$saleSvc = (string) file_get_contents($modules . '/memberships/Services/MembershipSaleService.php');

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

function methodLocked(?string $body): bool
{
    return $body !== null && str_contains($body, 'RepositoryContractGuard::denyMixedSemanticsApi(');
}

$checks = [];
$failed = [];

$checks['ClientMembershipRepository explicit runtime methods exist'] =
    str_contains($cmRepo, 'function findInResolvedTenantScope(')
    && str_contains($cmRepo, 'function findForUpdateInResolvedTenantScope(')
    && str_contains($cmRepo, 'function lockWithDefinitionInResolvedTenantScope(')
    && str_contains($cmRepo, 'function lockWithDefinitionForBillingInResolvedTenantScope(');

$checks['ClientMembershipRepository explicit repair methods exist'] =
    str_contains($cmRepo, 'function findForRepair(')
    && str_contains($cmRepo, 'function findForUpdateForRepair(')
    && str_contains($cmRepo, 'function lockWithDefinitionForRepair(')
    && str_contains($cmRepo, 'function lockWithDefinitionForBillingForRepair(')
    && str_contains($cmRepo, 'function updateForRepairById(');

$checks['ClientMembershipRepository mixed generic methods are locked through central guard'] =
    methodLocked(extractMethodBody($cmRepo, 'find'))
    && methodLocked(extractMethodBody($cmRepo, 'findForUpdate'))
    && methodLocked(extractMethodBody($cmRepo, 'lockWithDefinition'))
    && methodLocked(extractMethodBody($cmRepo, 'lockWithDefinitionForBilling'))
    && methodLocked(extractMethodBody($cmRepo, 'updateRepairOrUnscopedById'));

$checks['MembershipSaleRepository explicit runtime methods exist'] =
    str_contains($saleRepo, 'function findInResolvedTenantScope(')
    && str_contains($saleRepo, 'function findForUpdateInResolvedTenantScope(')
    && str_contains($saleRepo, 'function updateInTenantScope(')
    && str_contains($saleRepo, 'function updateInResolvedTenantScope(')
    && str_contains($saleRepo, 'function listByInvoiceIdInInvoicePlane(');

$checks['MembershipSaleRepository explicit repair methods exist'] =
    str_contains($saleRepo, 'function findForRepair(')
    && str_contains($saleRepo, 'function findForUpdateForRepair(')
    && str_contains($saleRepo, 'function updateForRepair(')
    && str_contains($saleRepo, 'function listByInvoiceIdForRepair(');

$checks['MembershipSaleRepository mixed generic methods are locked through central guard'] =
    methodLocked(extractMethodBody($saleRepo, 'find'))
    && methodLocked(extractMethodBody($saleRepo, 'findForUpdate'))
    && methodLocked(extractMethodBody($saleRepo, 'update'))
    && methodLocked(extractMethodBody($saleRepo, 'listByInvoiceId'));

$checks['MembershipBillingCycleRepository explicit mutation and aggregate split exists'] =
    str_contains($cycleRepo, 'function findForInvoiceInInvoicePlane(')
    && str_contains($cycleRepo, 'function findForUpdateForInvoiceInInvoicePlane(')
    && str_contains($cycleRepo, 'function listByInvoiceIdInInvoicePlane(')
    && str_contains($cycleRepo, 'function updateInInvoicePlane(')
    && str_contains($cycleRepo, 'function maxInvoicedCycleIdForMembershipInTenantScope(')
    && str_contains($cycleRepo, 'function maxInvoicedCycleIdForMembershipInResolvedTenantScope(')
    && str_contains($cycleRepo, 'function updateForRepair(')
    && str_contains($cycleRepo, 'function findForInvoiceForRepair(')
    && str_contains($cycleRepo, 'function findForUpdateForInvoiceForRepair(')
    && str_contains($cycleRepo, 'function listByInvoiceIdForRepair(')
    && str_contains($cycleRepo, 'function maxInvoicedCycleIdForMembershipForRepair(')
    && methodLocked(extractMethodBody($cycleRepo, 'find'))
    && methodLocked(extractMethodBody($cycleRepo, 'findForUpdate'))
    && methodLocked(extractMethodBody($cycleRepo, 'findForInvoice'))
    && methodLocked(extractMethodBody($cycleRepo, 'findForUpdateForInvoice'))
    && methodLocked(extractMethodBody($cycleRepo, 'listByInvoiceId'))
    && methodLocked(extractMethodBody($cycleRepo, 'maxInvoicedCycleIdForMembership'))
    && methodLocked(extractMethodBody($cycleRepo, 'update'));

$checks['MembershipService no longer uses generic clientMemberships->find'] =
    !str_contains($membershipSvc, 'clientMemberships->find(')
    && str_contains($membershipSvc, 'clientMemberships->findInResolvedTenantScope(');

$checks['MembershipSaleService no longer uses generic sale repository methods'] =
    !str_contains($saleSvc, 'sales->find(')
    && !str_contains($saleSvc, 'sales->findForUpdate(')
    && !str_contains($saleSvc, 'sales->update(')
    && !str_contains($saleSvc, 'sales->listByInvoiceId(')
    && str_contains($saleSvc, 'listSaleRowsForInvoiceContract(')
    && str_contains($saleSvc, 'lockSaleForSettlementContract(')
    && str_contains($saleSvc, 'updateSaleWithContract(');

$checks['MembershipLifecycleService no longer uses generic mixed membership methods'] =
    !str_contains($lifecycleSvc, 'clientMemberships->findForUpdate(')
    && !str_contains($lifecycleSvc, 'clientMemberships->find(')
    && !str_contains($lifecycleSvc, 'clientMemberships->updateRepairOrUnscopedById(')
    && str_contains($lifecycleSvc, 'findForUpdateForRepair(')
    && str_contains($lifecycleSvc, 'findForRepair(')
    && str_contains($lifecycleSvc, 'updateForRepairById(');

$checks['MembershipBillingService no longer uses generic mixed membership methods'] =
    !str_contains($billingSvc, 'clientMemberships->find(')
    && !str_contains($billingSvc, 'clientMemberships->lockWithDefinitionForBilling(')
    && !str_contains($billingSvc, 'clientMemberships->updateRepairOrUnscopedById(')
    && !str_contains($billingSvc, 'cycles->update(')
    && !str_contains($billingSvc, 'cycles->find(')
    && !str_contains($billingSvc, 'cycles->findForInvoice(')
    && !str_contains($billingSvc, 'cycles->findForUpdateForInvoice(')
    && !str_contains($billingSvc, 'cycles->listByInvoiceId(')
    && !str_contains($billingSvc, 'cycles->maxInvoicedCycleIdForMembership(')
    && str_contains($billingSvc, 'lockClientMembershipForBillingContract(')
    && str_contains($billingSvc, 'listCycleRowsForInvoiceContract(')
    && str_contains($billingSvc, 'lockCycleForInvoiceContract(')
    && str_contains($billingSvc, 'findCycleForInvoiceContract(')
    && str_contains($billingSvc, 'maxInvoicedCycleIdForMembershipContract(')
    && str_contains($billingSvc, 'updateCycleWithContract(')
    && str_contains($billingSvc, 'findForRepair(')
    && str_contains($billingSvc, 'updateForRepairById(');

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

echo PHP_EOL . "verify_membership_repository_contract_self_defense_readonly_01: OK\n";
exit(0);

<?php

declare(strict_types=1);

/**
 * PLT-TNT-03 — Membership-sale + invoice-plane self-defending boundary rollout proof.
 *
 * Focus:
 * - MembershipSaleRepository runtime vs repair split is explicit and generic wrappers are locked
 * - MembershipBillingCycleRepository no longer leaves maxInvoicedCycleIdForMembership unscoped
 * - PaymentRepository invoice-plane reads/aggregates/existence helpers are explicit and generic wrappers are locked
 * - Live callers moved to explicit runtime-safe methods
 * - Central repository contract policy covers the migrated families
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';
$scripts = $root . '/system/scripts';

$saleRepo = (string) file_get_contents($modules . '/memberships/Repositories/MembershipSaleRepository.php');
$cycleRepo = (string) file_get_contents($modules . '/memberships/Repositories/MembershipBillingCycleRepository.php');
$paymentRepo = (string) file_get_contents($modules . '/sales/repositories/PaymentRepository.php');
$saleSvc = (string) file_get_contents($modules . '/memberships/Services/MembershipSaleService.php');
$billingSvc = (string) file_get_contents($modules . '/memberships/Services/MembershipBillingService.php');
$invoiceSvc = (string) file_get_contents($modules . '/sales/services/InvoiceService.php');
$paymentSvc = (string) file_get_contents($modules . '/sales/services/PaymentService.php');
$invoiceCtrl = (string) file_get_contents($modules . '/sales/controllers/InvoiceController.php');
$paymentCtrl = (string) file_get_contents($modules . '/sales/controllers/PaymentController.php');
$policy = (string) file_get_contents($scripts . '/read-only/lib/repository_contract_policy.php');
$gate = (string) file_get_contents($scripts . '/run_mandatory_tenant_isolation_proof_release_gate_01.php');

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

function methodLocked(string $source, string $methodName): bool
{
    $body = extractMethodBody($source, $methodName);

    return $body !== null && str_contains($body, 'RepositoryContractGuard::denyMixedSemanticsApi(');
}

$checks = [];
$failed = [];

$checks['MembershipSaleRepository explicit split exists'] =
    str_contains($saleRepo, 'function findInResolvedTenantScope(')
    && str_contains($saleRepo, 'function findForRepair(')
    && str_contains($saleRepo, 'function listByInvoiceIdInInvoicePlane(')
    && str_contains($saleRepo, 'function listByInvoiceIdForRepair(')
    && str_contains($saleRepo, 'function updateInTenantScope(')
    && str_contains($saleRepo, 'function updateForRepair(');

$checks['MembershipSaleRepository generic wrappers are locked'] =
    methodLocked($saleRepo, 'find')
    && methodLocked($saleRepo, 'findForUpdate')
    && methodLocked($saleRepo, 'listByInvoiceId')
    && methodLocked($saleRepo, 'update');

$checks['MembershipBillingCycleRepository max aggregate is explicit and generic wrapper is locked'] =
    str_contains($cycleRepo, 'function maxInvoicedCycleIdForMembershipInTenantScope(')
    && str_contains($cycleRepo, 'function maxInvoicedCycleIdForMembershipInResolvedTenantScope(')
    && str_contains($cycleRepo, 'function maxInvoicedCycleIdForMembershipForRepair(')
    && methodLocked($cycleRepo, 'maxInvoicedCycleIdForMembership');

$checks['MembershipBillingCycleRepository invoice-plane split is explicit'] =
    str_contains($cycleRepo, 'function findForInvoiceInInvoicePlane(')
    && str_contains($cycleRepo, 'function findForInvoiceForRepair(')
    && str_contains($cycleRepo, 'function findForUpdateForInvoiceInInvoicePlane(')
    && str_contains($cycleRepo, 'function findForUpdateForInvoiceForRepair(')
    && str_contains($cycleRepo, 'function listByInvoiceIdInInvoicePlane(')
    && str_contains($cycleRepo, 'function listByInvoiceIdForRepair(');

$checks['PaymentRepository explicit invoice-plane methods exist'] =
    str_contains($paymentRepo, 'function findInInvoicePlane(')
    && str_contains($paymentRepo, 'function findForUpdateInInvoicePlane(')
    && str_contains($paymentRepo, 'function listByInvoiceIdInInvoicePlane(')
    && str_contains($paymentRepo, 'function getCompletedTotalByInvoiceIdInInvoicePlane(')
    && str_contains($paymentRepo, 'function existsCompletedByInvoiceAndReferenceInInvoicePlane(')
    && str_contains($paymentRepo, 'function getCompletedRefundedTotalForParentPaymentInInvoicePlane(')
    && str_contains($paymentRepo, 'function hasCompletedRefundForInvoiceInInvoicePlane(');

$checks['PaymentRepository generic and mixed wrappers are locked'] =
    methodLocked($paymentRepo, 'find')
    && methodLocked($paymentRepo, 'findForUpdate')
    && methodLocked($paymentRepo, 'getByInvoiceId')
    && methodLocked($paymentRepo, 'getCompletedTotalByInvoiceId')
    && methodLocked($paymentRepo, 'existsCompletedByInvoiceAndReference')
    && methodLocked($paymentRepo, 'getCompletedRefundedTotalForParentPayment')
    && methodLocked($paymentRepo, 'hasCompletedRefundForInvoice');

$checks['MembershipSaleService moved to explicit sale contracts'] =
    !str_contains($saleSvc, 'sales->find(')
    && !str_contains($saleSvc, 'sales->findForUpdate(')
    && !str_contains($saleSvc, 'sales->listByInvoiceId(')
    && !str_contains($saleSvc, 'sales->update(')
    && str_contains($saleSvc, 'listSaleRowsForInvoiceContract(')
    && str_contains($saleSvc, 'lockSaleForSettlementContract(')
    && str_contains($saleSvc, 'updateSaleWithContract(');

$checks['MembershipBillingService moved to explicit cycle contracts'] =
    !str_contains($billingSvc, 'cycles->find(')
    && !str_contains($billingSvc, 'cycles->findForInvoice(')
    && !str_contains($billingSvc, 'cycles->findForUpdateForInvoice(')
    && !str_contains($billingSvc, 'cycles->listByInvoiceId(')
    && !str_contains($billingSvc, 'cycles->maxInvoicedCycleIdForMembership(')
    && str_contains($billingSvc, 'listCycleRowsForInvoiceContract(')
    && str_contains($billingSvc, 'lockCycleForInvoiceContract(')
    && str_contains($billingSvc, 'findCycleForInvoiceContract(')
    && str_contains($billingSvc, 'maxInvoicedCycleIdForMembershipContract(');

$checks['Sales runtime callers moved to explicit payment methods'] =
    !str_contains($paymentSvc, 'repo->findForUpdate(')
    && !str_contains($paymentSvc, 'repo->existsCompletedByInvoiceAndReference(')
    && !str_contains($paymentSvc, 'repo->getCompletedRefundedTotalForParentPayment(')
    && !str_contains($invoiceSvc, 'paymentRepo->getCompletedTotalByInvoiceId(')
    && !str_contains($invoiceSvc, 'paymentRepo->hasCompletedRefundForInvoice(')
    && !str_contains($invoiceCtrl, 'paymentRepo->getByInvoiceId(')
    && !str_contains($invoiceCtrl, 'paymentRepo->getCompletedRefundedTotalForParentPayment(')
    && !str_contains($paymentCtrl, 'paymentRepo->find(')
    && str_contains($paymentSvc, 'findForUpdateInInvoicePlane(')
    && str_contains($invoiceSvc, 'getCompletedTotalByInvoiceIdInInvoicePlane(')
    && str_contains($invoiceCtrl, 'listByInvoiceIdInInvoicePlane(')
    && str_contains($paymentCtrl, 'findInInvoicePlane(');

$checks['Central policy inventory covers migrated families'] =
    str_contains($policy, "'class' => 'MembershipSaleRepository'")
    && str_contains($policy, "'class' => 'MembershipBillingCycleRepository'")
    && str_contains($policy, "'class' => 'PaymentRepository'")
    && str_contains($policy, "'locked_mixed_methods' => [");

$checks['Mandatory gate includes PLT-TNT-03 verifier'] =
    str_contains($gate, 'verify_plt_tnt_03_full_self_defending_boundary_rollout_01.php');

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

echo PHP_EOL . "verify_plt_tnt_03_full_self_defending_boundary_rollout_01: OK\n";
exit(0);

<?php

declare(strict_types=1);

/**
 * CROSS-MODULE-INVOICE-PAYMENT-ID-ACCESS-HARDENING-01 + CROSS-MODULE-WEAK-NOTE-CLEANUP-WAVE-01 — static inventory: non-Sales read
 * surfaces that touch invoices/payments and the guard style applied (SalesTenantScope vs explicit control-plane/global paths).
 * Membership invoice-keyed lists are **classified closed** here after the strict-vs-repair split; remaining notes are split by surface.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_cross_module_invoice_payment_read_guard_readonly_01.php
 *
 * Exit: 0 = checks passed, 1 = failure.
 */

$system = dirname(__DIR__, 2);

$reportRepo = (string) file_get_contents($system . '/modules/reports/repositories/ReportRepository.php');
$dashRepo = (string) file_get_contents($system . '/modules/dashboard/repositories/DashboardReadRepository.php');
$memSaleRepo = (string) file_get_contents($system . '/modules/memberships/Repositories/MembershipSaleRepository.php');
$memCycleRepo = (string) file_get_contents($system . '/modules/memberships/Repositories/MembershipBillingCycleRepository.php');
$memBillSvc = (string) file_get_contents($system . '/modules/memberships/Services/MembershipBillingService.php');
$memSaleSvc = (string) file_get_contents($system . '/modules/memberships/Services/MembershipSaleService.php');
$invAudit = (string) file_get_contents($system . '/modules/inventory/services/ProductInvoiceRefundReturnSettlementVisibilityAuditService.php');
$salesProf = (string) file_get_contents($system . '/modules/sales/providers/ClientSalesProfileProviderImpl.php');
$invRepo = (string) file_get_contents($system . '/modules/sales/repositories/InvoiceRepository.php');
$registerReports = (string) file_get_contents($system . '/modules/bootstrap/register_reports.php');
$registerDash = (string) file_get_contents($system . '/modules/bootstrap/register_dashboard.php');
$registerInv = (string) file_get_contents($system . '/modules/bootstrap/register_inventory.php');

$inventory = [
    'Reports JSON (staff)' => [
        'module' => 'reports',
        'entry' => 'GET /reports/* via ReportController + ReportService',
        'guard' => 'SalesTenantScope::invoiceClause on invoice alias i (branch-derived tenant)',
        'files' => ['modules/reports/repositories/ReportRepository.php', 'modules/reports/controllers/ReportController.php'],
    ],
    'Dashboard aggregates (staff)' => [
        'module' => 'dashboard',
        'entry' => 'DashboardSnapshotService / DashboardReadRepository',
        'guard' => 'SalesTenantScope::invoiceClause on invoice-backed payment/open-invoice/membership-overdue queries',
        'files' => ['modules/dashboard/repositories/DashboardReadRepository.php'],
    ],
    'Clients profile sales slice (non-Sales provider)' => [
        'module' => 'clients (via sales provider)',
        'entry' => 'ClientSalesProfileProviderImpl',
        'guard' => 'SalesTenantScope::invoiceClause (pre-existing)',
        'files' => ['modules/sales/providers/ClientSalesProfileProviderImpl.php'],
    ],
    'Memberships invoice-keyed lists / reconcile id discovery (CLASSIFIED: strict tenant + explicit repair/global split)' => [
        'module' => 'memberships',
        'entry' => 'MembershipSaleRepository / MembershipBillingCycleRepository',
        'guard' => 'CLOSED: strictTenantInvoicePlaneBranchScope() is fail-closed; resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable() is explicit repair/global-only and returns null instead of empty SQL. Refund-review queues (listRefundReview / listRefundReviewQueue): FND-TNT-09 resolvedOrganizationId + org EXISTS (sale/cm branch or invoice branch); empty without org.',
        'files' => [
            'modules/memberships/Repositories/MembershipSaleRepository.php',
            'modules/memberships/Repositories/MembershipBillingCycleRepository.php',
        ],
    ],
    'Inventory refund/settlement visibility audit' => [
        'module' => 'inventory',
        'entry' => 'ProductInvoiceRefundReturnSettlementVisibilityAuditService::run',
        'guard' => 'globalAdmin…OrUnscoped on invoices i (scopes when org resolved)',
        'files' => ['modules/inventory/services/ProductInvoiceRefundReturnSettlementVisibilityAuditService.php'],
    ],
    'Sales repositories (reference choke point)' => [
        'module' => 'sales',
        'entry' => 'InvoiceRepository::find / list',
        'guard' => 'SalesTenantScope::invoiceClause (pre-existing; cross-module callers inherit)',
        'files' => ['modules/sales/repositories/InvoiceRepository.php'],
    ],
];

echo "NON-SALES INVOICE/PAYMENT READ SURFACE INVENTORY\n";
echo str_repeat('-', 72) . PHP_EOL;
foreach ($inventory as $label => $meta) {
    echo $label . PHP_EOL;
    echo '  module: ' . $meta['module'] . PHP_EOL;
    echo '  entry: ' . $meta['entry'] . PHP_EOL;
    echo '  guard: ' . $meta['guard'] . PHP_EOL;
    echo '  files: ' . implode(', ', $meta['files']) . PHP_EOL;
}

$checks = [];

$checks['ReportRepository: SalesTenantScope + appendResolvedTenantInvoiceScope'] = str_contains($reportRepo, 'SalesTenantScope')
    && str_contains($reportRepo, 'appendResolvedTenantInvoiceScope')
    && str_contains($reportRepo, 'salesTenantScope->invoiceClause');
$checks['ReportRepository: revenue/payments/refunds/VAT use scope before branch filter'] = preg_match(
    '/getRevenueSummary[\s\S]*?appendResolvedTenantInvoiceScope[\s\S]*?appendBranchFilterOrIncludeGlobalNull/',
    $reportRepo
) === 1
    && substr_count($reportRepo, 'appendResolvedTenantInvoiceScope') >= 4;

$checks['DashboardReadRepository: SalesTenantScope + appendResolvedTenantInvoiceScope'] = str_contains($dashRepo, 'SalesTenantScope')
    && str_contains($dashRepo, 'appendResolvedTenantInvoiceScope');
$checks['DashboardReadRepository: scope on payment + invoice aggregates'] = substr_count($dashRepo, 'appendResolvedTenantInvoiceScope') >= 4;

$checks['Bootstrap: ReportRepository receives SalesTenantScope'] = str_contains($registerReports, 'ReportRepository')
    && str_contains($registerReports, 'SalesTenantScope::class');
$checks['Bootstrap: DashboardReadRepository receives SalesTenantScope'] = str_contains($registerDash, 'DashboardReadRepository')
    && str_contains($registerDash, 'SalesTenantScope::class');

$checks['MembershipSaleRepository invoice-plane paths use strict helper + explicit repair helper'] = str_contains(
    $memSaleRepo,
    'strictTenantInvoicePlaneBranchScope'
) && str_contains($memSaleRepo, 'resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable')
    && !str_contains($memSaleRepo, 'invoicePlaneExistsClauseForMembershipReconcileQueries')
    && !str_contains($memSaleRepo, 'catch (AccessDeniedException)')
    && preg_match('/function\s+findBlockingOpenInitialSale[\s\S]*?strictTenantInvoicePlaneBranchScope\s*\(\s*[\'"]i[\'"]\s*\)/', $memSaleRepo) === 1
    && preg_match('/function\s+listDistinctInvoiceIdsForReconcile[\s\S]*?resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable/', $memSaleRepo) === 1;

$checks['MembershipBillingCycleRepository invoice-plane paths use strict helper + explicit repair helper'] = str_contains($memCycleRepo, 'strictTenantInvoicePlaneBranchScope')
    && str_contains($memCycleRepo, 'resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable')
    && !str_contains($memCycleRepo, 'invoicePlaneExistsClauseForMembershipReconcileQueries')
    && !str_contains($memCycleRepo, 'catch (AccessDeniedException)')
    && str_contains($memCycleRepo, 'listDistinctInvoiceIdsOverdueCandidates')
    && str_contains($memCycleRepo, 'listDistinctInvoiceIdsPendingRenewalApplication')
    && str_contains($memCycleRepo, 'listDistinctInvoiceIdsForReconcile')
    && preg_match('/function\s+listDistinctInvoiceIdsPendingRenewalApplication[\s\S]*?resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable/', $memCycleRepo) === 1
    && preg_match('/function\s+listDistinctInvoiceIdsOverdueCandidates[\s\S]*?resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable/', $memCycleRepo) === 1
    && preg_match('/function\s+listDistinctInvoiceIdsForReconcile[\s\S]*?resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable/', $memCycleRepo) === 1
    && preg_match('/function\s+findByMembershipAndPeriod[\s\S]*?clientMembershipVisibleFromBranchContextClause/', $memCycleRepo) === 1
    && !preg_match('/SELECT\s+\*\s+FROM\s+membership_billing_cycles\s+WHERE\s+client_membership_id\s*=\s*\?/i', $memCycleRepo);

$checks['MembershipBillingCycleRepository::listRefundReviewQueue org-bound + InTenantScope named'] =
    str_contains($memCycleRepo, 'function listRefundReviewQueue(')
    && str_contains($memCycleRepo, 'billingCycleRefundReviewOrganizationBinding')
    && str_contains($memCycleRepo, 'resolvedOrganizationId()')
    && str_contains($memCycleRepo, 'listRefundReviewQueueInTenantScope');

$checks['MembershipSaleRepository::listRefundReview org-bound + InTenantScope named'] =
    str_contains($memSaleRepo, 'function listRefundReview(')
    && str_contains($memSaleRepo, 'membershipSalesRefundReviewOrganizationBinding')
    && str_contains($memSaleRepo, 'resolvedOrganizationId()')
    && str_contains($memSaleRepo, 'listRefundReviewInTenantScope');

$checks['MembershipBillingService class doc references invoice-keyed cycle repo methods'] =
    str_contains($memBillSvc, 'MembershipBillingCycleRepository::listByInvoiceIdInInvoicePlane')
    && str_contains($memBillSvc, 'listDistinctInvoiceIdsForReconcile');

$checks['MembershipSaleService class doc references invoice-keyed sale repo methods'] =
    str_contains($memSaleSvc, 'MembershipSaleRepository::listByInvoiceIdInInvoicePlane')
    && str_contains($memSaleSvc, 'listDistinctInvoiceIdsForReconcile');

$checks['ProductInvoice audit: OrganizationRepositoryScope + OrUnscoped on fetchScopedProductInvoiceLines'] = str_contains(
    $invAudit,
    'OrganizationRepositoryScope'
) && preg_match('/fetchScopedProductInvoiceLines[\s\S]*?globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped/', $invAudit) === 1;
$checks['Bootstrap: ProductInvoice audit service gets OrganizationRepositoryScope'] = str_contains($registerInv, 'ProductInvoiceRefundReturnSettlementVisibilityAuditService')
    && str_contains($registerInv, 'OrganizationRepositoryScope::class');

$checks['ClientSalesProfileProviderImpl still uses invoiceClause (unchanged baseline)'] = str_contains($salesProf, 'salesTenantScope->invoiceClause');
$checks['InvoiceRepository::find still uses tenantScope->invoiceClause'] = str_contains($invRepo, 'tenantScope->invoiceClause');

echo PHP_EOL . 'CLASSIFICATION SUMMARY (CROSS-MODULE-WEAK-NOTE-CLEANUP-WAVE-01)' . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;
echo 'CLOSED — Membership invoice-keyed SQL (listByInvoiceId, listDistinctInvoiceIds* on sales + billing cycles): '
    . 'strict tenant helper split from explicit repair/global helper; no AccessDenied catch-to-unscoped fallback remains in membership invoice-plane helpers.' . PHP_EOL;
echo PHP_EOL;
echo 'SEPARATE MODULE — Payroll invoice-linked eligibility: verify_payroll_invoice_payment_tenant_guard_readonly_01.php' . PHP_EOL;
echo PHP_EOL;
echo 'UPDATED — MembershipSaleRepository::find / findForUpdate: FND-TNT-08 fail-closed org EXISTS on ms.branch_id (same as update); '
    . 'invoice settlement uses findForUpdateInTenantScope when invoice.branch_id > 0. '
    . 'MembershipBillingCycleRepository::find / findForUpdate: FND-TNT-10 invoice-plane JOIN + findForUpdateForInvoice in settlement (see verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php).' . PHP_EOL;
echo PHP_EOL;
echo 'SEPARATE MODULE — Inventory refund/settlement visibility audit still uses its own explicit global control-plane helper family.'
    . PHP_EOL;

$failed = [];
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

echo PHP_EOL . 'All cross-module non-Sales invoice/payment read-guard static checks passed.' . PHP_EOL;
exit(0);

<?php

declare(strict_types=1);

/**
 * PAYROLL-INVOICE-PAYMENT-TENANT-GUARD-HARDENING-01 — static proof: payroll commission eligibility SQL
 * ties the invoice plane to resolved org via {@see \Modules\Sales\Services\SalesTenantScope::invoiceClause()}.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_payroll_invoice_payment_tenant_guard_readonly_01.php
 *
 * Exit: 0 = all checks passed, 1 = failure.
 */

$system = dirname(__DIR__, 2);

$payrollSvc = (string) file_get_contents($system . '/modules/payroll/services/PayrollService.php');
$payrollLines = (string) file_get_contents($system . '/modules/payroll/repositories/PayrollCommissionLineRepository.php');
$payrollRuns = (string) file_get_contents($system . '/modules/payroll/repositories/PayrollRunRepository.php');
$registerPayroll = (string) file_get_contents($system . '/modules/bootstrap/register_payroll.php');
$runCtl = (string) file_get_contents($system . '/modules/payroll/controllers/PayrollRunController.php');

echo "PAYROLL INVOICE/PAYMENT-LINKED READ PATH INVENTORY\n";
echo str_repeat('-', 72) . PHP_EOL;
echo "Entry: POST calculate (and related) → PayrollRunController → PayrollService::calculateRun\n";
echo "Invoice/payment eligibility SQL: PayrollService::fetchEligibleServiceLineEvents only\n";
echo "  - Joins: invoices i, invoice_items ii, appointments a, payments aggregate pm\n";
echo "  - Guard: SalesTenantScope::invoiceClause('i') (branch-derived org EXISTS on i.branch_id)\n";
echo "Commission line reads: PayrollCommissionLineRepository (listByRunId, allocatedSourceRefsExcludingRun)\n";
echo "  - Guard: payroll_runs pr + payrollRunBranchOrgExistsClause('pr') (pre-existing)\n";
echo "Payroll run reads: PayrollRunRepository::find/list* (pre-existing org on pr.branch_id)\n";
echo str_repeat('-', 72) . PHP_EOL;

$checks = [];

$checks['PayrollService imports SalesTenantScope'] = str_contains($payrollSvc, 'use Modules\\Sales\\Services\\SalesTenantScope;');
$checks['PayrollService constructor accepts SalesTenantScope'] = preg_match(
    '/function __construct\([\s\S]*SalesTenantScope \$salesTenantScope/',
    $payrollSvc
) === 1;
$checks['fetchEligibleServiceLineEvents uses salesTenantScope->invoiceClause'] = preg_match(
    '/function fetchEligibleServiceLineEvents\([\s\S]*salesTenantScope->invoiceClause\(\'i\'\)/',
    $payrollSvc
) === 1;
$checks['fetchEligibleServiceLineEvents merges iScope params before branch/period'] = preg_match(
    '/\$iScope = \$this->salesTenantScope->invoiceClause\(\'i\'\);[\s\S]*array_merge\(\$iScope\[\'params\'\], \[\$branchId, \$periodStart, \$periodEnd\]\)/',
    $payrollSvc
) === 1;
$checks['Bootstrap: PayrollService wiring includes SalesTenantScope::class'] = str_contains($registerPayroll, 'PayrollService::class')
    && str_contains($registerPayroll, 'SalesTenantScope::class');
$checks['PayrollCommissionLineRepository still uses payrollRunBranchOrgExistsClause'] = substr_count($payrollLines, 'payrollRunBranchOrgExistsClause') >= 2;
$checks['PayrollRunRepository still uses payrollRunBranchOrgExistsClause'] = str_contains($payrollRuns, 'payrollRunBranchOrgExistsClause');
$checks['PayrollRunController calls calculateRun on service'] = str_contains($runCtl, 'calculateRun');

$weak = [
    'Payments subquery (pm) aggregates all completed payments globally; outer invoice scope + join to i.id limits rows to in-org invoices (no per-payment org column on subquery).',
    'Invoices with NULL i.branch_id no longer satisfy invoiceClause (i.branch_id IS NOT NULL + org EXISTS); legacy null-branch paid rows will not produce commission events.',
];

echo PHP_EOL . 'REMAINING / DOCUMENTED EDGE FLAGS' . PHP_EOL;
foreach ($weak as $w) {
    echo '- ' . $w . PHP_EOL;
}

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

echo PHP_EOL . 'All payroll invoice/payment tenant-guard static checks passed.' . PHP_EOL;
exit(0);

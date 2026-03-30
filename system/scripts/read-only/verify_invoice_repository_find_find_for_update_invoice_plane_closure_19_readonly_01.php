<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-19 (**FND-TNT-30**): static proof for
 * {@see \Modules\Sales\Repositories\InvoiceRepository::find} and
 * {@see \Modules\Sales\Repositories\InvoiceRepository::findForUpdate} explicit branch-derived invoice-plane entry
 * ({@see \Modules\Sales\Services\SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane}) before SQL +
 * {@see \Modules\Sales\Services\SalesTenantScope::invoiceClause} on alias {@code i}.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/InvoiceRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['InvoiceRepository: find() requires branch-derived invoice plane before SQL'] =
    preg_match(
        '/function\s+find\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['InvoiceRepository: find() still applies invoiceClause(i)'] =
    preg_match('/function\s+find\s*\([\s\S]*?tenantScope->invoiceClause\s*\(\s*[\'"]i[\'"]\s*\)/', $src) === 1;

$checks['InvoiceRepository: findForUpdate() requires branch-derived invoice plane before FOR UPDATE SQL'] =
    preg_match(
        '/function\s+findForUpdate\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['InvoiceRepository: findForUpdate() still applies invoiceClause(i)'] =
    preg_match('/function\s+findForUpdate\s*\([\s\S]*?tenantScope->invoiceClause\s*\(\s*[\'"]i[\'"]\s*\)/', $src) === 1;

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

echo PHP_EOL . 'InvoiceRepository::find / findForUpdate invoice-plane closure (CLOSURE-19) checks passed.' . PHP_EOL;
exit(0);

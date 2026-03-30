<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-17 (**FND-TNT-28**): static proof for
 * {@see \Modules\Sales\Repositories\InvoiceRepository::count} branch-derived invoice-plane guard
 * and conditional {@code clients} join.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/InvoiceRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['InvoiceRepository: count() requires branch-derived invoice plane before SQL'] =
    preg_match(
        '/function\s+count\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['InvoiceRepository: count() still applies invoiceClause(i)'] =
    preg_match('/function\s+count\s*\([\s\S]*?tenantScope->invoiceClause\s*\(\s*[\'"]i[\'"]\s*\)/', $src) === 1;

$checks['InvoiceRepository: count() joins clients only when name/phone filters need c.*'] =
    str_contains($src, 'invoiceListRequiresClientsJoinForFilters')
    && str_contains($src, '!empty($filters[\'client_name\'])')
    && str_contains($src, '!empty($filters[\'client_phone\'])')
    && str_contains($src, 'LEFT JOIN clients c ON c.id = i.client_id')
    && str_contains($src, 'FROM invoices i')
    && preg_match('/function\s+count\s*\([\s\S]*?\$joinClients[\s\S]*?LEFT JOIN clients c/s', $src) === 1;

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

echo PHP_EOL . 'InvoiceRepository::count invoice-plane closure (CLOSURE-17) checks passed.' . PHP_EOL;
exit(0);

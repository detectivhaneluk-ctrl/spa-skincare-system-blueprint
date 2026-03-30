<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-18 (**FND-TNT-29**): static proof for
 * {@see \Modules\Sales\Repositories\InvoiceRepository::list} parity with {@see \Modules\Sales\Repositories\InvoiceRepository::count}
 * (branch-derived guard + {@see invoiceListRequiresClientsJoinForFilters}).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/InvoiceRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['InvoiceRepository: list() requires branch-derived invoice plane before SQL'] =
    preg_match(
        '/function\s+list\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['InvoiceRepository: list() still applies invoiceClause(i)'] =
    preg_match('/function\s+list\s*\([\s\S]*?tenantScope->invoiceClause\s*\(\s*[\'"]i[\'"]\s*\)/', $src) === 1;

$checks['InvoiceRepository: list() uses invoiceListRequiresClientsJoinForFilters + JOIN path when client filters'] =
    preg_match(
        '/function\s+list\s*\([\s\S]*?invoiceListRequiresClientsJoinForFilters\s*\(\s*\$filters\s*\)[\s\S]*?LEFT JOIN clients c ON c\.id = i\.client_id/s',
        $src
    ) === 1;

$checks['InvoiceRepository: list() uses subselect client names when join elided (parity with count)'] =
    str_contains($src, 'FROM clients cj WHERE cj.id = i.client_id LIMIT 1) AS client_first_name')
    && str_contains($src, 'FROM clients cj WHERE cj.id = i.client_id LIMIT 1) AS client_last_name');

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

echo PHP_EOL . 'InvoiceRepository::list invoice-plane closure (CLOSURE-18) checks passed.' . PHP_EOL;
exit(0);

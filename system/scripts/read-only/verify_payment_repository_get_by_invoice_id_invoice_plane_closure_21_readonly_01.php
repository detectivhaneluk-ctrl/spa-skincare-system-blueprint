<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-21 (**FND-TNT-32**): static proof for
 * {@see \Modules\Sales\Repositories\PaymentRepository::getByInvoiceId} explicit branch-derived invoice-plane entry
 * ({@see \Modules\Sales\Services\SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane}) before SQL +
 * {@see \Modules\Sales\Services\SalesTenantScope::paymentByInvoiceExistsClause} on {@code p} / {@code si}.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/PaymentRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['PaymentRepository: getByInvoiceId() requires branch-derived invoice plane before SQL'] =
    preg_match(
        '/function\s+getByInvoiceId\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: getByInvoiceId() still applies paymentByInvoiceExistsClause(p, si)'] =
    preg_match(
        '/function\s+getByInvoiceId\s*\([\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: getByInvoiceId() keeps ORDER BY p.created_at'] =
    preg_match(
        '/function\s+getByInvoiceId\s*\([\s\S]*?ORDER BY p\.created_at/s',
        $src
    ) === 1;

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

echo PHP_EOL . 'PaymentRepository::getByInvoiceId invoice-plane closure (CLOSURE-21) checks passed.' . PHP_EOL;
exit(0);

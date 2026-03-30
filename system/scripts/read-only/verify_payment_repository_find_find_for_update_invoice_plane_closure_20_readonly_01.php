<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-20 (**FND-TNT-31**): static proof for
 * {@see \Modules\Sales\Repositories\PaymentRepository::find} and
 * {@see \Modules\Sales\Repositories\PaymentRepository::findForUpdate} explicit branch-derived invoice-plane entry
 * ({@see \Modules\Sales\Services\SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane}) before SQL +
 * {@see \Modules\Sales\Services\SalesTenantScope::paymentByInvoiceExistsClause} on {@code p} / {@code si}.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/PaymentRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['PaymentRepository: find() requires branch-derived invoice plane before SQL'] =
    preg_match(
        '/function\s+find\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: find() still applies paymentByInvoiceExistsClause(p, si)'] =
    preg_match('/function\s+find\s*\([\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/', $src) === 1;

$checks['PaymentRepository: findForUpdate() requires branch-derived invoice plane before FOR UPDATE SQL'] =
    preg_match(
        '/function\s+findForUpdate\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: findForUpdate() still applies paymentByInvoiceExistsClause(p, si)'] =
    preg_match('/function\s+findForUpdate\s*\([\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/', $src) === 1;

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

echo PHP_EOL . 'PaymentRepository::find / findForUpdate invoice-plane closure (CLOSURE-20) checks passed.' . PHP_EOL;
exit(0);

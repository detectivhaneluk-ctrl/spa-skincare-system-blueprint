<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-22: static proof for
 * {@see \Modules\Sales\Repositories\PaymentRepository::getCompletedTotalByInvoiceIdInInvoicePlane} explicit branch-derived invoice-plane entry
 * ({@see \Modules\Sales\Services\SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane}) before SQL +
 * {@see \Modules\Sales\Services\SalesTenantScope::paymentByInvoiceExistsClause} on {@code p} / {@code si}.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_payment_repository_get_completed_total_by_invoice_id_invoice_plane_closure_22_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/PaymentRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['PaymentRepository: getCompletedTotalByInvoiceId() is locked through the central guard'] =
    preg_match(
        '/function\s+getCompletedTotalByInvoiceId\s*\([\s\S]*?denyMixedSemanticsApi\s*\(\s*[\'"]PaymentRepository::getCompletedTotalByInvoiceId[\'"]\s*,\s*\[[^\]]*getCompletedTotalByInvoiceIdInInvoicePlane/s',
        $src
    ) === 1;

$checks['PaymentRepository: getCompletedTotalByInvoiceIdInInvoicePlane() requires branch-derived invoice plane before SQL'] =
    preg_match(
        '/function\s+getCompletedTotalByInvoiceIdInInvoicePlane\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: getCompletedTotalByInvoiceIdInInvoicePlane() still applies paymentByInvoiceExistsClause(p, si)'] =
    preg_match(
        '/function\s+getCompletedTotalByInvoiceIdInInvoicePlane\s*\([\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: getCompletedTotalByInvoiceIdInInvoicePlane() keeps signed net CASE (refund negates amount)'] =
    preg_match(
        '/function\s+getCompletedTotalByInvoiceIdInInvoicePlane\s*\([\s\S]*?WHEN p\.entry_type = [\'"]refund[\'"] THEN -p\.amount/s',
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

echo PHP_EOL . 'PaymentRepository::getCompletedTotalByInvoiceIdInInvoicePlane closure (CLOSURE-22) checks passed.' . PHP_EOL;
exit(0);

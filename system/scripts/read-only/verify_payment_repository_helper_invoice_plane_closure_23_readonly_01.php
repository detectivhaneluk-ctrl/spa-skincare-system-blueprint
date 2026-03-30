<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-23: static proof for
 * PaymentRepository helper trio parity with the explicit branch-derived invoice-plane entry used by
 * find/findForUpdate/getByInvoiceId/getCompletedTotalByInvoiceId.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_payment_repository_helper_invoice_plane_closure_23_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/PaymentRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['PaymentRepository: existsCompletedByInvoiceAndReference() is locked through the central guard'] =
    preg_match(
        '/function\s+existsCompletedByInvoiceAndReference\s*\([\s\S]*?denyMixedSemanticsApi\s*\(\s*[\'"]PaymentRepository::existsCompletedByInvoiceAndReference[\'"]\s*,\s*\[[^\]]*existsCompletedByInvoiceAndReferenceInInvoicePlane/s',
        $src
    ) === 1;

$checks['PaymentRepository: existsCompletedByInvoiceAndReferenceInInvoicePlane() requires branch-derived invoice plane before paymentByInvoiceExistsClause()'] =
    preg_match(
        '/function\s+existsCompletedByInvoiceAndReferenceInInvoicePlane\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)\s*;[\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: getCompletedRefundedTotalForParentPayment() is locked through the central guard'] =
    preg_match(
        '/function\s+getCompletedRefundedTotalForParentPayment\s*\([\s\S]*?denyMixedSemanticsApi\s*\(\s*[\'"]PaymentRepository::getCompletedRefundedTotalForParentPayment[\'"]\s*,\s*\[[^\]]*getCompletedRefundedTotalForParentPaymentInInvoicePlane/s',
        $src
    ) === 1;

$checks['PaymentRepository: getCompletedRefundedTotalForParentPaymentInInvoicePlane() requires branch-derived invoice plane before paymentByInvoiceExistsClause()'] =
    preg_match(
        '/function\s+getCompletedRefundedTotalForParentPaymentInInvoicePlane\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)\s*;[\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: hasCompletedRefundForInvoice() is locked through the central guard'] =
    preg_match(
        '/function\s+hasCompletedRefundForInvoice\s*\([\s\S]*?denyMixedSemanticsApi\s*\(\s*[\'"]PaymentRepository::hasCompletedRefundForInvoice[\'"]\s*,\s*\[[^\]]*hasCompletedRefundForInvoiceInInvoicePlane/s',
        $src
    ) === 1;

$checks['PaymentRepository: hasCompletedRefundForInvoiceInInvoicePlane() requires branch-derived invoice plane before paymentByInvoiceExistsClause()'] =
    preg_match(
        '/function\s+hasCompletedRefundForInvoiceInInvoicePlane\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)\s*;[\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: helper trio count of explicit branch-derived invoice-plane entries is exactly 3'] =
    preg_match_all(
        '/function\s+(existsCompletedByInvoiceAndReferenceInInvoicePlane|getCompletedRefundedTotalForParentPaymentInInvoicePlane|hasCompletedRefundForInvoiceInInvoicePlane)\s*\([\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 3;

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

echo PHP_EOL . 'PaymentRepository helper invoice-plane closure (CLOSURE-23) checks passed.' . PHP_EOL;
exit(0);

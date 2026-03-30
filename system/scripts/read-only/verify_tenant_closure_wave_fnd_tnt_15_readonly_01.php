<?php

declare(strict_types=1);

/**
 * FND-TNT-15 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-09
 * Anonymous public commerce: invoice reads use branch-correlated path (no session branch-derived org required);
 * reconciler/recovery fall back only on AccessDeniedException.
 */

$root = dirname(__DIR__, 3);
$invRepo = (string) file_get_contents($root . '/system/modules/sales/repositories/InvoiceRepository.php');
$pcRepo = (string) file_get_contents($root . '/system/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php');
$svc = (string) file_get_contents($root . '/system/modules/public-commerce/services/PublicCommerceService.php');
$recon = (string) file_get_contents($root . '/system/modules/public-commerce/services/PublicCommerceFulfillmentReconciler.php');
$recovery = (string) file_get_contents($root . '/system/modules/public-commerce/services/PublicCommerceFulfillmentReconcileRecoveryService.php');

$ok = true;
if (!str_contains($invRepo, 'function findForPublicCommerceCorrelatedBranch(')) {
    fwrite(STDERR, "FAIL: InvoiceRepository missing findForPublicCommerceCorrelatedBranch.\n");
    $ok = false;
}
if (!str_contains($invRepo, 'AND i.branch_id = ?') || !str_contains($invRepo, 'findForPublicCommerceCorrelatedBranch')) {
    fwrite(STDERR, "FAIL: findForPublicCommerceCorrelatedBranch must pin i.branch_id.\n");
    $ok = false;
}
if (!str_contains($pcRepo, 'function findBranchIdPinByInvoiceId(')) {
    fwrite(STDERR, "FAIL: PublicCommercePurchaseRepository missing findBranchIdPinByInvoiceId.\n");
    $ok = false;
}
if (!str_contains($svc, 'findForPublicCommerceCorrelatedBranch($invoiceId, $branchId)')) {
    fwrite(STDERR, "FAIL: PublicCommerceService must use findForPublicCommerceCorrelatedBranch with purchase/initiate branch.\n");
    $ok = false;
}
if (preg_match('/function\s+finalizePurchase\b[\s\S]*?invoiceRepo->find\(/s', $svc) === 1) {
    fwrite(STDERR, "FAIL: finalizePurchase must not call invoiceRepo->find for invoice load.\n");
    $ok = false;
}
if (preg_match('/function\s+getPurchaseStatus\b[\s\S]*?invoiceRepo->find\(/s', $svc) === 1) {
    fwrite(STDERR, "FAIL: getPurchaseStatus must not call invoiceRepo->find for invoice load.\n");
    $ok = false;
}
if (!str_contains($recon, 'loadInvoiceRowForPublicCommerceReconcile')) {
    fwrite(STDERR, "FAIL: PublicCommerceFulfillmentReconciler must use loadInvoiceRowForPublicCommerceReconcile.\n");
    $ok = false;
}
if (!str_contains($recon, 'catch (AccessDeniedException)')) {
    fwrite(STDERR, "FAIL: reconciler invoice loader must catch AccessDeniedException for anonymous fallback.\n");
    $ok = false;
}
if (!str_contains($recovery, 'catch (AccessDeniedException)')) {
    fwrite(STDERR, "FAIL: recovery service must catch AccessDeniedException for invoice load.\n");
    $ok = false;
}

exit($ok ? 0 : 1);

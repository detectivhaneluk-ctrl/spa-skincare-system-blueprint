<?php

declare(strict_types=1);

/**
 * FND-TNT-09 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-03 refund-review lists + invoice-branch definition catalog.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$failed = false;

$saleRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipSaleRepository.php');
if (!str_contains($saleRepo, 'function listRefundReview(')) {
    fwrite(STDERR, "FAIL: MembershipSaleRepository::listRefundReview missing.\n");
    $failed = true;
}
if (!str_contains($saleRepo, 'resolvedOrganizationId()')) {
    fwrite(STDERR, "FAIL: listRefundReview must gate on resolvedOrganizationId.\n");
    $failed = true;
}
if (!str_contains($saleRepo, 'membershipSalesRefundReviewOrganizationBinding')) {
    fwrite(STDERR, "FAIL: expected membershipSalesRefundReviewOrganizationBinding helper.\n");
    $failed = true;
}
if (preg_match("/SELECT\s*\*\s+FROM\s+membership_sales\s+WHERE\s+status\s*=\s*'refund_review'/i", $saleRepo) === 1) {
    fwrite(STDERR, "FAIL: raw SELECT * FROM membership_sales WHERE status = refund_review must not remain.\n");
    $failed = true;
}
if (!str_contains($saleRepo, 'LEFT JOIN invoices i ON i.id = ms.invoice_id')) {
    fwrite(STDERR, "FAIL: listRefundReview must LEFT JOIN invoices for null-branch org proof.\n");
    $failed = true;
}

$cycleRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipBillingCycleRepository.php');
if (!str_contains($cycleRepo, 'function listRefundReviewQueue(')) {
    fwrite(STDERR, "FAIL: MembershipBillingCycleRepository::listRefundReviewQueue missing.\n");
    $failed = true;
}
if (!str_contains($cycleRepo, 'billingCycleRefundReviewOrganizationBinding')) {
    fwrite(STDERR, "FAIL: expected billingCycleRefundReviewOrganizationBinding helper.\n");
    $failed = true;
}
if (substr_count($cycleRepo, 'resolvedOrganizationId()') < 1) {
    fwrite(STDERR, "FAIL: listRefundReviewQueue must use resolvedOrganizationId.\n");
    $failed = true;
}

$defRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipDefinitionRepository.php');
if (!str_contains($defRepo, 'function listActiveForInvoiceBranch(')) {
    fwrite(STDERR, "FAIL: MembershipDefinitionRepository::listActiveForInvoiceBranch missing.\n");
    $failed = true;
}
if (!str_contains($defRepo, "branchColumnOwnedByResolvedOrganizationExistsClause('md')")
    || !preg_match('/function\s+listActiveForInvoiceBranch[\s\S]*?branchColumnOwnedByResolvedOrganizationExistsClause\s*\(\s*[\'"]md[\'"]\s*\)/', $defRepo)) {
    fwrite(STDERR, "FAIL: listActiveForInvoiceBranch must use branchColumnOwnedByResolvedOrganizationExistsClause('md').\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "verify_tenant_closure_wave_fnd_tnt_09_readonly_01: OK\n";
exit(0);

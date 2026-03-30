<?php

declare(strict_types=1);

/**
 * FND-TNT-08 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-02 Tier B membership_sales read/lock paths.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$path = $root . '/system/modules/memberships/Repositories/MembershipSaleRepository.php';
$body = (string) file_get_contents($path);

$failed = false;

foreach (
    [
        'find(int $id)' => [
            'SELECT ms.* FROM membership_sales ms WHERE ms.id = ?',
            'branchColumnOwnedByResolvedOrganizationExistsClause',
        ],
        'findForUpdate(int $id)' => [
            'SELECT ms.* FROM membership_sales ms WHERE ms.id = ?',
            'FOR UPDATE',
            'branchColumnOwnedByResolvedOrganizationExistsClause',
        ],
    ] as $label => $needles
) {
    foreach ($needles as $n) {
        if (!str_contains($body, $n)) {
            fwrite(STDERR, "FAIL {$label}: expected anchor not found: {$n}\n");
            $failed = true;
        }
    }
}

if (preg_match('/SELECT\s*\*\s+FROM\s+membership_sales\s+WHERE\s+id\s*=\s*\?/i', $body) === 1) {
    fwrite(STDERR, "FAIL: unqualified SELECT * FROM membership_sales WHERE id = ? must not remain.\n");
    $failed = true;
}

if (!str_contains($body, 'findBlockingOpenInitialSale')) {
    fwrite(STDERR, "FAIL: findBlockingOpenInitialSale missing.\n");
    $failed = true;
}
if (!str_contains($body, 'INNER JOIN invoices i ON i.id = ms.invoice_id') || !str_contains($body, 'invoicePlaneExistsClauseForMembershipReconcileQueries')) {
    fwrite(STDERR, "FAIL: findBlockingOpenInitialSale null-branch path must invoice-join + invoicePlane clause.\n");
    $failed = true;
}

$msOrgClauseCount = substr_count($body, "branchColumnOwnedByResolvedOrganizationExistsClause('ms')");
if ($msOrgClauseCount < 5) {
    fwrite(STDERR, "FAIL: expected at least 5 branchColumnOwnedByResolvedOrganizationExistsClause('ms') in repository (got {$msOrgClauseCount}).\n");
    $failed = true;
}

$svc = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipSaleService.php');
if (!str_contains($svc, 'operatorReevaluateRefundReviewSale(int $saleId, int $branchId)')) {
    fwrite(STDERR, "FAIL: MembershipSaleService must pass branch into operator reevaluate.\n");
    $failed = true;
}
if (!str_contains($svc, 'findForUpdateInTenantScope($saleId, $branchId)') || !str_contains($svc, 'findInTenantScope($saleId, $branchId)')) {
    fwrite(STDERR, "FAIL: operator path must use findForUpdateInTenantScope + findInTenantScope.\n");
    $failed = true;
}
if (!str_contains($svc, 'findForUpdateInTenantScope($saleId, $invBranch)')) {
    fwrite(STDERR, "FAIL: settlement paths should use invoice-branch findForUpdateInTenantScope when branch > 0.\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "verify_tenant_closure_wave_fnd_tnt_08_readonly_01: OK\n";
exit(0);

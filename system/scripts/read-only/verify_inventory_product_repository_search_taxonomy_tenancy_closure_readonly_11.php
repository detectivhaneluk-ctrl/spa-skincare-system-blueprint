<?php

declare(strict_types=1);

/**
 * FND-TNT-19 — static proof: FOUNDATION-INVENTORY-RESIDUAL-TENANCY-CLOSURE-11
 * ProductRepository: tenant list/count search + taxonomy filters use taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause (not weak pc/pb pairing).
 */

$root = dirname(__DIR__, 3);
$path = $root . '/system/modules/inventory/repositories/ProductRepository.php';
$text = (string) file_get_contents($path);

$ok = true;

if (!str_contains($text, "genericSearchConditionForAlias('p', \$q, \$branchId)")) {
    fwrite(STDERR, "FAIL: listInTenantScope/countInTenantScope must pass operation branch into genericSearchConditionForAlias.\n");
    $ok = false;
}

if (substr_count($text, 'taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause(') < 4) {
    fwrite(STDERR, "FAIL: ProductRepository should use taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause for tenant search + both taxonomy filters (list + count).\n");
    $ok = false;
}

if (str_contains($text, 'AND (pc.branch_id IS NULL OR pc.branch_id = {$a}.branch_id)')) {
    fwrite(STDERR, "FAIL: genericSearchConditionForAlias must not use weak pc.branch_id pairing; use taxonomy union.\n");
    $ok = false;
}

if (str_contains($text, 'AND (pb.branch_id IS NULL OR pb.branch_id = {$a}.branch_id)')) {
    fwrite(STDERR, "FAIL: genericSearchConditionForAlias must not use weak pb.branch_id pairing; use taxonomy union.\n");
    $ok = false;
}

exit($ok ? 0 : 1);

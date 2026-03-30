<?php

declare(strict_types=1);

/**
 * FND-TNT-17 — static proof: FOUNDATION-INVENTORY-RESIDUAL-TENANCY-CLOSURE-09
 * ProductBrandRepository: duplicate-name family uses resolved-tenant taxonomy union (not raw branch_id/NULL + TRIM only).
 */

$root = dirname(__DIR__, 3);
$brandRepoPath = $root . '/system/modules/inventory/repositories/ProductBrandRepository.php';
$brandRepo = (string) file_get_contents($brandRepoPath);

$ok = true;

if (!str_contains($brandRepo, 'whereLiveTrimmedNameInResolvedTenantCatalogScope')) {
    fwrite(STDERR, "FAIL: ProductBrandRepository must define whereLiveTrimmedNameInResolvedTenantCatalogScope.\n");
    $ok = false;
}

$needUnion = 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause';
foreach ([
    'findCanonicalLiveByScopeAndTrimmedName',
    'findOtherLiveByScopeAndTrimmedName',
    'countLiveByScopeAndTrimmedName',
    'listLiveIdsByScopeAndTrimmedName',
] as $fn) {
    $pos = strpos($brandRepo, 'function ' . $fn);
    if ($pos === false) {
        fwrite(STDERR, "FAIL: missing function {$fn}.\n");
        $ok = false;
        continue;
    }
    $next = strpos($brandRepo, "\n    public function ", $pos + 1);
    $chunk = $next === false ? substr($brandRepo, $pos) : substr($brandRepo, $pos, $next - $pos);
    if (!str_contains($chunk, $needUnion) && !str_contains($chunk, 'whereLiveTrimmedNameInResolvedTenantCatalogScope')) {
        fwrite(STDERR, "FAIL: {$fn} must use tenant catalog union or whereLiveTrimmedNameInResolvedTenantCatalogScope.\n");
        $ok = false;
    }
}

if (preg_match('/branch_id IS NULL AND TRIM\\(name\\)/', $brandRepo) === 1) {
    fwrite(STDERR, "FAIL: ProductBrandRepository must not use raw branch_id IS NULL AND TRIM(name).\n");
    $ok = false;
}

exit($ok ? 0 : 1);

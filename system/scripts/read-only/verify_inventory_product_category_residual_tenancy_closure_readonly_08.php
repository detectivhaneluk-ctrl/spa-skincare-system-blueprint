<?php

declare(strict_types=1);

/**
 * FND-TNT-16 — static proof: FOUNDATION-INVENTORY-RESIDUAL-TENANCY-CLOSURE-08
 * ProductCategoryRepository: duplicate-name + parent/child repair paths use resolved-tenant taxonomy union (not raw branch_id/NULL only).
 */

$root = dirname(__DIR__, 3);
$catRepoPath = $root . '/system/modules/inventory/repositories/ProductCategoryRepository.php';
$catRepo = (string) file_get_contents($catRepoPath);

$ok = true;

if (!str_contains($catRepo, 'whereLiveTrimmedNameInResolvedTenantCatalogScope')) {
    fwrite(STDERR, "FAIL: ProductCategoryRepository must define whereLiveTrimmedNameInResolvedTenantCatalogScope for duplicate-name tenancy.\n");
    $ok = false;
}
if (!str_contains($catRepo, 'whereLiveChildOfParentInResolvedTenantCatalogScope')) {
    fwrite(STDERR, "FAIL: ProductCategoryRepository must define whereLiveChildOfParentInResolvedTenantCatalogScope for child listing/counts.\n");
    $ok = false;
}

$needUnion = 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause';
foreach ([
    'findCanonicalLiveByScopeAndTrimmedName' => 'findCanonicalLiveByScopeAndTrimmedName',
    'clearChildParentLinks' => 'clearChildParentLinks',
    'countLiveChildCategoriesWithParentId' => 'countLiveChildCategoriesWithParentId',
] as $needle => $_) {
    $pos = strpos($catRepo, 'function ' . $needle);
    if ($pos === false) {
        fwrite(STDERR, "FAIL: missing function {$needle}.\n");
        $ok = false;
        continue;
    }
    $next = strpos($catRepo, "\n    public function ", $pos + 1);
    $chunk = $next === false ? substr($catRepo, $pos) : substr($catRepo, $pos, $next - $pos);
    if (!str_contains($chunk, $needUnion) && !str_contains($chunk, 'whereLiveTrimmedNameInResolvedTenantCatalogScope') && !str_contains($chunk, 'whereLiveChildOfParentInResolvedTenantCatalogScope')) {
        fwrite(STDERR, "FAIL: {$needle} must use tenant catalog union or the private where* helpers.\n");
        $ok = false;
    }
}

// Root-cause guard: no resurrected hand-rolled duplicate-name SQL (installation-wide null bucket).
if (preg_match('/branch_id IS NULL AND TRIM\\(name\\)/', $catRepo) === 1) {
    fwrite(STDERR, "FAIL: ProductCategoryRepository must not use raw branch_id IS NULL AND TRIM(name) (use union-backed predicates).\n");
    $ok = false;
}

exit($ok ? 0 : 1);

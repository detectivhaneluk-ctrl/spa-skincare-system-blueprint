<?php

declare(strict_types=1);

/**
 * ROOT-02-INVENTORY-CATALOG-SEMANTIC-NORMALIZATION-01
 *
 * Static proof for ProductRepository catalog semantics:
 * - Runtime entry points are explicit tenant/resolved-org methods.
 * - Legacy weak catalog/read helpers are locked fail-closed.
 * - Repair/control-plane methods are explicit and honestly named.
 * - Runtime module callers do not use weak ProductRepository methods.
 *
 * Usage (from repository root):
 *   php system/scripts/read-only/verify_root_02_inventory_catalog_semantic_normalization_01.php
 */

$root = dirname(__DIR__, 3);
$system = $root . '/system';
$modules = $system . '/modules';
$repoPath = $modules . '/inventory/repositories/ProductRepository.php';

if (!is_file($repoPath)) {
    fwrite(STDERR, "FAIL: missing ProductRepository.php at {$repoPath}\n");
    exit(1);
}

$repo = (string) file_get_contents($repoPath);

/**
 * @return string|null
 */
function extractMethodBody(string $source, string $methodName): ?string
{
    $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::\s*[^{\s]+)?\s*\{([\s\S]*?)\n    \}/';
    if (preg_match($pattern, $source, $m) !== 1) {
        return null;
    }

    return $m[1];
}

function methodLocked(?string $methodBody): bool
{
    return $methodBody !== null
        && str_contains($methodBody, 'throw new \LogicException(')
        && str_contains($methodBody, 'locked');
}

$listWeak = extractMethodBody($repo, 'list');
$countWeak = extractMethodBody($repo, 'count');
$unifiedWeak = extractMethodBody($repo, 'listActiveForUnifiedCatalog');
$findWeak = extractMethodBody($repo, 'find');
$findLockedWeak = extractMethodBody($repo, 'findLocked');
$backfillWeak = extractMethodBody($repo, 'listNonDeletedForTaxonomyBackfill');
$countAllWeak = extractMethodBody($repo, 'countNonDeletedProducts');
$orphanCatCountWeak = extractMethodBody($repo, 'countActiveWithOrphanProductCategoryFk');
$orphanBrandCountWeak = extractMethodBody($repo, 'countActiveWithOrphanProductBrandFk');
$orphanCatScopeWeak = extractMethodBody($repo, 'listOrphanProductCategoryFkCountsByProductBranch');
$orphanBrandScopeWeak = extractMethodBody($repo, 'listOrphanProductBrandFkCountsByProductBranch');
$orphanCatExamplesWeak = extractMethodBody($repo, 'listOrphanProductCategoryFkExamples');
$orphanBrandExamplesWeak = extractMethodBody($repo, 'listOrphanProductBrandFkExamples');

$listRepair = extractMethodBody($repo, 'listUnscopedCatalogForRepair');
$countRepair = extractMethodBody($repo, 'countUnscopedCatalogForRepair');
$unifiedRepair = extractMethodBody($repo, 'listActiveForUnifiedCatalogUnscopedForRepair');
$findRepair = extractMethodBody($repo, 'findUnscopedByIdForRepair');
$findLockedRepair = extractMethodBody($repo, 'findLockedUnscopedForRepair');
$backfillRepair = extractMethodBody($repo, 'listNonDeletedForTaxonomyBackfillUnscopedForRepair');
$countAllRepair = extractMethodBody($repo, 'countNonDeletedProductsUnscopedForRepair');
$orphanCatCountRepair = extractMethodBody($repo, 'countActiveWithOrphanProductCategoryFkUnscopedForRepair');
$orphanBrandCountRepair = extractMethodBody($repo, 'countActiveWithOrphanProductBrandFkUnscopedForRepair');
$orphanCatScopeRepair = extractMethodBody($repo, 'listOrphanProductCategoryFkCountsByProductBranchUnscopedForRepair');
$orphanBrandScopeRepair = extractMethodBody($repo, 'listOrphanProductBrandFkCountsByProductBranchUnscopedForRepair');
$orphanCatExamplesRepair = extractMethodBody($repo, 'listOrphanProductCategoryFkExamplesUnscopedForRepair');
$orphanBrandExamplesRepair = extractMethodBody($repo, 'listOrphanProductBrandFkExamplesUnscopedForRepair');

$listTenant = extractMethodBody($repo, 'listInTenantScope');
$countTenant = extractMethodBody($repo, 'countInTenantScope');
$findReadableResolved = extractMethodBody($repo, 'findReadableForStockMutationInResolvedOrg');
$findLockedResolved = extractMethodBody($repo, 'findLockedForStockMutationInResolvedOrg');
$listResolvedUnion = extractMethodBody($repo, 'listActiveForUnifiedCatalogInResolvedOrg');
$listResolvedGlobal = extractMethodBody($repo, 'listActiveOrgGlobalCatalogInResolvedOrg');

$checks = [];
$failed = [];

$checks['Legacy weak ProductRepository read helpers are locked fail-closed'] =
    methodLocked($findWeak)
    && methodLocked($findLockedWeak)
    && methodLocked($listWeak)
    && methodLocked($countWeak)
    && methodLocked($unifiedWeak)
    && methodLocked($backfillWeak)
    && methodLocked($countAllWeak)
    && methodLocked($orphanCatCountWeak)
    && methodLocked($orphanBrandCountWeak)
    && methodLocked($orphanCatScopeWeak)
    && methodLocked($orphanBrandScopeWeak)
    && methodLocked($orphanCatExamplesWeak)
    && methodLocked($orphanBrandExamplesWeak);

$checks['Explicit unscoped repair helpers exist and keep legacy semantics isolated'] =
    $findRepair !== null
    && $findLockedRepair !== null
    && $listRepair !== null
    && $countRepair !== null
    && $unifiedRepair !== null
    && $backfillRepair !== null
    && $countAllRepair !== null
    && $orphanCatCountRepair !== null
    && $orphanBrandCountRepair !== null
    && $orphanCatScopeRepair !== null
    && $orphanBrandScopeRepair !== null
    && $orphanCatExamplesRepair !== null
    && $orphanBrandExamplesRepair !== null
    && str_contains($listRepair, 'SELECT * FROM products WHERE deleted_at IS NULL')
    && str_contains($countRepair, 'SELECT COUNT(*) AS c FROM products WHERE deleted_at IS NULL')
    && str_contains($unifiedRepair, 'SELECT * FROM products WHERE deleted_at IS NULL AND is_active = 1')
    && str_contains($backfillRepair, 'SELECT id, branch_id, category, brand, product_category_id, product_brand_id FROM products WHERE deleted_at IS NULL ORDER BY id ASC')
    && str_contains($countAllRepair, 'SELECT COUNT(*) AS c FROM products WHERE deleted_at IS NULL')
    && str_contains($orphanCatCountRepair, 'LEFT JOIN product_categories pc ON pc.id = p.product_category_id')
    && str_contains($orphanBrandCountRepair, 'LEFT JOIN product_brands pb ON pb.id = p.product_brand_id');

$checks['Tenant list/count fail closed for non-positive branch input'] =
    $listTenant !== null
    && $countTenant !== null
    && str_contains($listTenant, 'if ($branchId <= 0)')
    && str_contains($listTenant, 'return [];')
    && str_contains($countTenant, 'if ($branchId <= 0)')
    && str_contains($countTenant, 'return 0;');

$checks['Resolved-org stock mutation reads fail closed for non-positive operation branch'] =
    $findReadableResolved !== null
    && $findLockedResolved !== null
    && str_contains($findReadableResolved, 'if ($operationBranchId <= 0)')
    && str_contains($findReadableResolved, 'return null;')
    && str_contains($findLockedResolved, 'if ($operationBranchId <= 0)')
    && str_contains($findLockedResolved, 'return null;');

$checks['Tenant list/count use strict branch-owned resolved-org scope'] =
    $listTenant !== null
    && $countTenant !== null
    && str_contains($listTenant, "branchColumnOwnedByResolvedOrganizationExistsClause('p')")
    && str_contains($listTenant, 'p.branch_id = ?')
    && str_contains($countTenant, "branchColumnOwnedByResolvedOrganizationExistsClause('p')")
    && str_contains($countTenant, 'p.branch_id = ?');

$checks['Resolved-org branch-owned-or-org-global union is explicit'] =
    $listResolvedUnion !== null
    && str_contains($listResolvedUnion, 'if ($operationBranchId <= 0)')
    && str_contains($listResolvedUnion, 'return [];')
    && str_contains($listResolvedUnion, 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause');

$checks['Resolved-org org-global-only catalog is explicit and org-anchored'] =
    $listResolvedGlobal !== null
    && str_contains($listResolvedGlobal, 'resolvedTenantOrganizationHasLiveBranchExistsClause')
    && str_contains($listResolvedGlobal, 'p.branch_id IS NULL');

$runtimeCallerFiles = [
    $modules . '/inventory/controllers/ProductController.php',
    $modules . '/inventory/controllers/InventoryCountController.php',
    $modules . '/inventory/controllers/StockMovementController.php',
    $modules . '/sales/services/CashierWorkspaceViewDataBuilder.php',
    $modules . '/sales/providers/CatalogSellableReadModelProviderImpl.php',
];

$runtimeText = '';
$runtimeByFile = [];
foreach ($runtimeCallerFiles as $f) {
    if (!is_file($f)) {
        $checks['Runtime caller files exist for static caller map'] = false;
        break;
    }
    $text = (string) file_get_contents($f);
    $runtimeByFile[$f] = $text;
    $runtimeText .= "\n" . $text;
}
if (!isset($checks['Runtime caller files exist for static caller map'])) {
    $checks['Runtime caller files exist for static caller map'] = true;
}

$checks['Runtime callers use canonical ProductRepository methods'] =
    str_contains($runtimeText, '->findInTenantScope(')
    && str_contains($runtimeText, '->listInTenantScope(')
    && str_contains($runtimeText, '->countInTenantScope(')
    && str_contains($runtimeText, '->listActiveForUnifiedCatalogInResolvedOrg(')
    && str_contains($runtimeText, '->listActiveOrgGlobalCatalogInResolvedOrg(');

$checks['Runtime caller map does not use weak ProductRepository methods'] =
    preg_match('/\$this->repo->(?:find|findLocked|list|count|listActiveForUnifiedCatalog|listNonDeletedForTaxonomyBackfill|countNonDeletedProducts|countActiveWithOrphanProductCategoryFk|countActiveWithOrphanProductBrandFk|listOrphanProductCategoryFkCountsByProductBranch|listOrphanProductBrandFkCountsByProductBranch|listOrphanProductCategoryFkExamples|listOrphanProductBrandFkExamples)\s*\(/', $runtimeByFile[$runtimeCallerFiles[0]] ?? '') !== 1
    && preg_match('/\$this->productRepo->(?:find|findLocked|list|count|listActiveForUnifiedCatalog|listNonDeletedForTaxonomyBackfill|countNonDeletedProducts|countActiveWithOrphanProductCategoryFk|countActiveWithOrphanProductBrandFk|listOrphanProductCategoryFkCountsByProductBranch|listOrphanProductBrandFkCountsByProductBranch|listOrphanProductCategoryFkExamples|listOrphanProductBrandFkExamples)\s*\(/', $runtimeByFile[$runtimeCallerFiles[1]] ?? '') !== 1
    && preg_match('/\$this->productRepo->(?:find|findLocked|list|count|listActiveForUnifiedCatalog|listNonDeletedForTaxonomyBackfill|countNonDeletedProducts|countActiveWithOrphanProductCategoryFk|countActiveWithOrphanProductBrandFk|listOrphanProductCategoryFkCountsByProductBranch|listOrphanProductBrandFkCountsByProductBranch|listOrphanProductCategoryFkExamples|listOrphanProductBrandFkExamples)\s*\(/', $runtimeByFile[$runtimeCallerFiles[2]] ?? '') !== 1
    && preg_match('/\$this->productRepository->(?:find|findLocked|list|count|listActiveForUnifiedCatalog|listNonDeletedForTaxonomyBackfill|countNonDeletedProducts|countActiveWithOrphanProductCategoryFk|countActiveWithOrphanProductBrandFk|listOrphanProductCategoryFkCountsByProductBranch|listOrphanProductBrandFkCountsByProductBranch|listOrphanProductCategoryFkExamples|listOrphanProductBrandFkExamples)\s*\(/', $runtimeByFile[$runtimeCallerFiles[3]] ?? '') !== 1
    && preg_match('/\$this->products->(?:find|findLocked|list|count|listActiveForUnifiedCatalog|listNonDeletedForTaxonomyBackfill|countNonDeletedProducts|countActiveWithOrphanProductCategoryFk|countActiveWithOrphanProductBrandFk|listOrphanProductCategoryFkCountsByProductBranch|listOrphanProductBrandFkCountsByProductBranch|listOrphanProductCategoryFkExamples|listOrphanProductBrandFkExamples)\s*\(/', $runtimeByFile[$runtimeCallerFiles[4]] ?? '') !== 1
    && !str_contains($runtimeText, '->listUnscopedCatalogForRepair(')
    && !str_contains($runtimeText, '->countUnscopedCatalogForRepair(')
    && !str_contains($runtimeText, '->listActiveForUnifiedCatalogUnscopedForRepair(');

/** @var list<string> $violations */
$violations = [];
$weakMethodNames = [
    'find',
    'findLocked',
    'list',
    'count',
    'listActiveForUnifiedCatalog',
    'listNonDeletedForTaxonomyBackfill',
    'countNonDeletedProducts',
    'countActiveWithOrphanProductCategoryFk',
    'countActiveWithOrphanProductBrandFk',
    'listOrphanProductCategoryFkCountsByProductBranch',
    'listOrphanProductBrandFkCountsByProductBranch',
    'listOrphanProductCategoryFkExamples',
    'listOrphanProductBrandFkExamples',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $fileInfo->getPathname());
    if ($path === str_replace('\\', '/', $repoPath)) {
        continue;
    }
    $text = (string) file_get_contents($fileInfo->getPathname());
    if ($text === '' || !str_contains($text, 'ProductRepository')) {
        continue;
    }
    preg_match_all('/ProductRepository\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $varsMatch);
    $vars = array_values(array_unique($varsMatch[1] ?? []));
    foreach ($vars as $var) {
        foreach ($weakMethodNames as $method) {
            if (preg_match('/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($method, '/') . '\s*\(/', $text) === 1
                || preg_match('/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($method, '/') . '\s*\(/', $text) === 1) {
                $violations[] = "{$path} uses weak ProductRepository::{$method}() via \${$var}";
            }
        }
    }
    if (preg_match('/ProductRepository::class\)\s*->\s*(find|findLocked|list|count|listActiveForUnifiedCatalog|listNonDeletedForTaxonomyBackfill|countNonDeletedProducts|countActiveWithOrphanProductCategoryFk|countActiveWithOrphanProductBrandFk|listOrphanProductCategoryFkCountsByProductBranch|listOrphanProductBrandFkCountsByProductBranch|listOrphanProductCategoryFkExamples|listOrphanProductBrandFkExamples)\s*\(/', $text, $m) === 1) {
        $violations[] = "{$path} uses weak ProductRepository::{$m[1]}() via container->get() chain";
    }
}

$checks['No tenant/runtime module file uses weak ProductRepository methods'] = ($violations === []);

foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, 'VIOLATION: ' . $v . PHP_EOL);
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_root_02_inventory_catalog_semantic_normalization_01: OK\n";
exit(0);

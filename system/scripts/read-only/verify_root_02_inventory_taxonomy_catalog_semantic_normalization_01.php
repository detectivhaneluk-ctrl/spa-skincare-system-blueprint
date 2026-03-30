<?php

declare(strict_types=1);

/**
 * ROOT-02-INVENTORY-TAXONOMY-CATALOG-SEMANTIC-NORMALIZATION-01
 *
 * Static proof:
 * - ProductCategoryRepository/ProductBrandRepository expose explicit runtime-safe taxonomy entry points.
 * - Legacy weak taxonomy read helpers are locked fail-closed.
 * - Repair/control-plane reads remain explicit and honestly named.
 * - Invalid runtime branch context fails closed.
 * - Runtime callers do not use weak taxonomy legacy methods.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_root_02_inventory_taxonomy_catalog_semantic_normalization_01.php
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';
$categoryRepoPath = $modules . '/inventory/repositories/ProductCategoryRepository.php';
$brandRepoPath = $modules . '/inventory/repositories/ProductBrandRepository.php';

if (!is_file($categoryRepoPath) || !is_file($brandRepoPath)) {
    fwrite(STDERR, "FAIL: missing taxonomy repository files.\n");
    exit(1);
}

$categoryRepo = (string) file_get_contents($categoryRepoPath);
$brandRepo = (string) file_get_contents($brandRepoPath);

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

function methodIsLockedFailClosed(?string $methodBody): bool
{
    return $methodBody !== null
        && str_contains($methodBody, 'throw new \LogicException(')
        && str_contains($methodBody, 'locked');
}

$checks = [];
$failed = [];

$catFindLegacy = extractMethodBody($categoryRepo, 'find');
$catList = extractMethodBody($categoryRepo, 'list');
$catMapLegacy = extractMethodBody($categoryRepo, 'mapByIdsForParentLabelLookup');
$catGraphLegacy = extractMethodBody($categoryRepo, 'listLiveForParentGraphAudit');
$catAncestorLegacy = extractMethodBody($categoryRepo, 'ancestorChainContainsId');
$catDupLegacy = extractMethodBody($categoryRepo, 'listDuplicateTrimmedNameGroups');
$catListRepair = extractMethodBody($categoryRepo, 'listUnscopedCatalogForRepair');
$catFindRepair = extractMethodBody($categoryRepo, 'findUnscopedLiveByIdForRepair');
$catMapRepair = extractMethodBody($categoryRepo, 'mapByIdsForParentLabelLookupUnscopedForRepair');
$catGraphRepair = extractMethodBody($categoryRepo, 'listLiveForParentGraphAuditUnscopedForRepair');
$catAncestorRepair = extractMethodBody($categoryRepo, 'ancestorChainContainsIdUnscopedForRepair');
$catDupRepair = extractMethodBody($categoryRepo, 'listDuplicateTrimmedNameGroupsUnscopedForRepair');
$catListTenant = extractMethodBody($categoryRepo, 'listInTenantScope');
$catFindTenant = extractMethodBody($categoryRepo, 'findInTenantScope');
$catSelectable = extractMethodBody($categoryRepo, 'listSelectableForProductBranch');
$catSelectableParent = extractMethodBody($categoryRepo, 'listSelectableAsParentForCategoryBranch');
$catGlobalOnly = extractMethodBody($categoryRepo, 'listSelectableOrgGlobalOnlyInResolvedTenantCatalog');
$catUnion = extractMethodBody($categoryRepo, 'listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog');

$brandFindLegacy = extractMethodBody($brandRepo, 'find');
$brandList = extractMethodBody($brandRepo, 'list');
$brandDupLegacy = extractMethodBody($brandRepo, 'listDuplicateTrimmedNameGroups');
$brandListRepair = extractMethodBody($brandRepo, 'listUnscopedCatalogForRepair');
$brandFindRepair = extractMethodBody($brandRepo, 'findUnscopedLiveByIdForRepair');
$brandDupRepair = extractMethodBody($brandRepo, 'listDuplicateTrimmedNameGroupsUnscopedForRepair');
$brandListTenant = extractMethodBody($brandRepo, 'listInTenantScope');
$brandFindTenant = extractMethodBody($brandRepo, 'findInTenantScope');
$brandSelectable = extractMethodBody($brandRepo, 'listSelectableForProductBranch');
$brandGlobalOnly = extractMethodBody($brandRepo, 'listSelectableOrgGlobalOnlyInResolvedTenantCatalog');
$brandUnion = extractMethodBody($brandRepo, 'listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog');

$checks['Category legacy weak read methods are locked fail-closed'] =
    methodIsLockedFailClosed($catFindLegacy)
    && methodIsLockedFailClosed($catList)
    && methodIsLockedFailClosed($catMapLegacy)
    && methodIsLockedFailClosed($catGraphLegacy)
    && methodIsLockedFailClosed($catAncestorLegacy)
    && methodIsLockedFailClosed($catDupLegacy);

$checks['Brand legacy weak read methods are locked fail-closed'] =
    methodIsLockedFailClosed($brandFindLegacy)
    && methodIsLockedFailClosed($brandList)
    && methodIsLockedFailClosed($brandDupLegacy);

$checks['Category/Brand explicit unscoped repair methods exist and keep OR branch/null semantics isolated'] =
    $catListRepair !== null
    && $brandListRepair !== null
    && str_contains($catListRepair, 'branch_id = ? OR branch_id IS NULL')
    && str_contains($brandListRepair, 'branch_id = ? OR branch_id IS NULL');

$checks['Category/Brand explicit repair replacements exist for locked weak reads'] =
    $catFindRepair !== null
    && $catMapRepair !== null
    && $catGraphRepair !== null
    && $catAncestorRepair !== null
    && $catDupRepair !== null
    && $brandFindRepair !== null
    && $brandDupRepair !== null
    && str_contains($catAncestorRepair, 'findUnscopedLiveByIdForRepair')
    && str_contains($catMapRepair, 'SELECT id, name, deleted_at FROM product_categories')
    && str_contains($catGraphRepair, 'SELECT id, parent_id, name, branch_id FROM product_categories')
    && str_contains($catDupRepair, 'FROM product_categories')
    && str_contains($brandDupRepair, 'FROM product_brands');

$checks['Category/Brand tenant list methods fail closed for invalid branch context'] =
    $catListTenant !== null
    && $brandListTenant !== null
    && str_contains($catListTenant, 'if ($operationBranchId <= 0)')
    && str_contains($catListTenant, 'return [];')
    && str_contains($brandListTenant, 'if ($operationBranchId <= 0)')
    && str_contains($brandListTenant, 'return [];');

$checks['Category/Brand tenant find methods fail closed for invalid branch context'] =
    $catFindTenant !== null
    && $brandFindTenant !== null
    && str_contains($catFindTenant, '$operationBranchId <= 0')
    && str_contains($catFindTenant, 'return null;')
    && str_contains($brandFindTenant, '$operationBranchId <= 0')
    && str_contains($brandFindTenant, 'return null;');

$checks['Category selectable methods split explicit ORG_GLOBAL-only vs BRANCH_OWNED_OR_ORG_GLOBAL'] =
    $catSelectable !== null
    && $catSelectableParent !== null
    && $catGlobalOnly !== null
    && $catUnion !== null
    && str_contains($catSelectable, 'if ($productBranchId !== null && $productBranchId <= 0)')
    && str_contains($catSelectableParent, 'if ($categoryBranchId !== null && $categoryBranchId <= 0)')
    && str_contains($catSelectable, 'listSelectableOrgGlobalOnlyInResolvedTenantCatalog')
    && str_contains($catSelectable, 'listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog')
    && str_contains($catSelectableParent, 'listSelectableOrgGlobalOnlyInResolvedTenantCatalog')
    && str_contains($catSelectableParent, 'listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog')
    && str_contains($catGlobalOnly, 'pc.branch_id IS NULL')
    && str_contains($catUnion, 'pc.branch_id IS NULL OR pc.branch_id = ?');

$checks['Brand selectable methods split explicit ORG_GLOBAL-only vs BRANCH_OWNED_OR_ORG_GLOBAL'] =
    $brandSelectable !== null
    && $brandGlobalOnly !== null
    && $brandUnion !== null
    && str_contains($brandSelectable, 'if ($productBranchId !== null && $productBranchId <= 0)')
    && str_contains($brandSelectable, 'listSelectableOrgGlobalOnlyInResolvedTenantCatalog')
    && str_contains($brandSelectable, 'listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog')
    && str_contains($brandGlobalOnly, 'pb.branch_id IS NULL')
    && str_contains($brandUnion, 'pb.branch_id IS NULL OR pb.branch_id = ?');

$checks['Category/Brand explicit runtime taxonomy visibility uses resolved-org union fragments'] =
    str_contains($catListTenant ?? '', 'taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause')
    && str_contains($catGlobalOnly ?? '', 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause')
    && str_contains($catUnion ?? '', 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause')
    && str_contains($brandListTenant ?? '', 'taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause')
    && str_contains($brandGlobalOnly ?? '', 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause')
    && str_contains($brandUnion ?? '', 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause');

$runtimeCallerFiles = [
    // HTTP/runtime surfaces
    $modules . '/inventory/controllers/ProductCategoryController.php',
    $modules . '/inventory/controllers/ProductBrandController.php',
    $modules . '/inventory/controllers/ProductController.php',
    // inventory services that can be reached from runtime or apply paths
    $modules . '/inventory/services/ProductCategoryService.php',
    $modules . '/inventory/services/ProductBrandService.php',
    $modules . '/inventory/services/ProductTaxonomyAssignabilityService.php',
    $modules . '/inventory/services/ProductTaxonomyLegacyBackfillService.php',
    $modules . '/inventory/services/ProductTaxonomyOrphanFkAuditService.php',
    $modules . '/inventory/services/ProductTaxonomyDuplicateNoncanonicalRetireService.php',
    $modules . '/inventory/services/ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService.php',
    $modules . '/inventory/services/ProductCategoryDuplicateParentCanonicalRelinkService.php',
    $modules . '/inventory/services/ProductCategoryTreeIntegrityAuditService.php',
    $modules . '/inventory/services/ProductCategoryTreeCycleClusterAuditService.php',
    $modules . '/inventory/services/ProductCategoryTreeCycleClusterSafeBreakService.php',
    $modules . '/bootstrap/register_inventory.php',
];

$runtimeText = '';
foreach ($runtimeCallerFiles as $f) {
    if (!is_file($f)) {
        $checks['Runtime caller files exist for static taxonomy map'] = false;
        break;
    }
    $runtimeText .= "\n" . (string) file_get_contents($f);
}
if (!isset($checks['Runtime caller files exist for static taxonomy map'])) {
    $checks['Runtime caller files exist for static taxonomy map'] = true;
}

$checks['Runtime caller map uses canonical taxonomy methods'] =
    str_contains($runtimeText, '->listInTenantScope(')
    && str_contains($runtimeText, '->findInTenantScope(')
    && str_contains($runtimeText, '->listSelectableForProductBranch(');

$checks['Runtime caller map does not use weak taxonomy legacy methods'] =
    !str_contains($runtimeText, '->find(')
    && !str_contains($runtimeText, '->list(')
    && !str_contains($runtimeText, '->mapByIdsForParentLabelLookup(')
    && !str_contains($runtimeText, '->listLiveForParentGraphAudit(')
    && !str_contains($runtimeText, '->ancestorChainContainsId(')
    && !str_contains($runtimeText, '->listDuplicateTrimmedNameGroups(')
    && !str_contains($runtimeText, '->listUnscopedCatalogForRepair(')
    && !str_contains($runtimeText, '->findUnscopedLiveByIdForRepair(');

/** @var list<string> $violations */
$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $fileInfo->getPathname());
    if ($path === str_replace('\\', '/', $categoryRepoPath) || $path === str_replace('\\', '/', $brandRepoPath)) {
        continue;
    }
    $text = (string) file_get_contents($fileInfo->getPathname());
    if ($text === '') {
        continue;
    }
    $usesCategoryRepo = str_contains($text, 'ProductCategoryRepository');
    $usesBrandRepo = str_contains($text, 'ProductBrandRepository');
    if (!$usesCategoryRepo && !$usesBrandRepo) {
        continue;
    }

    if ($usesCategoryRepo) {
        preg_match_all('/ProductCategoryRepository\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $catVars);
        $vars = array_values(array_unique($catVars[1] ?? []));
        foreach ($vars as $var) {
            foreach (['find', 'list', 'mapByIdsForParentLabelLookup', 'listLiveForParentGraphAudit', 'ancestorChainContainsId', 'listDuplicateTrimmedNameGroups'] as $weakMethod) {
                if (preg_match('/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/', $text) === 1
                    || preg_match('/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/', $text) === 1) {
                    $violations[] = "{$path} uses weak ProductCategoryRepository::{$weakMethod}() via \${$var}";
                }
            }
        }
        if (preg_match('/ProductCategoryRepository::class\)\s*->\s*(find|list|mapByIdsForParentLabelLookup|listLiveForParentGraphAudit|ancestorChainContainsId|listDuplicateTrimmedNameGroups)\s*\(/', $text, $m) === 1) {
            $violations[] = "{$path} uses weak ProductCategoryRepository::{$m[1]}() via container->get() chain";
        }
    }

    if ($usesBrandRepo) {
        preg_match_all('/ProductBrandRepository\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $brandVars);
        $vars = array_values(array_unique($brandVars[1] ?? []));
        foreach ($vars as $var) {
            foreach (['find', 'list', 'listDuplicateTrimmedNameGroups'] as $weakMethod) {
                if (preg_match('/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/', $text) === 1
                    || preg_match('/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/', $text) === 1) {
                    $violations[] = "{$path} uses weak ProductBrandRepository::{$weakMethod}() via \${$var}";
                }
            }
        }
        if (preg_match('/ProductBrandRepository::class\)\s*->\s*(find|list|listDuplicateTrimmedNameGroups)\s*\(/', $text, $m) === 1) {
            $violations[] = "{$path} uses weak ProductBrandRepository::{$m[1]}() via container->get() chain";
        }
    }
}

$checks['No module runtime file uses weak taxonomy legacy methods'] = ($violations === []);

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

echo PHP_EOL . "verify_root_02_inventory_taxonomy_catalog_semantic_normalization_01: OK\n";
exit(0);

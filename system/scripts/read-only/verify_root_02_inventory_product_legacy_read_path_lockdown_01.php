<?php

declare(strict_types=1);

/**
 * ROOT-02-INVENTORY-PRODUCT-LEGACY-READ-PATH-LOCKDOWN-01
 *
 * Static proof:
 * - ProductRepository weak legacy read helpers are locked fail-closed.
 * - Canonical runtime-safe ProductRepository read entry points remain explicit and fail closed.
 * - Explicit repair/control-plane helper names exist for unscoped paths.
 * - Broad module scan blocks weak ProductRepository helper reachability beyond inventory-only surfaces.
 * - Runtime-sensitive surfaces avoid explicit unscoped repair helpers.
 *
 * Usage:
 *   php system/scripts/read-only/verify_root_02_inventory_product_legacy_read_path_lockdown_01.php
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';
$repoPath = $modules . '/inventory/repositories/ProductRepository.php';

if (!is_file($repoPath)) {
    fwrite(STDERR, "FAIL: missing ProductRepository.php at {$repoPath}\n");
    exit(1);
}
if (!is_dir($modules)) {
    fwrite(STDERR, "FAIL: expected modules directory at {$modules}\n");
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

$weakReadMethods = [
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

$repairReadMethods = [
    'findUnscopedByIdForRepair',
    'findLockedUnscopedForRepair',
    'listUnscopedCatalogForRepair',
    'countUnscopedCatalogForRepair',
    'listActiveForUnifiedCatalogUnscopedForRepair',
    'listNonDeletedForTaxonomyBackfillUnscopedForRepair',
    'countNonDeletedProductsUnscopedForRepair',
    'countActiveWithOrphanProductCategoryFkUnscopedForRepair',
    'countActiveWithOrphanProductBrandFkUnscopedForRepair',
    'listOrphanProductCategoryFkCountsByProductBranchUnscopedForRepair',
    'listOrphanProductBrandFkCountsByProductBranchUnscopedForRepair',
    'listOrphanProductCategoryFkExamplesUnscopedForRepair',
    'listOrphanProductBrandFkExamplesUnscopedForRepair',
];

$checks = [];
$failed = [];

$allWeakLocked = true;
foreach ($weakReadMethods as $method) {
    $allWeakLocked = $allWeakLocked && methodLocked(extractMethodBody($repo, $method));
}
$checks['ProductRepository weak legacy read helpers are locked fail-closed'] = $allWeakLocked;

$allRepairPresent = true;
foreach ($repairReadMethods as $method) {
    $allRepairPresent = $allRepairPresent && (extractMethodBody($repo, $method) !== null);
}
$checks['Explicit ProductRepository unscoped repair helper names exist'] = $allRepairPresent;

$listTenant = extractMethodBody($repo, 'listInTenantScope');
$countTenant = extractMethodBody($repo, 'countInTenantScope');
$findReadableResolved = extractMethodBody($repo, 'findReadableForStockMutationInResolvedOrg');
$findLockedResolved = extractMethodBody($repo, 'findLockedForStockMutationInResolvedOrg');
$listResolvedUnion = extractMethodBody($repo, 'listActiveForUnifiedCatalogInResolvedOrg');
$listResolvedGlobal = extractMethodBody($repo, 'listActiveOrgGlobalCatalogInResolvedOrg');

$checks['Canonical runtime-safe ProductRepository reads fail closed on invalid/unresolved context'] =
    $listTenant !== null
    && $countTenant !== null
    && $findReadableResolved !== null
    && $findLockedResolved !== null
    && $listResolvedUnion !== null
    && $listResolvedGlobal !== null
    && str_contains($listTenant, 'if ($branchId <= 0)')
    && str_contains($listTenant, 'return [];')
    && str_contains($countTenant, 'if ($branchId <= 0)')
    && str_contains($countTenant, 'return 0;')
    && str_contains($findReadableResolved, 'if ($operationBranchId <= 0)')
    && str_contains($findReadableResolved, 'return null;')
    && str_contains($findLockedResolved, 'if ($operationBranchId <= 0)')
    && str_contains($findLockedResolved, 'return null;')
    && str_contains($listResolvedUnion, 'if ($operationBranchId <= 0)')
    && str_contains($listResolvedUnion, 'return [];');

$checks['Canonical runtime-safe ProductRepository reads keep strict resolved-org SQL contracts'] =
    str_contains($listTenant ?? '', "branchColumnOwnedByResolvedOrganizationExistsClause('p')")
    && str_contains($countTenant ?? '', "branchColumnOwnedByResolvedOrganizationExistsClause('p')")
    && str_contains($findReadableResolved ?? '', 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')
    && str_contains($findLockedResolved ?? '', 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')
    && str_contains($listResolvedUnion ?? '', 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')
    && str_contains($listResolvedGlobal ?? '', 'resolvedTenantOrganizationHasLiveBranchExistsClause')
    && str_contains($listResolvedGlobal ?? '', 'p.branch_id IS NULL');

/** @var list<string> $weakUsageViolations */
$weakUsageViolations = [];
/** @var list<string> $runtimeRepairUsageViolations */
$runtimeRepairUsageViolations = [];

$scannedPhpFiles = 0;
$repoReferencedFiles = 0;
$repoReferencedOutsideInventory = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $scannedPhpFiles++;
    $path = str_replace('\\', '/', $fileInfo->getPathname());
    if ($path === str_replace('\\', '/', $repoPath)) {
        continue;
    }
    $text = (string) file_get_contents($fileInfo->getPathname());
    if ($text === '' || !str_contains($text, 'ProductRepository')) {
        continue;
    }
    $repoReferencedFiles++;
    if (!str_contains($path, '/inventory/')) {
        $repoReferencedOutsideInventory++;
    }

    preg_match_all('/ProductRepository\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $varsMatch);
    $vars = array_values(array_unique($varsMatch[1] ?? []));

    $norm = '/' . ltrim(str_replace('\\', '/', substr($path, strlen(str_replace('\\', '/', $modules)))), '/');
    $basename = basename($norm);
    $isControllerOrProviderSurface = preg_match('#/(controllers|providers|resources|routes)/#', $norm) === 1;
    $isServiceSurface = str_contains($norm, '/services/');
    $isRepairService = preg_match('/(Backfill|Audit|Retire|Relink|Finalization|Drilldown|Reconcile|Recovery|Snapshot|Quality|Readiness)/', $basename) === 1;
    $isRuntimeSensitiveSurface = $isControllerOrProviderSurface || ($isServiceSurface && !$isRepairService);

    foreach ($vars as $var) {
        foreach ($weakReadMethods as $weakMethod) {
            $callOnThis = '/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/';
            $callOnVar = '/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/';
            if (preg_match($callOnThis, $text) === 1 || preg_match($callOnVar, $text) === 1) {
                $weakUsageViolations[] = "{$path} calls weak ProductRepository::{$weakMethod}() via \${$var}";
            }
        }

        if ($isRuntimeSensitiveSurface) {
            foreach ($repairReadMethods as $repairMethod) {
                $callOnThis = '/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($repairMethod, '/') . '\s*\(/';
                $callOnVar = '/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($repairMethod, '/') . '\s*\(/';
                if (preg_match($callOnThis, $text) === 1 || preg_match($callOnVar, $text) === 1) {
                    $runtimeRepairUsageViolations[] = "{$path} runtime-sensitive surface uses repair-only ProductRepository::{$repairMethod}()";
                }
            }
        }
    }

    if (preg_match(
        '/ProductRepository::class\)\s*->\s*(find|findLocked|list|count|listActiveForUnifiedCatalog|listNonDeletedForTaxonomyBackfill|countNonDeletedProducts|countActiveWithOrphanProductCategoryFk|countActiveWithOrphanProductBrandFk|listOrphanProductCategoryFkCountsByProductBranch|listOrphanProductBrandFkCountsByProductBranch|listOrphanProductCategoryFkExamples|listOrphanProductBrandFkExamples)\s*\(/',
        $text,
        $m
    ) === 1) {
        $weakUsageViolations[] = "{$path} calls weak ProductRepository::{$m[1]}() via container->get() chain";
    }
}

$checks['Broad module scan found no weak ProductRepository read helper usage'] = ($weakUsageViolations === []);
$checks['Runtime-sensitive surfaces avoid unscoped ProductRepository repair helpers'] = ($runtimeRepairUsageViolations === []);
$checks['Broad scan includes ProductRepository references outside inventory module'] = $repoReferencedOutsideInventory > 0;

foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

echo 'Scanned PHP files under modules: ' . $scannedPhpFiles . PHP_EOL;
echo 'Module files referencing ProductRepository: ' . $repoReferencedFiles . PHP_EOL;
echo 'ProductRepository references outside inventory: ' . $repoReferencedOutsideInventory . PHP_EOL;

if ($weakUsageViolations !== []) {
    foreach ($weakUsageViolations as $v) {
        fwrite(STDERR, 'VIOLATION: ' . $v . PHP_EOL);
    }
}
if ($runtimeRepairUsageViolations !== []) {
    foreach ($runtimeRepairUsageViolations as $v) {
        fwrite(STDERR, 'RUNTIME-REPAIR-VIOLATION: ' . $v . PHP_EOL);
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_root_02_inventory_product_legacy_read_path_lockdown_01: OK\n";
exit(0);


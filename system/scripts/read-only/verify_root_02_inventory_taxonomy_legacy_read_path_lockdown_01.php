<?php

declare(strict_types=1);

/**
 * ROOT-02-INVENTORY-TAXONOMY-LEGACY-READ-PATH-LOCKDOWN-01
 *
 * Static proof:
 * - Legacy weak taxonomy read helpers on ProductCategoryRepository/ProductBrandRepository are locked fail-closed.
 * - Explicit repair/control-plane helper names remain available.
 * - Tenant/runtime entry points remain explicit and fail closed on invalid context.
 * - Module-wide caller scan (not inventory-only) finds no weak legacy taxonomy helper usage.
 * - Runtime-sensitive caller surfaces do not call explicit repair-only helpers.
 *
 * Usage:
 *   php system/scripts/read-only/verify_root_02_inventory_taxonomy_legacy_read_path_lockdown_01.php
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';
$categoryRepoPath = $modules . '/inventory/repositories/ProductCategoryRepository.php';
$brandRepoPath = $modules . '/inventory/repositories/ProductBrandRepository.php';

if (!is_file($categoryRepoPath) || !is_file($brandRepoPath)) {
    fwrite(STDERR, "FAIL: missing ProductCategoryRepository/ProductBrandRepository.\n");
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

function methodLocked(?string $methodBody): bool
{
    return $methodBody !== null
        && str_contains($methodBody, 'throw new \LogicException(')
        && str_contains($methodBody, 'locked');
}

$checks = [];
$failed = [];

$weakCategoryMethods = [
    'find',
    'list',
    'mapByIdsForParentLabelLookup',
    'listLiveForParentGraphAudit',
    'ancestorChainContainsId',
    'listDuplicateTrimmedNameGroups',
];
$weakBrandMethods = [
    'find',
    'list',
    'listDuplicateTrimmedNameGroups',
];

$categoryWeakLocked = true;
foreach ($weakCategoryMethods as $method) {
    $categoryWeakLocked = $categoryWeakLocked && methodLocked(extractMethodBody($categoryRepo, $method));
}
$checks['Category weak legacy read helpers are locked fail-closed'] = $categoryWeakLocked;

$brandWeakLocked = true;
foreach ($weakBrandMethods as $method) {
    $brandWeakLocked = $brandWeakLocked && methodLocked(extractMethodBody($brandRepo, $method));
}
$checks['Brand weak legacy read helpers are locked fail-closed'] = $brandWeakLocked;

$catFindRepair = extractMethodBody($categoryRepo, 'findUnscopedLiveByIdForRepair');
$catMapRepair = extractMethodBody($categoryRepo, 'mapByIdsForParentLabelLookupUnscopedForRepair');
$catListRepair = extractMethodBody($categoryRepo, 'listUnscopedCatalogForRepair');
$catGraphRepair = extractMethodBody($categoryRepo, 'listLiveForParentGraphAuditUnscopedForRepair');
$catAncestorRepair = extractMethodBody($categoryRepo, 'ancestorChainContainsIdUnscopedForRepair');
$catDupRepair = extractMethodBody($categoryRepo, 'listDuplicateTrimmedNameGroupsUnscopedForRepair');

$brandFindRepair = extractMethodBody($brandRepo, 'findUnscopedLiveByIdForRepair');
$brandListRepair = extractMethodBody($brandRepo, 'listUnscopedCatalogForRepair');
$brandDupRepair = extractMethodBody($brandRepo, 'listDuplicateTrimmedNameGroupsUnscopedForRepair');

$checks['Explicit repair/control-plane taxonomy helper names exist'] =
    $catFindRepair !== null
    && $catMapRepair !== null
    && $catListRepair !== null
    && $catGraphRepair !== null
    && $catAncestorRepair !== null
    && $catDupRepair !== null
    && $brandFindRepair !== null
    && $brandListRepair !== null
    && $brandDupRepair !== null;

$checks['Explicit unscoped list methods keep branch-or-global SQL isolated'] =
    str_contains($catListRepair ?? '', 'branch_id = ? OR branch_id IS NULL')
    && str_contains($brandListRepair ?? '', 'branch_id = ? OR branch_id IS NULL');

$catFindTenant = extractMethodBody($categoryRepo, 'findInTenantScope');
$catListTenant = extractMethodBody($categoryRepo, 'listInTenantScope');
$catSelectable = extractMethodBody($categoryRepo, 'listSelectableForProductBranch');
$catParentSelectable = extractMethodBody($categoryRepo, 'listSelectableAsParentForCategoryBranch');
$brandFindTenant = extractMethodBody($brandRepo, 'findInTenantScope');
$brandListTenant = extractMethodBody($brandRepo, 'listInTenantScope');
$brandSelectable = extractMethodBody($brandRepo, 'listSelectableForProductBranch');

$checks['Tenant/runtime taxonomy reads fail closed on invalid/unresolved branch context'] =
    str_contains($catFindTenant ?? '', '$operationBranchId <= 0')
    && str_contains($catFindTenant ?? '', 'return null;')
    && str_contains($brandFindTenant ?? '', '$operationBranchId <= 0')
    && str_contains($brandFindTenant ?? '', 'return null;')
    && str_contains($catListTenant ?? '', '$operationBranchId <= 0')
    && str_contains($catListTenant ?? '', 'return [];')
    && str_contains($brandListTenant ?? '', '$operationBranchId <= 0')
    && str_contains($brandListTenant ?? '', 'return [];')
    && str_contains($catSelectable ?? '', '$productBranchId !== null && $productBranchId <= 0')
    && str_contains($catSelectable ?? '', 'return [];')
    && str_contains($catParentSelectable ?? '', '$categoryBranchId !== null && $categoryBranchId <= 0')
    && str_contains($catParentSelectable ?? '', 'return [];')
    && str_contains($brandSelectable ?? '', '$productBranchId !== null && $productBranchId <= 0')
    && str_contains($brandSelectable ?? '', 'return [];');

$weakByRepo = [
    'ProductCategoryRepository' => $weakCategoryMethods,
    'ProductBrandRepository' => $weakBrandMethods,
];
$repairOnlyByRepo = [
    'ProductCategoryRepository' => [
        'findUnscopedLiveByIdForRepair',
        'mapByIdsForParentLabelLookupUnscopedForRepair',
        'listUnscopedCatalogForRepair',
        'listLiveForParentGraphAuditUnscopedForRepair',
        'ancestorChainContainsIdUnscopedForRepair',
        'listDuplicateTrimmedNameGroupsUnscopedForRepair',
    ],
    'ProductBrandRepository' => [
        'findUnscopedLiveByIdForRepair',
        'listUnscopedCatalogForRepair',
        'listDuplicateTrimmedNameGroupsUnscopedForRepair',
    ],
];

/** @var list<string> $violations */
$violations = [];
/** @var list<string> $runtimeRepairViolations */
$runtimeRepairViolations = [];
$scannedPhpFiles = 0;
$repoReferencedFiles = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $scannedPhpFiles++;
    $path = str_replace('\\', '/', $fileInfo->getPathname());
    if ($path === str_replace('\\', '/', $categoryRepoPath) || $path === str_replace('\\', '/', $brandRepoPath)) {
        continue;
    }
    $text = (string) file_get_contents($fileInfo->getPathname());
    if ($text === '') {
        continue;
    }

    $usesCatRepo = str_contains($text, 'ProductCategoryRepository');
    $usesBrandRepo = str_contains($text, 'ProductBrandRepository');
    if (!$usesCatRepo && !$usesBrandRepo) {
        continue;
    }
    $repoReferencedFiles++;

    $norm = '/' . ltrim(str_replace('\\', '/', substr($path, strlen(str_replace('\\', '/', $modules)))), '/');
    $basename = basename($norm);
    $isControllerOrProviderSurface = preg_match('#/(controllers|providers|resources|routes)/#', $norm) === 1;
    $isServiceSurface = str_contains($norm, '/services/');
    $isRepairService = preg_match('/(Backfill|Audit|Relink|Retire|Finalization|CycleCluster|Integrity|SafeBreak)/', $basename) === 1;
    $isRuntimeSensitiveSurface = $isControllerOrProviderSurface || ($isServiceSurface && !$isRepairService);

    foreach ($weakByRepo as $repoClass => $weakMethods) {
        if (!str_contains($text, $repoClass)) {
            continue;
        }
        preg_match_all('/' . preg_quote($repoClass, '/') . '\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $varsMatch);
        $vars = array_values(array_unique($varsMatch[1] ?? []));
        foreach ($vars as $var) {
            foreach ($weakMethods as $weakMethod) {
                $callOnThis = '/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/';
                $callOnVar = '/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($weakMethod, '/') . '\s*\(/';
                if (preg_match($callOnThis, $text) === 1 || preg_match($callOnVar, $text) === 1) {
                    $violations[] = "{$path} calls weak {$repoClass}::{$weakMethod}() via \${$var}";
                }
            }
        }
        $weakAlt = implode('|', array_map(static fn (string $m): string => preg_quote($m, '/'), $weakMethods));
        if (preg_match('/' . preg_quote($repoClass, '/') . '::class\)\s*->\s*(' . $weakAlt . ')\s*\(/', $text, $m) === 1) {
            $violations[] = "{$path} calls weak {$repoClass}::{$m[1]}() via container->get() chain";
        }

        if ($isRuntimeSensitiveSurface) {
            $repairMethods = $repairOnlyByRepo[$repoClass] ?? [];
            foreach ($vars as $var) {
                foreach ($repairMethods as $repairMethod) {
                    $callOnThis = '/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($repairMethod, '/') . '\s*\(/';
                    $callOnVar = '/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($repairMethod, '/') . '\s*\(/';
                    if (preg_match($callOnThis, $text) === 1 || preg_match($callOnVar, $text) === 1) {
                        $runtimeRepairViolations[] = "{$path} runtime-sensitive surface uses repair-only {$repoClass}::{$repairMethod}()";
                    }
                }
            }
        }
    }
}

$checks['Broad caller scan found no weak taxonomy legacy read helper usage'] = ($violations === []);
$checks['Runtime-sensitive surfaces avoid repair-only taxonomy helpers'] = ($runtimeRepairViolations === []);
$checks['Broad module scan touched repository-referencing files beyond narrow inventory slice'] = $repoReferencedFiles > 0;

foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

echo 'Scanned PHP files under modules: ' . $scannedPhpFiles . PHP_EOL;
echo 'Repository-referencing module files scanned: ' . $repoReferencedFiles . PHP_EOL;

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, 'VIOLATION: ' . $v . PHP_EOL);
    }
}
if ($runtimeRepairViolations !== []) {
    foreach ($runtimeRepairViolations as $v) {
        fwrite(STDERR, 'RUNTIME-REPAIR-VIOLATION: ' . $v . PHP_EOL);
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_root_02_inventory_taxonomy_legacy_read_path_lockdown_01: OK\n";
exit(0);


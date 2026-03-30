<?php

declare(strict_types=1);

/**
 * PLT-TNT-03-CROSS-PLATFORM-AUTOLOAD-CASE-CANONICALIZATION-01
 *
 * Read-only release blocker verifier for cross-platform autoload truth:
 * - Core prefix map must cover every declared Core\* family and point at exact-case directories.
 * - Core class/interface/trait/enum paths must match namespace + symbol casing exactly.
 * - Protected rollout module surfaces must remain reachable under the current module autoloader.
 * - Case-variant duplicate paths are forbidden on protected surfaces.
 * - Core autoload must stay single-path and exact-case (no lowercase/ucfirst fallback hacks).
 */

$systemRoot = dirname(__DIR__, 2);
$autoloadPath = $systemRoot . '/core/app/autoload.php';

if (!is_file($autoloadPath)) {
    fwrite(STDERR, "Missing autoload.php: {$autoloadPath}\n");
    exit(1);
}

$autoloadSource = (string) file_get_contents($autoloadPath);
if ($autoloadSource === '') {
    fwrite(STDERR, "autoload.php is empty or unreadable.\n");
    exit(1);
}

/**
 * @return array{namespace:string,class:string,kind:string}|null
 */
function parsePhpSymbol(string $path): ?array
{
    $src = (string) file_get_contents($path);
    if ($src === '') {
        return null;
    }

    if (!preg_match('/^namespace\s+([^;]+);/m', $src, $nsMatch)) {
        return null;
    }

    if (!preg_match('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)|^(?:final\s+|abstract\s+)?interface\s+([A-Za-z_][A-Za-z0-9_]*)|^(?:final\s+)?trait\s+([A-Za-z_][A-Za-z0-9_]*)|^enum\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $classMatch)) {
        return null;
    }

    $class = '';
    $kind = '';
    if (!empty($classMatch[1])) {
        $class = $classMatch[1];
        $kind = 'class';
    } elseif (!empty($classMatch[2])) {
        $class = $classMatch[2];
        $kind = 'interface';
    } elseif (!empty($classMatch[3])) {
        $class = $classMatch[3];
        $kind = 'trait';
    } elseif (!empty($classMatch[4])) {
        $class = $classMatch[4];
        $kind = 'enum';
    }

    if ($class === '') {
        return null;
    }

    return [
        'namespace' => trim($nsMatch[1]),
        'class' => $class,
        'kind' => $kind,
    ];
}

/**
 * @return array<string,string>
 */
function parseCorePrefixMap(string $autoloadSource): array
{
    $map = [];
    if (preg_match_all('~\'((?:Core\\\\\\\\)[^\']+\\\\\\\\)\'\s*=>\s*\$base\s*\.\s*\'/([^\']+)/\'~', $autoloadSource, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $map[str_replace('\\\\', '\\', $match[1])] = trim($match[2], '/');
        }
    }

    return $map;
}

function pathExistsWithExactCase(string $basePath, string $relativePath): bool
{
    $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '') {
        return is_dir($basePath);
    }

    $current = $basePath;
    foreach (explode('/', $relativePath) as $segment) {
        if (!is_dir($current)) {
            return false;
        }

        $entries = scandir($current);
        if ($entries === false || !in_array($segment, $entries, true)) {
            return false;
        }

        $current .= '/' . $segment;
    }

    return file_exists($current);
}

/**
 * @return list<string>
 */
function collectCaseVariantDuplicates(string $root): array
{
    $seen = [];
    $dupes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        $key = strtolower($relative);
        if (!isset($seen[$key])) {
            $seen[$key] = [$relative];
            continue;
        }

        if (!in_array($relative, $seen[$key], true)) {
            $seen[$key][] = $relative;
        }
    }

    foreach ($seen as $variants) {
        if (count($variants) > 1) {
            sort($variants);
            $dupes[] = implode(' <> ', $variants);
        }
    }

    sort($dupes);

    return $dupes;
}

/**
 * @return list<string>
 */
function coreExpectedPathViolations(string $systemRoot, array $corePrefixes): array
{
    $coreRoot = $systemRoot . '/core';
    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($coreRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($systemRoot) + 1));
        $symbol = parsePhpSymbol($file->getPathname());
        if ($symbol === null || !str_starts_with($symbol['namespace'], 'Core\\')) {
            continue;
        }

        $namespaceParts = explode('\\', $symbol['namespace']);
        $family = $namespaceParts[1] ?? '';
        if ($family === '') {
            $violations[] = $relative . ' declares invalid Core namespace';
            continue;
        }

        $prefix = 'Core\\' . $family . '\\';
        if (!isset($corePrefixes[$prefix])) {
            $violations[] = $relative . ' declares unmapped prefix ' . $prefix;
            continue;
        }

        $fqcn = $symbol['namespace'] . '\\' . $symbol['class'];
        $relativeClass = substr($fqcn, strlen($prefix));
        $expectedRelative = $corePrefixes[$prefix] . '/' . str_replace('\\', '/', $relativeClass) . '.php';
        $actualBase = pathinfo($relative, PATHINFO_FILENAME);

        if ($actualBase !== $symbol['class']) {
            $violations[] = $relative . ' filename case does not match declared ' . $symbol['kind'] . ' ' . $symbol['class'];
        }

        if ($relative !== $expectedRelative) {
            $violations[] = $relative . ' expected ' . $expectedRelative . ' for ' . $fqcn;
        }
    }

    sort($violations);

    return $violations;
}

function verifierModuleFolder(string $pascal): string
{
    $map = [
        'GiftcardsPackages' => 'giftcards-packages',
        'ServicesResources' => 'services-resources',
        'OnlineBooking' => 'online-booking',
    ];

    if (isset($map[$pascal])) {
        return $map[$pascal];
    }

    return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $pascal));
}

/**
 * @param list<string> $parts
 * @return list<string>
 */
function verifierModuleClassFileCandidates(array $parts): array
{
    $module = verifierModuleFolder($parts[0]);
    $className = $parts[count($parts) - 1];
    $dirSegments = array_slice($parts, 1, -1);
    $prefix = 'modules/' . $module . '/';
    if ($dirSegments === []) {
        return [$prefix . $className . '.php'];
    }

    $variants = [
        implode('/', array_map(static fn (string $s): string => strtolower($s), $dirSegments)),
        implode('/', array_map(static fn (string $s): string => ucfirst(strtolower($s)), $dirSegments)),
        implode('/', $dirSegments),
    ];

    $out = [];
    foreach (array_values(array_unique($variants)) as $variant) {
        $out[] = $prefix . $variant . '/' . $className . '.php';
    }

    return $out;
}

/**
 * @param list<string> $relativeDirs
 * @return list<string>
 */
function protectedModuleSurfaceViolations(string $systemRoot, array $relativeDirs): array
{
    $violations = [];
    foreach ($relativeDirs as $relativeDir) {
        $absoluteDir = $systemRoot . '/' . trim($relativeDir, '/');
        if (!is_dir($absoluteDir)) {
            $violations[] = 'missing protected module surface ' . $relativeDir;
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $symbol = parsePhpSymbol($file->getPathname());
            if ($symbol === null || !str_starts_with($symbol['namespace'], 'Modules\\')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($systemRoot) + 1));
            $actualBase = pathinfo($relative, PATHINFO_FILENAME);
            if ($actualBase !== $symbol['class']) {
                $violations[] = $relative . ' filename case does not match declared ' . $symbol['kind'] . ' ' . $symbol['class'];
                continue;
            }

            $parts = explode('\\', substr($symbol['namespace'] . '\\' . $symbol['class'], strlen('Modules\\')));
            $candidates = verifierModuleClassFileCandidates($parts);
            if (!in_array($relative, $candidates, true)) {
                $violations[] = $relative . ' is outside module autoload candidates for ' . $symbol['namespace'] . '\\' . $symbol['class'];
            }
        }
    }

    sort($violations);

    return $violations;
}

$corePrefixes = parseCorePrefixMap($autoloadSource);
$checks = [];
$details = [];

$requiredCorePrefixes = [
    'Core\\Tenant\\',
    'Core\\Repository\\',
];

$checks['core_prefix_map_parseable'] = $corePrefixes !== [];
if (!$checks['core_prefix_map_parseable']) {
    $details[] = 'Could not parse Core prefix map from core/app/autoload.php';
}

$checks['required_core_prefixes_present'] = true;
foreach ($requiredCorePrefixes as $requiredPrefix) {
    if (!isset($corePrefixes[$requiredPrefix])) {
        $checks['required_core_prefixes_present'] = false;
        $details[] = 'Missing required Core prefix: ' . $requiredPrefix;
    }
}

$checks['core_prefix_paths_exist_with_exact_case'] = true;
foreach ($corePrefixes as $prefix => $relativeDir) {
    if (!pathExistsWithExactCase($systemRoot, $relativeDir)) {
        $checks['core_prefix_paths_exist_with_exact_case'] = false;
        $details[] = $prefix . ' points to non-canonical path ' . $relativeDir;
    }
}

$coreViolations = coreExpectedPathViolations($systemRoot, $corePrefixes);
$checks['core_namespace_and_symbol_paths_match_exact_case'] = $coreViolations === [];
foreach ($coreViolations as $violation) {
    $details[] = $violation;
}

$coreDuplicates = collectCaseVariantDuplicates($systemRoot . '/core');
$checks['core_has_no_case_variant_path_duplicates'] = $coreDuplicates === [];
foreach ($coreDuplicates as $duplicate) {
    $details[] = 'core duplicate case-variant path hazard: ' . $duplicate;
}

$protectedModuleDirs = [
    'modules/memberships',
    'modules/sales',
    'modules/packages',
    'modules/gift-cards',
    'modules/inventory',
    'modules/appointments',
    'modules/clients',
    'modules/documents',
    'modules/staff',
    'modules/services-resources',
];
$moduleViolations = protectedModuleSurfaceViolations($systemRoot, $protectedModuleDirs);
$checks['protected_module_surfaces_match_module_autoload_candidates'] = $moduleViolations === [];
foreach ($moduleViolations as $violation) {
    $details[] = $violation;
}

$moduleDuplicates = [];
foreach ($protectedModuleDirs as $dir) {
    $absoluteDir = $systemRoot . '/' . $dir;
    if (!is_dir($absoluteDir)) {
        continue;
    }
    foreach (collectCaseVariantDuplicates($absoluteDir) as $duplicate) {
        $moduleDuplicates[] = $dir . ': ' . $duplicate;
    }
}
$checks['protected_module_surfaces_have_no_case_variant_duplicates'] = $moduleDuplicates === [];
foreach ($moduleDuplicates as $duplicate) {
    $details[] = 'protected module duplicate case-variant path hazard: ' . $duplicate;
}

$coreSectionStart = strpos($autoloadSource, '$prefixes = [');
$modulesMarker = strpos($autoloadSource, '// Modules:');
$coreSection = $coreSectionStart !== false && $modulesMarker !== false && $modulesMarker > $coreSectionStart
    ? substr($autoloadSource, $coreSectionStart, $modulesMarker - $coreSectionStart)
    : '';
$checks['core_autoload_has_no_case_fallback_hack'] = $coreSection !== ''
    && !str_contains($coreSection, 'strtolower(')
    && !str_contains($coreSection, 'ucfirst(')
    && !str_contains($coreSection, 'array_unique(')
    && !str_contains($coreSection, 'moduleClassFileCandidates(');
if (!$checks['core_autoload_has_no_case_fallback_hack']) {
    $details[] = 'Core autoload section must stay exact-case, single-path, and fallback-free.';
}

require $autoloadPath;
$checks['critical_core_classes_autoload_from_canonical_paths'] = class_exists(\Core\Tenant\TenantOwnedDataScopeGuard::class)
    && class_exists(\Core\Tenant\TenantRuntimeContextEnforcer::class)
    && class_exists(\Core\Repository\RepositoryContractGuard::class);
if (!$checks['critical_core_classes_autoload_from_canonical_paths']) {
    $details[] = 'One or more critical Core classes did not autoload: Core\\Tenant\\TenantOwnedDataScopeGuard, Core\\Tenant\\TenantRuntimeContextEnforcer, Core\\Repository\\RepositoryContractGuard';
}

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

if ($details !== []) {
    echo PHP_EOL . 'DETAILS' . PHP_EOL;
    foreach ($details as $detail) {
        echo '- ' . $detail . PHP_EOL;
    }
}

if ($failed) {
    exit(1);
}

echo PHP_EOL . 'verify_cross_platform_autoload_case_canonicalization_01: OK' . PHP_EOL;

<?php

declare(strict_types=1);

/**
 * Enforces incremental architecture rules for core ↔ module boundaries.
 * Risk removed: unreviewed new core→module coupling.
 * Proof: run this script from repo root.
 * @root-family NONE
 */

$repoRoot = dirname(__DIR__, 3);
$coreDir = $repoRoot . '/system/core';

$useAllow = loadAllowlist($repoRoot . '/system/docs/contracts/core-to-module-use-allowlist.txt');
$reqAllow = loadAllowlist($repoRoot . '/system/docs/contracts/core-module-require-allowlist.txt');

$errors = [];

if (!is_dir($coreDir)) {
    fwrite(STDERR, "system/core missing\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($coreDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $full = $fileInfo->getPathname();
    $rel = normalizeRel($repoRoot, $full);
    $contents = file_get_contents($full);
    if ($contents === false) {
        $errors[] = "unreadable: {$rel}";
        continue;
    }

    if (preg_match_all('/^\s*use\s+Modules\\\\/m', $contents, $m)) {
        if (!in_array($rel, $useAllow, true)) {
            $errors[] = "disallowed Modules\\ import in core (allowlist or refactor): {$rel}";
        }
    }

    if (preg_match_all('/\b(require|require_once|include|include_once)\s*\(\s*[^;]*modules\//i', $contents)) {
        if (!in_array($rel, $reqAllow, true)) {
            $errors[] = "disallowed require/include of modules/ path in core: {$rel}";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "verify_architecture_contracts: FAIL\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "verify_architecture_contracts: PASS\n";
exit(0);

/**
 * @return list<string>
 */
function loadAllowlist(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $out[] = str_replace('\\', '/', $line);
    }
    return $out;
}

function normalizeRel(string $repoRoot, string $absolute): string
{
    $repoRoot = rtrim(str_replace('\\', '/', $repoRoot), '/');
    $absolute = str_replace('\\', '/', $absolute);
    if (!str_starts_with($absolute, $repoRoot)) {
        return $absolute;
    }
    return ltrim(substr($absolute, strlen($repoRoot)), '/');
}

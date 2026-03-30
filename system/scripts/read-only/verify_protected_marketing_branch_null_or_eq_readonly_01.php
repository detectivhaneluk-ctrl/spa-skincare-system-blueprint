<?php

declare(strict_types=1);

/**
 * FOUNDATION-PLATFORM-INVARIANTS-AND-FOUNDER-RISK-ENGINE-01 — protected marketing tree:
 * forbid NEW hand-rolled `(alias.)branch_id = ? OR (alias.)branch_id IS NULL` without explicit escape hatch.
 *
 * Approved patterns: line contains {@code NULL_BRANCH_OR_EQ_PATTERN_ALLOW}.
 *
 *   php system/scripts/read-only/verify_protected_marketing_branch_null_or_eq_readonly_01.php
 */

$root = dirname(__DIR__, 2);
$pattern = '/(?:[A-Za-z_][A-Za-z0-9_]*\.)?branch_id\s*=\s*\?\s+OR\s+(?:[A-Za-z_][A-Za-z0-9_]*\.)?branch_id\s+IS\s+NULL/i';

$scan = [
    $root . '/modules/marketing/repositories',
    $root . '/modules/marketing/services',
];

$violations = [];
$rootNorm = str_replace('\\', '/', realpath($root) ?: $root);

foreach ($scan as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $pathNorm = str_replace('\\', '/', $path);
        $rel = str_starts_with($pathNorm, $rootNorm . '/') ? substr($pathNorm, strlen($rootNorm) + 1) : $pathNorm;
        $src = (string) file_get_contents($path);
        $lines = preg_split('/\R/', $src) ?: [];
        foreach ($lines as $i => $line) {
            if ($line === '' || str_contains($line, 'NULL_BRANCH_OR_EQ_PATTERN_ALLOW')) {
                continue;
            }
            $trim = ltrim($line);
            if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '#')) {
                continue;
            }
            if (!preg_match($pattern, $line)) {
                continue;
            }
            $violations[] = $rel . ':' . ($i + 1) . ': ' . trim($line);
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Protected marketing NULL∪eq branch pattern violations:\n" . implode("\n", $violations) . "\n");
    exit(1);
}

echo "verify_protected_marketing_branch_null_or_eq_readonly_01: OK\n";
exit(0);

<?php

declare(strict_types=1);

/**
 * Guardrail for unsafe NULL-branch catalog semantics in protected runtime surfaces.
 *
 * Detects common forms such as:
 * - branch_id IS NULL OR branch_id = ?
 * - alias.branch_id IS NULL OR alias.branch_id = ?
 * - (branch_id IS NULL OR branch_id = ?)
 *
 * Scans sellable catalog and active fulfillment/runtime areas touched by FOUNDATION hardening.
 */

$root = dirname(__DIR__);

$pattern = '/(?:[A-Za-z_][A-Za-z0-9_]*\.)?branch_id\s+IS\s+NULL\s+OR\s+(?:[A-Za-z_][A-Za-z0-9_]*\.)?branch_id\s*=/i';

$scan = [
    $root . '/modules/memberships/repositories',
    $root . '/modules/memberships/services',
    $root . '/modules/memberships/controllers',
    $root . '/modules/packages/repositories',
    $root . '/modules/packages/services',
    $root . '/modules/packages/controllers',
    $root . '/modules/gift-cards/repositories',
    $root . '/modules/gift-cards/services',
    $root . '/modules/gift-cards/providers',
    $root . '/modules/public-commerce',
    $root . '/modules/sales/repositories',
    $root . '/modules/sales/services',
    $root . '/modules/sales/controllers',
    $root . '/modules/sales/providers',
    $root . '/modules/appointments/providers',
    $root . '/modules/clients/services',
    $root . '/modules/documents/repositories',
    $root . '/modules/documents/services',
    $root . '/modules/intake/repositories',
    $root . '/modules/intake/services',
    $root . '/modules/marketing/repositories',
    $root . '/modules/marketing/services',
];

/** @var array<string, string> relative path from system/ => justification */
$allowlist = [
    'modules/sales/repositories/VatRateRepository.php' => 'Settings-backed VAT: intentional global row + branch override (not sellable entitlement catalog).',
    'modules/sales/repositories/PaymentMethodRepository.php' => 'Settings-backed payment methods: global template + branch row (not catalog sellables).',
];

$violations = [];

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
        $rootNorm = str_replace('\\', '/', realpath($root) ?: $root);
        $pathNorm = str_replace('\\', '/', $path);
        $rel = str_starts_with($pathNorm, $rootNorm . '/') ? substr($pathNorm, strlen($rootNorm) + 1) : $pathNorm;
        $src = (string) file_get_contents($path);
        if ($src === '') {
            continue;
        }
        $lines = preg_split('/\R/', $src) ?: [];
        foreach ($lines as $i => $line) {
            if ($line === '' || str_contains($line, 'NULL_BRANCH_PATTERN_ALLOW')) {
                continue;
            }
            if (!preg_match($pattern, $line)) {
                continue;
            }
            if (isset($allowlist[$rel])) {
                continue;
            }
            $violations[] = $rel . ':' . ($i + 1) . ': ' . trim($line);
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "NULL-branch catalog pattern violations (add NULL_BRANCH_PATTERN_ALLOW on line or allowlist with justification):\n" . implode("\n", $violations) . "\n");
    exit(1);
}

echo "verify_null_branch_catalog_patterns: OK (scanned trees clean or explicitly allowlisted).\n";
exit(0);

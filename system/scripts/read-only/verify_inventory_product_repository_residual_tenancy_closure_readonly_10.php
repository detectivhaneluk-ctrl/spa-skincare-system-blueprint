<?php

declare(strict_types=1);

/**
 * FND-TNT-18 — static proof: FOUNDATION-INVENTORY-RESIDUAL-TENANCY-CLOSURE-10
 * ProductRepository: detach / FK reference counts / canonical relink mutations use resolvedTenantCatalogProductVisibilityClause.
 */

$root = dirname(__DIR__, 3);
$path = $root . '/system/modules/inventory/repositories/ProductRepository.php';
$text = (string) file_get_contents($path);

$ok = true;

if (!str_contains($text, 'function resolvedTenantCatalogProductVisibilityClause(')) {
    fwrite(STDERR, "FAIL: ProductRepository must define resolvedTenantCatalogProductVisibilityClause.\n");
    $ok = false;
}

foreach ([
    'detachActiveProductsFromCategory',
    'detachActiveProductsFromBrand',
    'countActiveProductsReferencingCategoryIds',
    'countActiveProductsReferencingBrandIds',
    'relinkActiveProductCategoryIdsToCanonical',
    'relinkActiveProductBrandIdsToCanonical',
] as $fn) {
    $pos = strpos($text, 'function ' . $fn);
    if ($pos === false) {
        fwrite(STDERR, "FAIL: missing function {$fn}.\n");
        $ok = false;
        continue;
    }
    $next = strpos($text, "\n    public function ", $pos + 1);
    $chunk = $next === false ? substr($text, $pos) : substr($text, $pos, $next - $pos);
    if (!str_contains($chunk, 'resolvedTenantCatalogProductVisibilityClause')) {
        fwrite(STDERR, "FAIL: {$fn} must call resolvedTenantCatalogProductVisibilityClause.\n");
        $ok = false;
    }
}

exit($ok ? 0 : 1);

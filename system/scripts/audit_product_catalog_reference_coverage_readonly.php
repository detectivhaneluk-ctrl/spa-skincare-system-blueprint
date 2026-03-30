<?php

declare(strict_types=1);

/**
 * PRODUCT-BRAND-CATALOG-TAIL-WAVE-01 — read-only product normalized category/brand coverage audit.
 *
 * Ops: system/docs/PRODUCT-CATALOG-REFERENCE-COVERAGE-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_product_catalog_reference_coverage_readonly.php
 *   php scripts/audit_product_catalog_reference_coverage_readonly.php --product-id=123
 *   php scripts/audit_product_catalog_reference_coverage_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductCatalogReferenceCoverageAuditService;

$json = in_array('--json', $argv, true);
$productId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--product-id=(\d+)$/', (string) $arg, $m)) {
        $productId = (int) $m[1];
    }
}

try {
    $payload = app(ProductCatalogReferenceCoverageAuditService::class)->run($productId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = ProductCatalogReferenceCoverageAuditService::EXAMPLE_CAP;
echo "Product catalog reference coverage audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'product_id_filter: ' . ($payload['product_id_filter'] === null ? '(none)' : (string) $payload['product_id_filter']) . "\n";
echo 'products_scanned: ' . $payload['products_scanned'] . "\n";
echo 'affected_products_count: ' . $payload['affected_products_count'] . "\n";
echo 'affected_product_ids_sample: ' . json_encode($payload['affected_product_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "coverage_class_counts:\n";
foreach (ProductCatalogReferenceCoverageAuditService::COVERAGE_CLASSES as $class) {
    $n = (int) ($payload['coverage_class_counts'][$class] ?? 0);
    echo sprintf("  %-35s %d\n", $class, $n);
}

echo "\nExamples (cap={$cap} per class, product_id order):\n";
foreach (ProductCatalogReferenceCoverageAuditService::COVERAGE_CLASSES as $class) {
    $rows = $payload['examples_by_coverage_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $p) {
        echo sprintf(
            "    product_id=%d sku=%s category_id=%s brand_id=%s assignable_cat=%s assignable_brand=%s reasons=%s\n",
            (int) $p['product_id'],
            (string) $p['sku'],
            $p['category_id'] === null ? 'null' : (string) $p['category_id'],
            $p['brand_id'] === null ? 'null' : (string) $p['brand_id'],
            $p['category_assignable'] ? 'true' : 'false',
            $p['brand_assignable'] ? 'true' : 'false',
            json_encode($p['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);

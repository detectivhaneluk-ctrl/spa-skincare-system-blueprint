<?php

declare(strict_types=1);

/**
 * PRODUCT-BRAND-CATALOG-TAIL-WAVE-02 — read-only legacy vs normalized taxonomy coherence audit.
 *
 * Ops: system/docs/PRODUCT-LEGACY-NORMALIZED-TAXONOMY-COHERENCE-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_product_legacy_normalized_taxonomy_coherence_readonly.php
 *   php scripts/audit_product_legacy_normalized_taxonomy_coherence_readonly.php --product-id=123
 *   php scripts/audit_product_legacy_normalized_taxonomy_coherence_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductLegacyNormalizedTaxonomyCoherenceAuditService;

$json = in_array('--json', $argv, true);
$productId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--product-id=(\d+)$/', (string) $arg, $m)) {
        $productId = (int) $m[1];
    }
}

try {
    $payload = app(ProductLegacyNormalizedTaxonomyCoherenceAuditService::class)->run($productId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = ProductLegacyNormalizedTaxonomyCoherenceAuditService::EXAMPLE_CAP;
echo "Product legacy / normalized taxonomy coherence audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'product_id_filter: ' . ($payload['product_id_filter'] === null ? '(none)' : (string) $payload['product_id_filter']) . "\n";
echo 'products_scanned: ' . $payload['products_scanned'] . "\n";
echo 'affected_products_count: ' . $payload['affected_products_count'] . "\n";
echo 'affected_product_ids_sample: ' . json_encode($payload['affected_product_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "coherence_class_counts:\n";
foreach (ProductLegacyNormalizedTaxonomyCoherenceAuditService::COHERENCE_CLASSES as $class) {
    $n = (int) ($payload['coherence_class_counts'][$class] ?? 0);
    echo sprintf("  %-32s %d\n", $class, $n);
}

echo "\ncategory_axis_status_counts:\n";
foreach (ProductLegacyNormalizedTaxonomyCoherenceAuditService::AXIS_STATUSES as $st) {
    $n = (int) ($payload['category_axis_status_counts'][$st] ?? 0);
    echo sprintf("  %-32s %d\n", $st, $n);
}

echo "\nbrand_axis_status_counts:\n";
foreach (ProductLegacyNormalizedTaxonomyCoherenceAuditService::AXIS_STATUSES as $st) {
    $n = (int) ($payload['brand_axis_status_counts'][$st] ?? 0);
    echo sprintf("  %-32s %d\n", $st, $n);
}

echo "\nExamples (cap={$cap} per coherence_class, product_id order):\n";
foreach (ProductLegacyNormalizedTaxonomyCoherenceAuditService::COHERENCE_CLASSES as $class) {
    $rows = $payload['examples_by_coherence_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $p) {
        echo sprintf(
            "    product_id=%d cat_axis=%s brand_axis=%s legacy_cat=%s norm_cat=%s legacy_brand=%s norm_brand=%s reasons=%s\n",
            (int) $p['product_id'],
            (string) $p['category_axis_status'],
            (string) $p['brand_axis_status'],
            $p['legacy_category'] === null ? 'null' : json_encode($p['legacy_category'], JSON_UNESCAPED_UNICODE),
            $p['normalized_category_name'] === null ? 'null' : json_encode($p['normalized_category_name'], JSON_UNESCAPED_UNICODE),
            $p['legacy_brand'] === null ? 'null' : json_encode($p['legacy_brand'], JSON_UNESCAPED_UNICODE),
            $p['normalized_brand_name'] === null ? 'null' : json_encode($p['normalized_brand_name'], JSON_UNESCAPED_UNICODE),
            json_encode($p['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);

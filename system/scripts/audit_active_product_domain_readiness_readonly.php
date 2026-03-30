<?php

declare(strict_types=1);

/**
 * UNIFIED-CATALOG-DOMAIN-TRUTH-TAIL-WAVE-01 — read-only active product domain readiness audit.
 *
 * Ops: system/docs/ACTIVE-PRODUCT-DOMAIN-READINESS-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_active_product_domain_readiness_readonly.php
 *   php scripts/audit_active_product_domain_readiness_readonly.php --product-id=123
 *   php scripts/audit_active_product_domain_readiness_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ActiveProductDomainReadinessAuditService;

$json = in_array('--json', $argv, true);
$productId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--product-id=(\d+)$/', (string) $arg, $m)) {
        $productId = (int) $m[1];
    }
}

try {
    $payload = app(ActiveProductDomainReadinessAuditService::class)->run($productId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = ActiveProductDomainReadinessAuditService::EXAMPLE_CAP;
echo "Active product domain readiness audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'product_id_filter: ' . ($payload['product_id_filter'] === null ? '(none)' : (string) $payload['product_id_filter']) . "\n";
echo 'products_scanned: ' . $payload['products_scanned'] . "\n";
echo 'affected_products_count: ' . $payload['affected_products_count'] . "\n";
echo 'affected_product_ids_sample: ' . json_encode($payload['affected_product_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "readiness_class_counts:\n";
foreach (ActiveProductDomainReadinessAuditService::READINESS_CLASSES as $class) {
    $n = (int) ($payload['readiness_class_counts'][$class] ?? 0);
    echo sprintf("  %-28s %d\n", $class, $n);
}

echo "\nExamples (cap={$cap} per class, product_id order):\n";
foreach (ActiveProductDomainReadinessAuditService::READINESS_CLASSES as $class) {
    $rows = $payload['examples_by_readiness_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $p) {
        echo sprintf(
            "    product_id=%d identity=%s coverage=%s coherence=%s reasons=%s\n",
            (int) $p['product_id'],
            $p['identity_present'] ? 'true' : 'false',
            (string) $p['category_reference_coverage_class'],
            (string) $p['taxonomy_coherence_class'],
            json_encode($p['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);

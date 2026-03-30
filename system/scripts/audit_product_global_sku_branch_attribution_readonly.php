<?php

declare(strict_types=1);

/**
 * PRODUCTS-GLOBAL-SKU-BRANCH-ATTRIBUTION-AUDIT-01 — read-only audit.
 *
 * Proves where global products ({@code products.branch_id IS NULL}, non-deleted) have movements with
 * {@code stock_movements.branch_id IS NOT NULL}. Does not modify data.
 *
 * Operational context: single {@code products.stock_quantity} per SKU; movement {@code branch_id} is
 * attribution only and may reflect invoice branch or operator context — see inventory README + ledger ops doc.
 *
 * Usage (from {@code system/}):
 *   php scripts/audit_product_global_sku_branch_attribution_readonly.php
 *   php scripts/audit_product_global_sku_branch_attribution_readonly.php --json
 *
 * Exit {@code 0} unless bootstrap/query failure ({@code 1}).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductGlobalSkuBranchAttributionAuditService;

$json = in_array('--json', $argv, true);

try {
    $report = app(ProductGlobalSkuBranchAttributionAuditService::class)->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Global SKU branch attribution audit (read-only)\n";
echo "products_scanned (non-deleted, branch_id NULL): {$report['products_scanned']}\n";
echo "affected_global_products_count: {$report['affected_global_products_count']}\n";
echo "affected_movements_count: {$report['affected_movements_count']}\n\n";

if ($report['example_products'] !== []) {
    echo "Example products (cap=" . ProductGlobalSkuBranchAttributionAuditService::EXAMPLE_PRODUCT_CAP . "):\n";
    foreach ($report['example_products'] as $p) {
        echo sprintf(
            "  product_id=%d sku=%s movements_with_branch_id=%d name=%s\n",
            $p['product_id'],
            $p['sku'],
            $p['branch_tagged_movements'],
            $p['name']
        );
    }
    echo "\n";
}

if ($report['example_movements'] !== []) {
    echo "Example movements (cap=" . ProductGlobalSkuBranchAttributionAuditService::EXAMPLE_MOVEMENT_CAP . "):\n";
    foreach ($report['example_movements'] as $m) {
        $ref = ($m['reference_type'] ?? 'null') . '/' . ($m['reference_id'] !== null ? (string) $m['reference_id'] : 'null');
        echo sprintf(
            "  movement_id=%d product_id=%d type=%s qty=%s branch_id=%d ref=%s created_at=%s\n",
            $m['id'],
            $m['product_id'],
            $m['movement_type'],
            (string) $m['quantity'],
            $m['branch_id'],
            $ref,
            $m['created_at']
        );
    }
}

echo "\nNo database changes were made.\n";

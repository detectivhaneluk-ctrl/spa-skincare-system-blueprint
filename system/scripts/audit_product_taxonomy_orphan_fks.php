<?php

declare(strict_types=1);

/**
 * Audit + optional repair for orphan normalized product taxonomy FKs (PRODUCT-TAXONOMY-ORPHAN-FK-AUDIT-AND-REPAIR-01).
 *
 * Default: dry-run (no writes). Mutations require explicit --apply.
 * Exit code 0 on success (including when anomalies exist); non-zero only on runtime failure.
 *
 * Usage (from system/):
 *   php scripts/audit_product_taxonomy_orphan_fks.php
 *   php scripts/audit_product_taxonomy_orphan_fks.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductTaxonomyOrphanFkAuditService;

try {
    $apply = in_array('--apply', $argv, true);
    $service = app(ProductTaxonomyOrphanFkAuditService::class);
    $summary = $service->run($apply);

    echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";

    foreach ($summary as $k => $v) {
        if ($k === 'anomaly_examples' || $k === 'orphan_category_fk_by_scope' || $k === 'orphan_brand_fk_by_scope') {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

    echo "\nOrphan category FK by product branch scope:\n";
    foreach ($summary['orphan_category_fk_by_scope'] as $row) {
        echo sprintf("  %s: %d\n", $row['scope'], $row['count']);
    }
    if ($summary['orphan_category_fk_by_scope'] === []) {
        echo "  (none)\n";
    }

    echo "\nOrphan brand FK by product branch scope:\n";
    foreach ($summary['orphan_brand_fk_by_scope'] as $row) {
        echo sprintf("  %s: %d\n", $row['scope'], $row['count']);
    }
    if ($summary['orphan_brand_fk_by_scope'] === []) {
        echo "  (none)\n";
    }

    if (!empty($summary['anomaly_examples'])) {
        echo "\nAnomaly examples (capped):\n";
        foreach ($summary['anomaly_examples'] as $ex) {
            echo sprintf(
                "  product_id=%d field=%s fk_id=%d\n",
                (int) $ex['product_id'],
                (string) $ex['field'],
                (int) $ex['fk_id']
            );
        }
    }

    if (!$apply) {
        echo "\nNo database changes were made. Re-run with --apply to NULL orphan FKs only.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

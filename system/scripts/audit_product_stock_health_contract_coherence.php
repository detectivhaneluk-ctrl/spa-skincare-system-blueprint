<?php

declare(strict_types=1);

/**
 * PRODUCTS-DOMAIN-HARDENING-WAVE-11 — read-only stock-health contract coherence audit.
 *
 * Proves cross-tool invariants for consolidated snapshot, comparison, and preflight layers.
 *
 * Usage (from system/):
 *   php scripts/audit_product_stock_health_contract_coherence.php
 *
 * Output: single JSON object (stdout). Exit 0 iff overall_status=pass.
 *
 * Ops: system/docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockHealthContractCoherenceAuditService;

try {
    $payload = app(ProductStockHealthContractCoherenceAuditService::class)->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

$status = (string) ($payload['overall_status'] ?? '');
exit($status === ProductStockHealthContractCoherenceAuditService::STATUS_PASS ? 0 : 2);

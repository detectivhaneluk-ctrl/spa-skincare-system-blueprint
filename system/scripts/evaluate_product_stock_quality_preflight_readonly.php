<?php

declare(strict_types=1);

/**
 * PRODUCTS-STOCK-QUALITY-PREFLIGHT-ADVISORY — read-only advisory from consolidated snapshot JSON (optional baseline).
 *
 * Inputs: JSON from `php scripts/audit_product_stock_quality_consolidated_readonly.php --json`
 *
 * Usage (from system/):
 *   php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=path/to/current.json
 *   php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=current.json --baseline=checkpoint.json --json
 *
 * Ops: system/docs/PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md
 *
 * Exit 0: advisory produced. Exit 1: usage / IO / invalid JSON / validation error.
 * Advisory is policy guidance only — not a stock correctness guarantee.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockQualityPreflightAdvisoryService;

$opts = getopt('', ['current:', 'baseline:', 'json']);
$currentPath = $opts['current'] ?? null;
$baselinePath = $opts['baseline'] ?? null;
$jsonOut = array_key_exists('json', $opts);

if ($currentPath === null || $currentPath === '') {
    fwrite(STDERR, "Usage: php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=<file.json> [--baseline=<file.json>] [--json]\n");
    exit(1);
}

try {
    $current = loadSnapshotJson($currentPath, 'current');
    $baseline = null;
    if ($baselinePath !== null && $baselinePath !== '') {
        $baseline = loadSnapshotJson($baselinePath, 'baseline');
    }
    $result = app(ProductStockQualityPreflightAdvisoryService::class)->evaluate($current, $baseline);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($jsonOut) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo "Product stock quality — preflight advisory (read-only; policy only)\n";
echo 'advisory_schema_version: ' . $result['advisory_schema_version'] . "\n";
echo 'baseline_present: ' . ($result['baseline_present'] ? 'true' : 'false') . "\n";
echo 'advisory_decision: ' . $result['advisory_decision'] . "\n";
echo 'advisory_reason_codes: ' . json_encode($result['advisory_reason_codes'], JSON_UNESCAPED_UNICODE) . "\n";
echo 'current_overall_health_status: ' . $result['current_overall_health_status'] . "\n";
echo 'current_status_fingerprint: ' . $result['current_status_fingerprint'] . "\n";
echo 'recommended_manual_review: ' . ($result['recommended_manual_review'] ? 'true' : 'false') . "\n";
if ($result['comparison_summary'] !== null) {
    echo 'comparison_summary: ' . json_encode($result['comparison_summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "comparison_summary: null\n";
}

echo "\nNo database access; no writes. This output is advisory only.\n";

/**
 * @return array<string, mixed>
 */
function loadSnapshotJson(string $path, string $label): array
{
    if (!is_readable($path)) {
        throw new \InvalidArgumentException("Cannot read file for [{$label}]: {$path}");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new \InvalidArgumentException("Failed to read [{$label}]: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new \InvalidArgumentException("Invalid JSON for [{$label}]: {$path}");
    }

    return $data;
}

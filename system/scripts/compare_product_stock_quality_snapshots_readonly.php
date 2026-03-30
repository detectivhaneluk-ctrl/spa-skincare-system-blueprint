<?php

declare(strict_types=1);

/**
 * PRODUCTS-STOCK-QUALITY-SNAPSHOT-COMPARISON — compare two consolidated audit JSON files (read-only, no DB).
 *
 * Inputs: JSON from `php scripts/audit_product_stock_quality_consolidated_readonly.php --json`
 *
 * Usage (from system/):
 *   php scripts/compare_product_stock_quality_snapshots_readonly.php --left=path/to/baseline.json --right=path/to/current.json
 *   php scripts/compare_product_stock_quality_snapshots_readonly.php --left=baseline.json --right=current.json --json
 *
 * Ops: system/docs/PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md
 *
 * Exit 0: comparison produced. Exit 1: usage / IO / invalid JSON / validation error.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockQualitySnapshotComparisonService;

$opts = getopt('', ['left:', 'right:', 'json']);
$leftPath = $opts['left'] ?? null;
$rightPath = $opts['right'] ?? null;
$jsonOut = array_key_exists('json', $opts);

if ($leftPath === null || $rightPath === null || $leftPath === '' || $rightPath === '') {
    fwrite(STDERR, "Usage: php scripts/compare_product_stock_quality_snapshots_readonly.php --left=<file.json> --right=<file.json> [--json]\n");
    exit(1);
}

try {
    $left = loadSnapshotJson($leftPath, 'left');
    $right = loadSnapshotJson($rightPath, 'right');
    $result = app(ProductStockQualitySnapshotComparisonService::class)->compare($left, $right);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($jsonOut) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo "Product stock quality — snapshot comparison (read-only)\n";
echo 'comparison_schema_version: ' . $result['comparison_schema_version'] . "\n";
echo 'left_schema_version: ' . $result['left_schema_version'] . "\n";
echo 'right_schema_version: ' . $result['right_schema_version'] . "\n";
echo 'contract_compatible: ' . ($result['contract_compatible'] ? 'true' : 'false') . "\n";
echo 'overall_change_status: ' . $result['overall_change_status'] . "\n";
echo 'fingerprint_changed: ' . ($result['fingerprint_changed'] ? 'true' : 'false') . "\n";
echo 'health_status_changed: ' . ($result['health_status_changed'] ? 'true' : 'false') . "\n";
echo 'issue_codes_added: ' . json_encode($result['issue_codes_added'], JSON_UNESCAPED_UNICODE) . "\n";
echo 'issue_codes_resolved: ' . json_encode($result['issue_codes_resolved'], JSON_UNESCAPED_UNICODE) . "\n";
echo 'persistent_issue_codes: ' . json_encode($result['persistent_issue_codes'], JSON_UNESCAPED_UNICODE) . "\n";
echo "component_changes:\n";
foreach ($result['component_changes'] as $cid => $delta) {
    echo '  [' . $cid . '] severity ' . $delta['severity_before'] . ' -> ' . $delta['severity_after'];
    echo ' | fp_changed=' . ($delta['fingerprint_changed'] ? 'true' : 'false');
    echo ' | codes +' . json_encode($delta['issue_codes_added'], JSON_UNESCAPED_UNICODE);
    echo ' -' . json_encode($delta['issue_codes_resolved'], JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\nNo database access; no writes.\n";

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

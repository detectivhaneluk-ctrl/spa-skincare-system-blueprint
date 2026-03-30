<?php

declare(strict_types=1);

/**
 * PRODUCTS-STOCK-QUALITY-CONSOLIDATED-AUDIT-01 — read-only consolidated stock quality audit.
 *
 * Composes ledger reconciliation, global SKU branch attribution, origin classification,
 * reference integrity, and classification drift (no duplicated heavy queries).
 *
 * Ops + severity rules: system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_product_stock_quality_consolidated_readonly.php
 *   php scripts/audit_product_stock_quality_consolidated_readonly.php --json
 *   php scripts/audit_product_stock_quality_consolidated_readonly.php --fail-on-critical
 *   php scripts/audit_product_stock_quality_consolidated_readonly.php --fail-on-warn
 *
 * Default: exit 0 when the audit completes (health does not affect exit).
 * Optional gates: non-zero exit when overall_health_status matches policy (see ops doc).
 * Exit 1: bootstrap/runtime failure.
 *
 * Payload (service): schema_version, status_fingerprint, normalized rollups, active_issue_codes,
 * issue_inventory, … (see PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockQualityConsolidatedAuditService;

$json = in_array('--json', $argv, true);
$failOnWarn = in_array('--fail-on-warn', $argv, true);
$failOnCritical = in_array('--fail-on-critical', $argv, true);

$gatePolicy = 'none';
if ($failOnWarn) {
    $gatePolicy = 'fail_on_warn';
} elseif ($failOnCritical) {
    $gatePolicy = 'fail_on_critical';
}

try {
    $payload = app(ProductStockQualityConsolidatedAuditService::class)->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$health = $payload['overall_health_status'] ?? '';
$gateResult = 'pass';
if ($gatePolicy === 'fail_on_critical' && $health === ProductStockQualityConsolidatedAuditService::SEVERITY_CRITICAL) {
    $gateResult = 'fail';
} elseif ($gatePolicy === 'fail_on_warn' && ($health === ProductStockQualityConsolidatedAuditService::SEVERITY_WARN || $health === ProductStockQualityConsolidatedAuditService::SEVERITY_CRITICAL)) {
    $gateResult = 'fail';
}

if ($json) {
    $out = $payload;
    $out['gate_policy'] = $gatePolicy;
    $out['gate_result'] = $gateResult;
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($gateResult === 'fail' ? 2 : 0);
}

$policyLabel = match ($gatePolicy) {
    'fail_on_warn' => 'fail-on-warn (non-zero exit if overall_health_status is warn or critical)',
    'fail_on_critical' => 'fail-on-critical (non-zero exit if overall_health_status is critical only)',
    default => 'none (exit code ignores health unless a gate flag is set)',
};
echo 'Exit gate policy: ' . $policyLabel . "\n";
if ($gatePolicy !== 'none') {
    echo 'Gate result: ' . $gateResult . "\n";
}

echo "Product stock quality — consolidated audit (read-only)\n";
echo 'schema_version: ' . $payload['schema_version'] . "\n";
echo 'generated_at: ' . $payload['generated_at'] . "\n";
echo 'overall_health_status: ' . $payload['overall_health_status'] . "\n";
echo 'overall_summary: ' . $payload['overall_summary'] . "\n";
echo 'active_issue_count: ' . $payload['active_issue_count'] . "\n";
echo 'active_issue_codes: ' . ($payload['active_issue_codes'] === [] ? '(none)' : implode(', ', $payload['active_issue_codes'])) . "\n";
if (($payload['issue_inventory'] ?? []) !== []) {
    echo "issue_inventory:\n";
    foreach ($payload['issue_inventory'] as $row) {
        echo sprintf(
            "  %s | severity=%s | component=%s | %s\n",
            $row['code'],
            $row['severity'],
            $row['component'],
            $row['summary']
        );
    }
} else {
    echo "issue_inventory: (none)\n";
}
echo "\n";

echo "--- diff / checkpoint summary (stable fields; compare across runs) ---\n";
echo 'status_fingerprint: ' . $payload['status_fingerprint'] . "\n";
echo 'issue_counts_by_severity: ' . json_encode($payload['issue_counts_by_severity'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo 'issue_counts_by_component: ' . json_encode($payload['issue_counts_by_component'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo 'component_status_summary: ' . json_encode($payload['component_status_summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "\n";

if ($payload['recommended_next_steps'] !== []) {
    echo "recommended_next_steps (deduped, investigation CLIs):\n";
    foreach ($payload['recommended_next_steps'] as $step) {
        echo '  - ' . $step['readonly_cli'] . ' — ' . $step['ops_doc'] . "\n";
    }
    echo "\n";
}

foreach ($payload['component_results'] as $componentId => $block) {
    $codes = $block['active_issue_codes'] ?? [];
    echo '[' . $componentId . '] severity=' . $block['severity'] . ' status_fingerprint=' . ($block['status_fingerprint'] ?? '') . ' active_issue_codes=' . ($codes === [] ? '(none)' : implode(',', $codes)) . "\n";
    if ($block['severity_reasons'] !== []) {
        foreach ($block['severity_reasons'] as $r) {
            echo '  - ' . $r . "\n";
        }
    }
    if (($block['recommended_next_checks'] ?? []) !== []) {
        echo "  recommended_next_checks:\n";
        foreach ($block['recommended_next_checks'] as $step) {
            echo '    - ' . $step['readonly_cli'] . ' — ' . $step['ops_doc'] . "\n";
        }
    }
    $rep = $block['report'];
    switch ($componentId) {
        case 'ledger_reconciliation':
            echo sprintf(
                "  products_scanned=%d matched=%d mismatched=%d\n",
                (int) $rep['products_scanned'],
                (int) $rep['matched_count'],
                (int) $rep['mismatched_count']
            );
            break;
        case 'global_sku_branch_attribution':
            echo sprintf(
                "  products_scanned=%d affected_global_products=%d affected_movements=%d\n",
                (int) $rep['products_scanned'],
                (int) $rep['affected_global_products_count'],
                (int) $rep['affected_movements_count']
            );
            break;
        case 'origin_classification':
            echo sprintf(
                "  total_movements=%d movements_on_deleted_or_missing_product=%d\n",
                (int) $rep['total_movements'],
                (int) $rep['movements_on_deleted_or_missing_product']
            );
            $cbo = $rep['counts_by_origin'] ?? [];
            echo '  counts_by_origin: ' . json_encode($cbo, JSON_UNESCAPED_UNICODE) . "\n";
            break;
        case 'reference_integrity':
            echo sprintf("  total_movements=%d\n", (int) $rep['total_movements']);
            echo '  counts_by_anomaly: ' . json_encode($rep['counts_by_anomaly'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
            break;
        case 'classification_drift':
            echo sprintf(
                "  other_uncategorized_total=%d manual_operator_entry_total=%d manual_operator_unexpected_movement_type_count=%d\n",
                (int) $rep['other_uncategorized_total'],
                (int) $rep['manual_operator_entry_total'],
                (int) $rep['manual_operator_unexpected_movement_type_count']
            );
            echo '  counts_by_drift_reason: ' . json_encode($rep['counts_by_drift_reason'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
            break;
    }
    echo "\n";
}

echo "No database changes were made.\n";

exit($gateResult === 'fail' ? 2 : 0);

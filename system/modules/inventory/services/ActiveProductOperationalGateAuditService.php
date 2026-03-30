<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Read-only per-product operational gate for **active** products: inventory readiness matrix +
 * stock-health evidence from consolidated component reports + preflight policy (no baseline).
 *
 * Per-product stock-health attribution uses **capped examples** embedded in
 * {@see ProductStockQualityConsolidatedAuditService} payloads only (same evidence as consolidated JSON).
 *
 * Task: {@code UNIFIED-CATALOG-DOMAIN-TRUTH-TAIL-WAVE-03}.
 */
final class ActiveProductOperationalGateAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AFFECTED_ID_SAMPLE_CAP = 100;

    public const AUDIT_SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const OPERATIONAL_GATE_CLASSES = [
        'operationally_clear',
        'manual_review_required',
        'blocked_by_stock_health',
        'blocked_by_domain_inventory_state',
        'unusable_operational_state',
        'mixed_gate_anomaly',
    ];

    public function __construct(
        private ActiveProductInventoryReadinessMatrixAuditService $inventoryMatrix,
        private ProductStockQualityConsolidatedAuditService $consolidatedStockQuality,
        private ProductStockQualityPreflightAdvisoryService $preflightAdvisory,
    ) {
    }

    /**
     * @return array{
     *     generated_at_utc: string,
     *     audit_schema_version: int,
     *     products_scanned: int,
     *     operational_gate_class_counts: array<string, int>,
     *     products_with_stock_health_issues_count: int,
     *     blocked_products_count: int,
     *     affected_product_ids_sample: list<int>,
     *     examples_by_operational_gate_class: array<string, list<array<string, mixed>>>,
     *     notes: list<string>,
     *     products: list<array{
     *         product_id: int,
     *         sku: string,
     *         name: string,
     *         branch_id: int|null,
     *         inventory_readiness_class: string,
     *         stock_health_issue_count: int,
     *         stock_health_max_severity: string|null,
     *         stock_health_issue_codes: list<string>,
     *         preflight_blocking_signal: bool,
     *         operational_gate_class: string,
     *         reason_codes: list<string>
     *     }>
     * }
     */
    public function run(?int $productId = null): array
    {
        $generatedAt = gmdate('c');

        $matrix = $this->inventoryMatrix->run($productId);
        $consolidated = $this->consolidatedStockQuality->run();
        $advisory = $this->preflightAdvisory->evaluate($consolidated, null);

        $preflightBlocking = $advisory['advisory_decision'] === ProductStockQualityPreflightAdvisoryService::DECISION_HOLD;
        $healthByProduct = $this->buildPerProductStockHealthFromConsolidated(
            $consolidated['component_results'] ?? []
        );

        $gateCounts = array_fill_keys(self::OPERATIONAL_GATE_CLASSES, 0);
        $examples = [];
        foreach (self::OPERATIONAL_GATE_CLASSES as $c) {
            $examples[$c] = [];
        }

        $productsOut = [];
        $stockIssueProductCount = 0;
        $blockedCount = 0;
        $affectedIds = [];

        foreach ($matrix['products'] as $row) {
            $pid = (int) $row['product_id'];
            $invClass = (string) ($row['inventory_readiness_class'] ?? '');
            $health = $healthByProduct[$pid] ?? ['issue_codes' => [], 'max_severity' => null];
            $codes = $health['issue_codes'];
            $issueCount = count($codes);
            $maxSev = $health['max_severity'];

            if ($issueCount > 0) {
                $stockIssueProductCount++;
            }

            [$gateClass, $reasonCodes] = $this->classifyGate(
                $invClass,
                $issueCount,
                $maxSev,
                $preflightBlocking,
                $codes
            );

            $reasonCodes = $this->mergeReasonCodes(
                isset($row['reason_codes']) && is_array($row['reason_codes']) ? $row['reason_codes'] : [],
                $reasonCodes,
                $advisory,
                $consolidated['schema_version'] ?? '',
                $codes
            );

            $outRow = [
                'product_id' => $pid,
                'sku' => (string) ($row['sku'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'branch_id' => $row['branch_id'] ?? null,
                'inventory_readiness_class' => $invClass,
                'stock_health_issue_count' => $issueCount,
                'stock_health_max_severity' => $maxSev,
                'stock_health_issue_codes' => $codes,
                'preflight_blocking_signal' => $preflightBlocking,
                'operational_gate_class' => $gateClass,
                'reason_codes' => $reasonCodes,
            ];

            $gateCounts[$gateClass]++;
            $productsOut[] = $outRow;

            if ($gateClass !== 'operationally_clear') {
                $affectedIds[] = $pid;
            }

            if (!in_array($gateClass, ['operationally_clear', 'manual_review_required'], true)) {
                $blockedCount++;
            }

            if (count($examples[$gateClass]) < self::EXAMPLE_CAP) {
                $examples[$gateClass][] = $outRow;
            }
        }

        usort($productsOut, static fn (array $a, array $b): int => ((int) $a['product_id']) <=> ((int) $b['product_id']));

        $notes = [
            'Scans only active non-deleted products (same scope as ActiveProductInventoryReadinessMatrixAuditService).',
            'inventory_readiness_class is from ActiveProductInventoryReadinessMatrixAuditService (WAVE-02).',
            'Stock-health issues per product are derived only from capped example rows inside consolidated component_results reports (same JSON contract as ProductStockQualityConsolidatedAuditService); products not appearing in those examples are treated as having zero attributed issues.',
            'Global consolidated issues without per-example product_id (e.g. some origin signals) are not attributed to individual products here.',
            'Preflight uses ProductStockQualityPreflightAdvisoryService with baseline omitted (current snapshot only), matching conservative hold/review/proceed policy.',
            'preflight_blocking_signal is true only when advisory_decision is hold.',
            'blocked_products_count excludes operationally_clear and manual_review_required.',
            'Read-only: no repairs, no storefront, no mixed-sales or publish behavior.',
            'consolidated_schema_version=' . (string) ($consolidated['schema_version'] ?? ''),
            'preflight_advisory_decision=' . (string) ($advisory['advisory_decision'] ?? ''),
        ];

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'products_scanned' => count($productsOut),
            'operational_gate_class_counts' => $gateCounts,
            'products_with_stock_health_issues_count' => $stockIssueProductCount,
            'blocked_products_count' => $blockedCount,
            'affected_product_ids_sample' => array_slice($affectedIds, 0, self::AFFECTED_ID_SAMPLE_CAP),
            'examples_by_operational_gate_class' => $examples,
            'notes' => $notes,
            'products' => $productsOut,
        ];
    }

    /**
     * @param array<string, mixed> $componentResults
     * @return array<int, array{issue_codes: list<string>, max_severity: string|null}>
     */
    private function buildPerProductStockHealthFromConsolidated(array $componentResults): array
    {
        /** @var array<int, array<string, string>> $acc product_id => code => severity */
        $acc = [];

        $ledger = $componentResults['ledger_reconciliation']['report'] ?? [];
        foreach ($ledger['mismatch_examples'] ?? [] as $ex) {
            $pid = (int) ($ex['product_id'] ?? 0);
            if ($pid > 0) {
                $this->addIssue($acc, $pid, ProductStockQualityConsolidatedAuditService::ISSUE_LEDGER_MISMATCH_PRESENT);
            }
        }

        $globalSku = $componentResults['global_sku_branch_attribution']['report'] ?? [];
        foreach ($globalSku['example_products'] ?? [] as $ex) {
            $pid = (int) ($ex['product_id'] ?? 0);
            if ($pid > 0) {
                $this->addIssue($acc, $pid, ProductStockQualityConsolidatedAuditService::ISSUE_GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT);
            }
        }
        foreach ($globalSku['example_movements'] ?? [] as $ex) {
            $pid = (int) ($ex['product_id'] ?? 0);
            if ($pid > 0) {
                $this->addIssue($acc, $pid, ProductStockQualityConsolidatedAuditService::ISSUE_GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT);
            }
        }

        $reference = $componentResults['reference_integrity']['report'] ?? [];
        foreach ($reference['examples_by_anomaly'] ?? [] as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $ex) {
                $pid = (int) ($ex['product_id'] ?? 0);
                if ($pid > 0) {
                    $this->addIssue($acc, $pid, ProductStockQualityConsolidatedAuditService::ISSUE_REFERENCE_INTEGRITY_ANOMALIES_PRESENT);
                }
            }
        }

        $origin = $componentResults['origin_classification']['report'] ?? [];
        foreach (($origin['examples_by_origin']['other_uncategorized'] ?? []) as $ex) {
            $pid = (int) ($ex['product_id'] ?? 0);
            if ($pid > 0) {
                $this->addIssue($acc, $pid, ProductStockQualityConsolidatedAuditService::ISSUE_ORIGIN_OTHER_UNCATEGORIZED_PRESENT);
            }
        }

        $drift = $componentResults['classification_drift']['report'] ?? [];
        foreach ($drift['examples_by_drift_reason'] ?? [] as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $ex) {
                $pid = (int) ($ex['product_id'] ?? 0);
                if ($pid > 0) {
                    $this->addIssue($acc, $pid, ProductStockQualityConsolidatedAuditService::ISSUE_CLASSIFICATION_DRIFT_PRESENT);
                }
            }
        }
        foreach ($drift['manual_operator_unexpected_movement_type_examples'] ?? [] as $ex) {
            $pid = (int) ($ex['product_id'] ?? 0);
            if ($pid > 0) {
                $this->addIssue($acc, $pid, ProductStockQualityConsolidatedAuditService::ISSUE_MANUAL_OPERATOR_UNEXPECTED_MOVEMENT_TYPE_PRESENT);
            }
        }

        $out = [];
        foreach ($acc as $pid => $byCode) {
            $codes = array_keys($byCode);
            $codes = $this->sortIssueCodes($codes);
            $out[$pid] = [
                'issue_codes' => $codes,
                'max_severity' => $this->maxSeverity(array_values($byCode)),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, string>> $acc
     */
    private function addIssue(array &$acc, int $productId, string $code): void
    {
        $sev = ProductStockQualityConsolidatedAuditService::SEVERITY_WARN;
        if (in_array(
            $code,
            [
                ProductStockQualityConsolidatedAuditService::ISSUE_LEDGER_MISMATCH_PRESENT,
                ProductStockQualityConsolidatedAuditService::ISSUE_REFERENCE_INTEGRITY_ANOMALIES_PRESENT,
                ProductStockQualityConsolidatedAuditService::ISSUE_DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT,
            ],
            true
        )) {
            $sev = ProductStockQualityConsolidatedAuditService::SEVERITY_CRITICAL;
        }

        if (!isset($acc[$productId])) {
            $acc[$productId] = [];
        }
        $prev = $acc[$productId][$code] ?? null;
        if ($prev === null || $this->severityRank($sev) > $this->severityRank($prev)) {
            $acc[$productId][$code] = $sev;
        }
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function sortIssueCodes(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        $order = array_flip(ProductStockQualityConsolidatedAuditService::ISSUE_CODE_ORDER);
        usort($codes, static function (string $a, string $b) use ($order): int {
            return ($order[$a] ?? 999) <=> ($order[$b] ?? 999);
        });

        return $codes;
    }

    /**
     * @param list<string> $severities
     */
    private function maxSeverity(array $severities): ?string
    {
        if ($severities === []) {
            return null;
        }
        $best = ProductStockQualityConsolidatedAuditService::SEVERITY_HEALTHY;
        foreach ($severities as $s) {
            if ($this->severityRank((string) $s) > $this->severityRank($best)) {
                $best = (string) $s;
            }
        }

        return $best === ProductStockQualityConsolidatedAuditService::SEVERITY_HEALTHY ? null : $best;
    }

    private function severityRank(string $s): int
    {
        $i = array_search($s, ProductStockQualityConsolidatedAuditService::SEVERITY_ORDER, true);

        return $i === false ? 0 : (int) $i;
    }

    /**
     * @param list<string> $codes
     * @return array{0: string, 1: list<string>}
     */
    private function classifyGate(
        string $inventoryReadinessClass,
        int $issueCount,
        ?string $maxSeverity,
        bool $preflightBlocking,
        array $codes
    ): array {
        $critical = $maxSeverity === ProductStockQualityConsolidatedAuditService::SEVERITY_CRITICAL;

        if ($inventoryReadinessClass === 'unusable_inventory_state') {
            return [
                'unusable_operational_state',
                $this->gateReasons('unusable_operational_state_inventory_unusable', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
            ];
        }

        if ($inventoryReadinessClass === 'mixed_operational_anomaly') {
            return [
                'mixed_gate_anomaly',
                $this->gateReasons('mixed_gate_anomaly_inventory_matrix_mixed', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
            ];
        }

        if ($critical && $preflightBlocking) {
            return [
                'unusable_operational_state',
                $this->gateReasons('unusable_operational_state_critical_and_preflight_hold', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
            ];
        }

        if ($inventoryReadinessClass === 'operationally_ready') {
            if ($preflightBlocking) {
                return [
                    'blocked_by_stock_health',
                    $this->gateReasons('blocked_by_stock_health_preflight_hold', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
                ];
            }
            if ($issueCount > 0) {
                return [
                    'manual_review_required',
                    $this->gateReasons('manual_review_required_stock_health_warn', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
                ];
            }

            return [
                'operationally_clear',
                $this->gateReasons('operationally_clear', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
            ];
        }

        if ($inventoryReadinessClass === 'domain_ready_but_negative_on_hand'
            || $inventoryReadinessClass === 'domain_not_ready') {
            if ($issueCount > 0 || $preflightBlocking) {
                return [
                    'mixed_gate_anomaly',
                    $this->gateReasons('mixed_gate_anomaly_domain_inventory_plus_stock_or_preflight', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
                ];
            }

            return [
                'blocked_by_domain_inventory_state',
                $this->gateReasons('blocked_by_domain_inventory_state', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
            ];
        }

        return [
            'mixed_gate_anomaly',
            $this->gateReasons('mixed_gate_anomaly_residual', $inventoryReadinessClass, $issueCount, $maxSeverity, $preflightBlocking, $codes),
        ];
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function gateReasons(
        string $rule,
        string $invClass,
        int $issueCount,
        ?string $maxSeverity,
        bool $preflightBlocking,
        array $codes
    ): array {
        $r = [
            'operational_gate_rule:' . $rule,
            'inventory_readiness_class:' . $invClass,
            'stock_health_issue_count:' . (string) $issueCount,
            'stock_health_max_severity:' . ($maxSeverity ?? '(none)'),
            'preflight_blocking_signal:' . ($preflightBlocking ? 'true' : 'false'),
        ];
        foreach ($codes as $c) {
            $r[] = 'stock_health_issue_code:' . $c;
        }

        return $r;
    }

    /**
     * @param list<string> $matrixReasons
     * @param list<string> $gateReasons
     * @param array<string, mixed> $advisory
     * @param list<string> $issueCodes
     * @return list<string>
     */
    private function mergeReasonCodes(
        array $matrixReasons,
        array $gateReasons,
        array $advisory,
        string $consolidatedSchemaVersion,
        array $issueCodes
    ): array {
        $merged = [...$matrixReasons, ...$gateReasons];
        $merged[] = 'composed_from:active_product_inventory_readiness_matrix_audit';
        $merged[] = 'composed_from:product_stock_quality_consolidated_audit';
        $merged[] = 'composed_from:product_stock_quality_preflight_advisory';
        $merged[] = 'consolidated_schema_version:' . $consolidatedSchemaVersion;
        $merged[] = 'preflight_advisory_decision:' . (string) ($advisory['advisory_decision'] ?? '');
        foreach ($advisory['advisory_reason_codes'] ?? [] as $rc) {
            $merged[] = 'preflight_reason:' . (string) $rc;
        }
        foreach ($issueCodes as $c) {
            $merged[] = 'attributed_stock_health_issue_code:' . $c;
        }

        $merged = array_values(array_unique($merged));
        sort($merged, SORT_STRING);

        return $merged;
    }
}

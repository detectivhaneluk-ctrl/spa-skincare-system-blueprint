<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Read-only single payload composing inventory stock-quality audits (no duplicate SQL).
 *
 * Underlying services: ledger reconciliation, global SKU movement branch attribution,
 * origin classification rollup, reference integrity, classification drift. Severity rules:
 * {@see system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md}.
 *
 * Task: {@code PRODUCTS-STOCK-QUALITY-CONSOLIDATED-AUDIT-01}. No writes.
 * Stable JSON contract: {@see self::SCHEMA_VERSION} — top-level keys documented in
 * {@code system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md}.
 * Canonical issue codes: {@see self::ISSUE_CODE_ORDER}.
 * Status fingerprints: SHA-256 of canonical JSON (see ops doc); exclude {@code generated_at} from input.
 */
final class ProductStockQualityConsolidatedAuditService
{
    /**
     * Bump only when the consolidated payload’s top-level or per-component envelope changes
     * in a way consumers must handle (additive minor fields may stay on same major.minor).
     */
    public const SCHEMA_VERSION = '1.2.0';

    public const ISSUE_LEDGER_MISMATCH_PRESENT = 'LEDGER_MISMATCH_PRESENT';

    public const ISSUE_DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT = 'DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT';

    public const ISSUE_REFERENCE_INTEGRITY_ANOMALIES_PRESENT = 'REFERENCE_INTEGRITY_ANOMALIES_PRESENT';

    public const ISSUE_GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT = 'GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT';

    public const ISSUE_ORIGIN_OTHER_UNCATEGORIZED_PRESENT = 'ORIGIN_OTHER_UNCATEGORIZED_PRESENT';

    public const ISSUE_CLASSIFICATION_DRIFT_PRESENT = 'CLASSIFICATION_DRIFT_PRESENT';

    public const ISSUE_MANUAL_OPERATOR_UNEXPECTED_MOVEMENT_TYPE_PRESENT = 'MANUAL_OPERATOR_UNEXPECTED_MOVEMENT_TYPE_PRESENT';

    /**
     * Deterministic sort order for {@see active_issue_codes} and {@see issue_inventory}.
     *
     * @var list<string>
     */
    public const ISSUE_CODE_ORDER = [
        self::ISSUE_LEDGER_MISMATCH_PRESENT,
        self::ISSUE_DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT,
        self::ISSUE_REFERENCE_INTEGRITY_ANOMALIES_PRESENT,
        self::ISSUE_GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT,
        self::ISSUE_ORIGIN_OTHER_UNCATEGORIZED_PRESENT,
        self::ISSUE_CLASSIFICATION_DRIFT_PRESENT,
        self::ISSUE_MANUAL_OPERATOR_UNEXPECTED_MOVEMENT_TYPE_PRESENT,
    ];

    public const SEVERITY_HEALTHY = 'healthy';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_CRITICAL = 'critical';

    /** @var list<string> */
    public const SEVERITY_ORDER = [self::SEVERITY_HEALTHY, self::SEVERITY_WARN, self::SEVERITY_CRITICAL];

    /** @var list<string> */
    private const COMPONENT_ORDER_FOR_NEXT_STEPS = [
        'ledger_reconciliation',
        'reference_integrity',
        'origin_classification',
        'classification_drift',
        'global_sku_branch_attribution',
    ];

    /** @var list<string> */
    private const COMPONENT_IDS = [
        'ledger_reconciliation',
        'global_sku_branch_attribution',
        'origin_classification',
        'reference_integrity',
        'classification_drift',
    ];

    public function __construct(
        private ProductStockLedgerReconciliationService $ledgerReconciliation,
        private ProductGlobalSkuBranchAttributionAuditService $globalSkuBranchAttribution,
        private ProductStockMovementOriginClassificationReportService $originClassification,
        private ProductStockMovementReferenceIntegrityAuditService $referenceIntegrity,
        private ProductStockMovementClassificationDriftAuditService $classificationDrift,
    ) {
    }

    /**
     * @return array{
     *     schema_version: string,
     *     generated_at: string,
     *     overall_health_status: 'healthy'|'warn'|'critical',
     *     overall_summary: string,
     *     active_issue_codes: list<string>,
     *     active_issue_count: int,
     *     status_fingerprint: string,
     *     issue_counts_by_severity: array<string, int>,
     *     issue_counts_by_component: array<string, int>,
     *     component_status_summary: array<string, array{
     *         severity: 'healthy'|'warn'|'critical',
     *         active_issue_count: int,
     *         active_issue_codes: list<string>
     *     }>,
     *     issue_inventory: list<array{
     *         code: string,
     *         severity: 'healthy'|'warn'|'critical',
     *         component: string,
     *         summary: string,
     *         recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>
     *     }>,
     *     recommended_next_steps: list<array{readonly_cli: string, ops_doc: string, intent: string}>,
     *     component_results: array<string, array{
     *         severity: 'healthy'|'warn'|'critical',
     *         severity_reasons: list<string>,
     *         active_issue_codes: list<string>,
     *         status_fingerprint: string,
     *         recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>,
     *         report: array
     *     }>
     * }
     */
    public function run(): array
    {
        $ledger = $this->ledgerReconciliation->run();
        $globalSku = $this->globalSkuBranchAttribution->run();
        $origin = $this->originClassification->run();
        $reference = $this->referenceIntegrity->run();
        $drift = $this->classificationDrift->run();

        $componentResults = [
            'ledger_reconciliation' => $this->evaluateLedgerReconciliation($ledger),
            'global_sku_branch_attribution' => $this->evaluateGlobalSkuBranchAttribution($globalSku),
            'origin_classification' => $this->evaluateOriginClassification($origin),
            'reference_integrity' => $this->evaluateReferenceIntegrity($reference),
            'classification_drift' => $this->evaluateClassificationDrift($drift),
        ];

        [$overallStatus, $overallSummary] = $this->rollupOverall($componentResults);
        [$componentResults, $recommendedNextSteps] = $this->attachNextStepGuidance($componentResults);
        [$componentResults, $issueBundle] = $this->attachIssueCatalog($componentResults);

        $summaries = $this->buildNormalizedSummaries($issueBundle['issue_inventory'], $componentResults);
        $componentResults = $this->attachComponentStatusFingerprints($componentResults);
        $statusFingerprint = $this->computeOverallStatusFingerprint($overallStatus, $issueBundle, $componentResults);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'overall_health_status' => $overallStatus,
            'overall_summary' => $overallSummary,
            'active_issue_codes' => $issueBundle['active_issue_codes'],
            'active_issue_count' => $issueBundle['active_issue_count'],
            'status_fingerprint' => $statusFingerprint,
            'issue_counts_by_severity' => $summaries['issue_counts_by_severity'],
            'issue_counts_by_component' => $summaries['issue_counts_by_component'],
            'component_status_summary' => $summaries['component_status_summary'],
            'issue_inventory' => $issueBundle['issue_inventory'],
            'recommended_next_steps' => $recommendedNextSteps,
            'component_results' => $componentResults,
        ];
    }

    /**
     * SHA-256 over a canonical JSON snapshot of stable status fields only (excludes generated_at, schema_version,
     * summaries, reports, recommended_* , severity_reasons). See ops doc for the exact structure.
     *
     * @param array{active_issue_codes: list<string>, active_issue_count: int, issue_inventory: list<array<string, mixed>>} $issueBundle
     * @param array<string, array{severity: string, active_issue_codes: list<string>, ...}> $componentResults
     */
    private function computeOverallStatusFingerprint(string $overallHealthStatus, array $issueBundle, array $componentResults): string
    {
        $inventorySlice = [];
        foreach ($issueBundle['issue_inventory'] as $row) {
            $slice = [
                'code' => $row['code'],
                'component' => $row['component'],
                'severity' => $row['severity'],
            ];
            ksort($slice);
            $inventorySlice[] = $slice;
        }

        $components = [];
        foreach (self::COMPONENT_IDS as $id) {
            $b = $componentResults[$id];
            $block = [
                'active_issue_codes' => $b['active_issue_codes'],
                'severity' => $b['severity'],
            ];
            ksort($block);
            $components[$id] = $block;
        }
        ksort($components);

        $canonical = [
            'active_issue_codes' => $issueBundle['active_issue_codes'],
            'active_issue_count' => $issueBundle['active_issue_count'],
            'components' => $components,
            'issue_inventory' => $inventorySlice,
            'overall_health_status' => $overallHealthStatus,
        ];
        $this->ksortRecursive($canonical);

        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return hash('sha256', 'encode_error');
        }

        return hash('sha256', $json);
    }

    /**
     * @param array<string, array{severity: string, active_issue_codes: list<string>, ...}> $componentResults
     * @return array<string, array{severity: string, active_issue_codes: list<string>, ...}>
     */
    private function attachComponentStatusFingerprints(array $componentResults): array
    {
        foreach (self::COMPONENT_IDS as $id) {
            $block = $componentResults[$id];
            $block['status_fingerprint'] = $this->computeComponentStatusFingerprint(
                $block['severity'],
                $block['active_issue_codes']
            );
            $componentResults[$id] = $block;
        }

        return $componentResults;
    }

    /**
     * @param list<string> $activeIssueCodes
     */
    private function computeComponentStatusFingerprint(string $severity, array $activeIssueCodes): string
    {
        $canonical = [
            'active_issue_codes' => $activeIssueCodes,
            'severity' => $severity,
        ];
        ksort($canonical);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return hash('sha256', 'encode_error');
        }

        return hash('sha256', $json);
    }

    /**
     * @param list<array{code: string, severity: string, component: string, ...}> $issueInventory
     * @param array<string, array{severity: string, active_issue_codes: list<string>, ...}> $componentResults
     * @return array{
     *     issue_counts_by_severity: array<string, int>,
     *     issue_counts_by_component: array<string, int>,
     *     component_status_summary: array<string, array{severity: string, active_issue_count: int, active_issue_codes: list<string>}>
     * }
     */
    private function buildNormalizedSummaries(array $issueInventory, array $componentResults): array
    {
        $bySeverity = [
            self::SEVERITY_CRITICAL => 0,
            self::SEVERITY_WARN => 0,
        ];
        foreach ($issueInventory as $row) {
            $s = $row['severity'];
            if ($s === self::SEVERITY_CRITICAL || $s === self::SEVERITY_WARN) {
                $bySeverity[$s]++;
            }
        }

        $byComponent = [];
        foreach (self::COMPONENT_IDS as $id) {
            $byComponent[$id] = 0;
        }
        foreach ($issueInventory as $row) {
            $c = $row['component'];
            if (isset($byComponent[$c])) {
                $byComponent[$c]++;
            }
        }

        $componentSummary = [];
        foreach (self::COMPONENT_IDS as $id) {
            $b = $componentResults[$id];
            $codes = $b['active_issue_codes'];
            $componentSummary[$id] = [
                'severity' => $b['severity'],
                'active_issue_count' => count($codes),
                'active_issue_codes' => $codes,
            ];
        }

        return [
            'issue_counts_by_severity' => $bySeverity,
            'issue_counts_by_component' => $byComponent,
            'component_status_summary' => $componentSummary,
        ];
    }

    private function ksortRecursive(mixed &$data): void
    {
        if (!is_array($data)) {
            return;
        }
        if ($this->isAssocArray($data)) {
            ksort($data);
        }
        foreach ($data as &$child) {
            $this->ksortRecursive($child);
        }
        unset($child);
    }

    private function isAssocArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param array<string, array{severity: string, severity_reasons: list<string>, recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>, report: array}> $componentResults
     * @return array{0: array<string, array{severity: string, severity_reasons: list<string>, active_issue_codes: list<string>, recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>, report: array}>, 1: array{active_issue_codes: list<string>, active_issue_count: int, issue_inventory: list<array{code: string, severity: string, component: string, summary: string, recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>}>}}
     */
    private function attachIssueCatalog(array $componentResults): array
    {
        $union = [];
        $inventory = [];

        foreach (self::COMPONENT_IDS as $id) {
            $block = $componentResults[$id];
            $codes = $this->activeIssueCodesForComponent($id, $block['report']);
            $codes = $this->sortIssueCodes($codes);
            $block['active_issue_codes'] = $codes;
            $componentResults[$id] = $block;

            $checks = $block['recommended_next_checks'];
            foreach ($codes as $code) {
                $union[] = $code;
                $inventory[] = [
                    'code' => $code,
                    'severity' => $this->severityForIssueCode($code),
                    'component' => $id,
                    'summary' => $this->issueInventorySummary($code, $id, $block['report']),
                    'recommended_next_checks' => $checks,
                ];
            }
        }

        $active = $this->sortIssueCodes(array_values(array_unique($union)));

        return [
            $componentResults,
            [
                'active_issue_codes' => $active,
                'active_issue_count' => count($active),
                'issue_inventory' => $this->sortIssueInventory($inventory),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function activeIssueCodesForComponent(string $componentId, array $report): array
    {
        return match ($componentId) {
            'ledger_reconciliation' => ((int) ($report['mismatched_count'] ?? 0) > 0)
                ? [self::ISSUE_LEDGER_MISMATCH_PRESENT]
                : [],
            'global_sku_branch_attribution' => ((int) ($report['affected_movements_count'] ?? 0) > 0)
                ? [self::ISSUE_GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT]
                : [],
            'origin_classification' => $this->originIssueCodes($report),
            'reference_integrity' => $this->referenceIssueCodes($report),
            'classification_drift' => $this->classificationDriftIssueCodes($report),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function originIssueCodes(array $report): array
    {
        $codes = [];
        if ((int) ($report['movements_on_deleted_or_missing_product'] ?? 0) > 0) {
            $codes[] = self::ISSUE_DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT;
        }
        if ((int) (($report['counts_by_origin'] ?? [])['other_uncategorized'] ?? 0) > 0) {
            $codes[] = self::ISSUE_ORIGIN_OTHER_UNCATEGORIZED_PRESENT;
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function referenceIssueCodes(array $report): array
    {
        $counts = $report['counts_by_anomaly'] ?? [];
        foreach (ProductStockMovementReferenceIntegrityAuditService::ANOMALY_KEYS as $key) {
            if ((int) ($counts[$key] ?? 0) > 0) {
                return [self::ISSUE_REFERENCE_INTEGRITY_ANOMALIES_PRESENT];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function classificationDriftIssueCodes(array $report): array
    {
        $codes = [];
        if ((int) ($report['other_uncategorized_total'] ?? 0) > 0) {
            $codes[] = self::ISSUE_CLASSIFICATION_DRIFT_PRESENT;
        }
        if ((int) ($report['manual_operator_unexpected_movement_type_count'] ?? 0) > 0) {
            $codes[] = self::ISSUE_MANUAL_OPERATOR_UNEXPECTED_MOVEMENT_TYPE_PRESENT;
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function sortIssueCodes(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        $order = array_flip(self::ISSUE_CODE_ORDER);
        usort($codes, static function (string $a, string $b) use ($order): int {
            return ($order[$a] ?? 999) <=> ($order[$b] ?? 999);
        });

        return $codes;
    }

    /**
     * @param list<array{code: string, severity: string, component: string, summary: string, recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>}> $rows
     * @return list<array{code: string, severity: string, component: string, summary: string, recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>}>
     */
    private function sortIssueInventory(array $rows): array
    {
        $order = array_flip(self::ISSUE_CODE_ORDER);
        usort($rows, static function (array $x, array $y) use ($order): int {
            $cx = $order[$x['code']] ?? 999;
            $cy = $order[$y['code']] ?? 999;
            if ($cx !== $cy) {
                return $cx <=> $cy;
            }

            return strcmp($x['component'], $y['component']);
        });

        return $rows;
    }

    private function severityForIssueCode(string $code): string
    {
        return match ($code) {
            self::ISSUE_LEDGER_MISMATCH_PRESENT,
            self::ISSUE_DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT,
            self::ISSUE_REFERENCE_INTEGRITY_ANOMALIES_PRESENT => self::SEVERITY_CRITICAL,
            default => self::SEVERITY_WARN,
        };
    }

    private function issueInventorySummary(string $code, string $componentId, array $report): string
    {
        return match ($code) {
            self::ISSUE_LEDGER_MISMATCH_PRESENT => sprintf(
                'Ledger reconciliation: mismatched_count=%d (products.stock_quantity vs sum of movements beyond ε).',
                (int) ($report['mismatched_count'] ?? 0)
            ),
            self::ISSUE_GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT => sprintf(
                'Global SKU branch attribution: affected_movements_count=%d (contextual warn per inventory/ledger docs).',
                (int) ($report['affected_movements_count'] ?? 0)
            ),
            self::ISSUE_DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT => sprintf(
                'Origin classification: movements_on_deleted_or_missing_product=%d.',
                (int) ($report['movements_on_deleted_or_missing_product'] ?? 0)
            ),
            self::ISSUE_ORIGIN_OTHER_UNCATEGORIZED_PRESENT => sprintf(
                'Origin classification: counts_by_origin[other_uncategorized]=%d.',
                (int) (($report['counts_by_origin'] ?? [])['other_uncategorized'] ?? 0)
            ),
            self::ISSUE_REFERENCE_INTEGRITY_ANOMALIES_PRESENT => sprintf(
                'Reference integrity: non-zero anomaly buckets (sum of counts_by_anomaly values=%d).',
                $this->referenceAnomalySum($report)
            ),
            self::ISSUE_CLASSIFICATION_DRIFT_PRESENT => sprintf(
                'Classification drift: other_uncategorized_total=%d.',
                (int) ($report['other_uncategorized_total'] ?? 0)
            ),
            self::ISSUE_MANUAL_OPERATOR_UNEXPECTED_MOVEMENT_TYPE_PRESENT => sprintf(
                'Classification drift: manual_operator_unexpected_movement_type_count=%d.',
                (int) ($report['manual_operator_unexpected_movement_type_count'] ?? 0)
            ),
            default => sprintf('Stock quality issue (%s) on component %s.', $code, $componentId),
        };
    }

    private function referenceAnomalySum(array $report): int
    {
        $counts = $report['counts_by_anomaly'] ?? [];
        $sum = 0;
        foreach (ProductStockMovementReferenceIntegrityAuditService::ANOMALY_KEYS as $key) {
            $sum += (int) ($counts[$key] ?? 0);
        }

        return $sum;
    }

    /**
     * @param array<string, array{severity: string, severity_reasons: list<string>, report: array}> $componentResults
     * @return array{0: array<string, array{severity: string, severity_reasons: list<string>, recommended_next_checks: list<array{readonly_cli: string, ops_doc: string, intent: string}>, report: array}>, 1: list<array{readonly_cli: string, ops_doc: string, intent: string}>}
     */
    private function attachNextStepGuidance(array $componentResults): array
    {
        $dedupe = [];
        $ordered = [];

        foreach (self::COMPONENT_ORDER_FOR_NEXT_STEPS as $id) {
            $block = $componentResults[$id];
            $checks = $this->recommendedNextChecksFor($id, $block['severity'], $block['report']);
            $block['recommended_next_checks'] = $checks;
            $componentResults[$id] = $block;

            foreach ($checks as $step) {
                $k = $step['readonly_cli'] . "\0" . $step['ops_doc'];
                if (isset($dedupe[$k])) {
                    continue;
                }
                $dedupe[$k] = true;
                $ordered[] = $step;
            }
        }

        foreach ($componentResults as $id => $block) {
            if (in_array($id, self::COMPONENT_ORDER_FOR_NEXT_STEPS, true)) {
                continue;
            }
            $checks = $this->recommendedNextChecksFor($id, $block['severity'], $block['report']);
            $block['recommended_next_checks'] = $checks;
            $componentResults[$id] = $block;
            foreach ($checks as $step) {
                $k = $step['readonly_cli'] . "\0" . $step['ops_doc'];
                if (isset($dedupe[$k])) {
                    continue;
                }
                $dedupe[$k] = true;
                $ordered[] = $step;
            }
        }

        return [$componentResults, $ordered];
    }

    /**
     * Read-only investigation pointers only (existing CLIs + ops docs). Empty when healthy.
     *
     * @return list<array{readonly_cli: string, ops_doc: string, intent: string}>
     */
    private function recommendedNextChecksFor(string $componentId, string $severity, array $report): array
    {
        if ($severity === self::SEVERITY_HEALTHY) {
            return [];
        }

        return match ($componentId) {
            'ledger_reconciliation' => [[
                'readonly_cli' => 'php scripts/audit_product_stock_ledger_reconciliation.php',
                'ops_doc' => 'system/docs/PRODUCT-STOCK-LEDGER-RECONCILIATION-OPS.md',
                'intent' => 'Investigate products.stock_quantity vs SUM(stock_movements.quantity) mismatches; CLI prints capped examples.',
            ]],
            'global_sku_branch_attribution' => [[
                'readonly_cli' => 'php scripts/audit_product_global_sku_branch_attribution_readonly.php',
                'ops_doc' => 'system/modules/inventory/README.md',
                'intent' => 'Review capped movements/products; re-read invoice branch attribution caveat (warn is contextual, not automatic defect). Cross-check ledger ops for single stock_quantity truth.',
            ]],
            'reference_integrity' => [[
                'readonly_cli' => 'php scripts/audit_product_stock_movement_reference_integrity_readonly.php',
                'ops_doc' => 'system/docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md',
                'intent' => 'Investigate orphan reference targets and malformed reference_type/reference_id pairs; CLI prints capped examples.',
            ]],
            'classification_drift' => [[
                'readonly_cli' => 'php scripts/audit_product_stock_movement_classification_drift_readonly.php',
                'ops_doc' => 'system/docs/PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md',
                'intent' => 'Break down other_uncategorized and manual_operator_entry shape; compare with reference integrity for overlapping rows.',
            ]],
            'origin_classification' => $this->originClassificationNextChecks($severity, $report),
            default => [],
        };
    }

    /**
     * @return list<array{readonly_cli: string, ops_doc: string, intent: string}>
     */
    private function originClassificationNextChecks(string $severity, array $report): array
    {
        $steps = [[
            'readonly_cli' => 'php scripts/report_product_stock_movement_origin_classification_readonly.php',
            'ops_doc' => 'system/docs/PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md',
            'intent' => 'Re-run origin rollup for investigation context; use ops doc cross-links for drill-down order.',
        ]];

        $onBad = (int) ($report['movements_on_deleted_or_missing_product'] ?? 0);
        if ($onBad > 0) {
            $steps[] = [
                'readonly_cli' => 'php scripts/audit_product_stock_movement_reference_integrity_readonly.php',
                'ops_doc' => 'system/docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md',
                'intent' => 'Investigate movements tied to missing or soft-deleted products alongside referential integrity.',
            ];
        }

        $other = (int) (($report['counts_by_origin'] ?? [])['other_uncategorized'] ?? 0);
        if ($other > 0 || $severity === self::SEVERITY_WARN) {
            $steps[] = [
                'readonly_cli' => 'php scripts/audit_product_stock_movement_classification_drift_readonly.php',
                'ops_doc' => 'system/docs/PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md',
                'intent' => 'Drill into other_uncategorized reasons per drift ops (may overlap reference integrity).',
            ];
            $steps[] = [
                'readonly_cli' => 'php scripts/audit_product_stock_movement_reference_integrity_readonly.php',
                'ops_doc' => 'system/docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md',
                'intent' => 'Cross-check referential truth where origin rules and drift signals overlap.',
            ];
        }

        return $this->uniqueNextSteps($steps);
    }

    /**
     * @param list<array{readonly_cli: string, ops_doc: string, intent: string}> $steps
     * @return list<array{readonly_cli: string, ops_doc: string, intent: string}>
     */
    private function uniqueNextSteps(array $steps): array
    {
        $out = [];
        $seen = [];
        foreach ($steps as $s) {
            $k = $s['readonly_cli'] . "\0" . $s['ops_doc'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * @param array<string, array{severity: string, severity_reasons: list<string>, report: array}> $components
     * @return array{0: string, 1: string}
     */
    private function rollupOverall(array $components): array
    {
        $worst = self::SEVERITY_HEALTHY;
        $reasons = [];
        foreach ($components as $id => $block) {
            $s = $block['severity'];
            if ($this->severityRank($s) > $this->severityRank($worst)) {
                $worst = $s;
            }
        }
        foreach ($components as $id => $block) {
            if ($block['severity'] === $worst && $block['severity_reasons'] !== []) {
                foreach ($block['severity_reasons'] as $r) {
                    $reasons[] = $id . ': ' . $r;
                }
            }
        }
        if ($worst === self::SEVERITY_HEALTHY) {
            return [self::SEVERITY_HEALTHY, 'All five components are healthy per consolidated rules (see PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md).'];
        }
        $summary = match ($worst) {
            self::SEVERITY_CRITICAL => 'Critical — at least one component reports a blocking integrity or ledger-truth issue. Do not treat stock as trusted until investigated.',
            self::SEVERITY_WARN => 'Warn — no blocking ledger/reference failure under these rules, but at least one caveat or drift signal needs review.',
            default => 'See component_results.',
        };
        if ($reasons !== []) {
            $summary .= ' Details: ' . implode(' | ', array_slice($reasons, 0, 6));
            if (count($reasons) > 6) {
                $summary .= ' (+' . (count($reasons) - 6) . ' more)';
            }
        }

        return [$worst, $summary];
    }

    private function severityRank(string $s): int
    {
        $i = array_search($s, self::SEVERITY_ORDER, true);

        return $i === false ? 0 : (int) $i;
    }

    /**
     * @return array{severity: string, severity_reasons: list<string>, report: array}
     */
    private function evaluateLedgerReconciliation(array $report): array
    {
        $mismatched = (int) ($report['mismatched_count'] ?? 0);
        if ($mismatched > 0) {
            return [
                'severity' => self::SEVERITY_CRITICAL,
                'severity_reasons' => [
                    sprintf('mismatched_count=%d (products.stock_quantity vs SUM(stock_movements.quantity) beyond ε)', $mismatched),
                ],
                'report' => $report,
            ];
        }

        return [
            'severity' => self::SEVERITY_HEALTHY,
            'severity_reasons' => [],
            'report' => $report,
        ];
    }

    /**
     * @return array{severity: string, severity_reasons: list<string>, report: array}
     */
    private function evaluateGlobalSkuBranchAttribution(array $report): array
    {
        $n = (int) ($report['affected_movements_count'] ?? 0);
        if ($n > 0) {
            return [
                'severity' => self::SEVERITY_WARN,
                'severity_reasons' => [
                    sprintf(
                        'affected_movements_count=%d on global SKUs (branch_id on movement rows is attribution-only; expected under invoice settlement and branch operator context per ledger/inventory docs — not a defect by itself)',
                        $n
                    ),
                ],
                'report' => $report,
            ];
        }

        return [
            'severity' => self::SEVERITY_HEALTHY,
            'severity_reasons' => [],
            'report' => $report,
        ];
    }

    /**
     * @return array{severity: string, severity_reasons: list<string>, report: array}
     */
    private function evaluateOriginClassification(array $report): array
    {
        $reasons = [];
        $onBad = (int) ($report['movements_on_deleted_or_missing_product'] ?? 0);
        if ($onBad > 0) {
            $reasons[] = sprintf('movements_on_deleted_or_missing_product=%d (movements tied to missing or soft-deleted product)', $onBad);
        }
        $other = (int) (($report['counts_by_origin'] ?? [])['other_uncategorized'] ?? 0);
        if ($other > 0) {
            $reasons[] = sprintf('counts_by_origin[other_uncategorized]=%d (use classification drift + reference integrity audits for causes)', $other);
        }

        $severity = self::SEVERITY_HEALTHY;
        if ($onBad > 0) {
            $severity = self::SEVERITY_CRITICAL;
        } elseif ($other > 0) {
            $severity = self::SEVERITY_WARN;
        }

        return [
            'severity' => $severity,
            'severity_reasons' => $reasons,
            'report' => $report,
        ];
    }

    /**
     * @return array{severity: string, severity_reasons: list<string>, report: array}
     */
    private function evaluateReferenceIntegrity(array $report): array
    {
        $counts = $report['counts_by_anomaly'] ?? [];
        $reasons = [];
        foreach (ProductStockMovementReferenceIntegrityAuditService::ANOMALY_KEYS as $key) {
            $c = (int) ($counts[$key] ?? 0);
            if ($c > 0) {
                $reasons[] = sprintf('%s=%d', $key, $c);
            }
        }

        return [
            'severity' => $reasons === [] ? self::SEVERITY_HEALTHY : self::SEVERITY_CRITICAL,
            'severity_reasons' => $reasons,
            'report' => $report,
        ];
    }

    /**
     * @return array{severity: string, severity_reasons: list<string>, report: array}
     */
    private function evaluateClassificationDrift(array $report): array
    {
        $reasons = [];
        $otherTotal = (int) ($report['other_uncategorized_total'] ?? 0);
        if ($otherTotal > 0) {
            $reasons[] = sprintf('other_uncategorized_total=%d (rows not matching canonical origin buckets — see drift breakdown in report)', $otherTotal);
        }
        $unexpectedManual = (int) ($report['manual_operator_unexpected_movement_type_count'] ?? 0);
        if ($unexpectedManual > 0) {
            $reasons[] = sprintf(
                'manual_operator_unexpected_movement_type_count=%d (null reference pair but movement_type outside StockMovementService::MANUAL_ENTRY_MOVEMENT_TYPES — legacy/import/SQL per drift ops)',
                $unexpectedManual
            );
        }

        $severity = self::SEVERITY_HEALTHY;
        if ($unexpectedManual > 0 || $otherTotal > 0) {
            $severity = self::SEVERITY_WARN;
        }

        return [
            'severity' => $severity,
            'severity_reasons' => $reasons,
            'report' => $report,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Read-only preflight advisory from consolidated snapshot JSON (+ optional baseline). No DB, no writes.
 *
 * Policy is conservative ops guidance only — not stock/business truth. Rules:
 * {@see system/docs/PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md}.
 */
final class ProductStockQualityPreflightAdvisoryService
{
    public const ADVISORY_SCHEMA_VERSION = '1.0.0';

    public const DECISION_PROCEED = 'proceed';

    public const DECISION_REVIEW = 'review';

    public const DECISION_HOLD = 'hold';

    public const REASON_CURRENT_HEALTH_CRITICAL = 'CURRENT_HEALTH_CRITICAL';

    public const REASON_CURRENT_HEALTH_WARN_NO_BASELINE = 'CURRENT_HEALTH_WARN_NO_BASELINE';

    public const REASON_CURRENT_HEALTH_HEALTHY_NO_BASELINE = 'CURRENT_HEALTH_HEALTHY_NO_BASELINE';

    public const REASON_BASELINE_CONTRACT_CHANGED = 'BASELINE_CONTRACT_CHANGED';

    public const REASON_BASELINE_WORSENED = 'BASELINE_WORSENED';

    public const REASON_BASELINE_CHANGED_SAME_SEVERITY = 'BASELINE_CHANGED_SAME_SEVERITY';

    public const REASON_BASELINE_IMPROVED = 'BASELINE_IMPROVED';

    public const REASON_BASELINE_UNCHANGED_WARN = 'BASELINE_UNCHANGED_WARN';

    public const REASON_BASELINE_UNCHANGED_HEALTHY = 'BASELINE_UNCHANGED_HEALTHY';

    public function __construct(
        private ProductStockQualitySnapshotComparisonService $snapshotComparison,
    ) {
    }

    /**
     * @param array<string, mixed>              $currentSnapshot  Decoded consolidated JSON (current)
     * @param array<string, mixed>|null $baselineSnapshot Decoded consolidated JSON (checkpoint), or null
     * @return array<string, mixed>
     */
    public function evaluate(array $currentSnapshot, ?array $baselineSnapshot = null): array
    {
        $comparisonFull = null;
        $comparisonSummary = null;

        if ($baselineSnapshot !== null) {
            $comparisonFull = $this->snapshotComparison->compare($baselineSnapshot, $currentSnapshot);
            $comparisonSummary = $this->buildComparisonSummary($comparisonFull);
        } else {
            $this->snapshotComparison->validateConsolidatedSnapshot($currentSnapshot, 'current');
        }

        $current = $this->stripCliKeys($currentSnapshot);
        $health = (string) $current['overall_health_status'];
        $fingerprint = (string) $current['status_fingerprint'];

        [$decision, $reasonCodes] = $this->resolveDecision($health, $comparisonFull);

        $manualReview = $decision !== self::DECISION_PROCEED;

        return [
            'advisory_schema_version' => self::ADVISORY_SCHEMA_VERSION,
            'baseline_present' => $baselineSnapshot !== null,
            'advisory_decision' => $decision,
            'advisory_reason_codes' => $reasonCodes,
            'current_overall_health_status' => $health,
            'current_status_fingerprint' => $fingerprint,
            'comparison_summary' => $comparisonSummary,
            'recommended_manual_review' => $manualReview,
        ];
    }

    /**
     * @param array<string, mixed>|null $comparisonFull
     * @return array{0: string, 1: list<string>}
     */
    private function resolveDecision(string $currentHealth, ?array $comparisonFull): array
    {
        if ($currentHealth === ProductStockQualityConsolidatedAuditService::SEVERITY_CRITICAL) {
            return [self::DECISION_HOLD, [self::REASON_CURRENT_HEALTH_CRITICAL]];
        }

        if ($comparisonFull === null) {
            return match ($currentHealth) {
                ProductStockQualityConsolidatedAuditService::SEVERITY_HEALTHY => [
                    self::DECISION_PROCEED,
                    [self::REASON_CURRENT_HEALTH_HEALTHY_NO_BASELINE],
                ],
                default => [
                    self::DECISION_REVIEW,
                    [self::REASON_CURRENT_HEALTH_WARN_NO_BASELINE],
                ],
            };
        }

        $ocs = (string) $comparisonFull['overall_change_status'];

        return match ($ocs) {
            ProductStockQualitySnapshotComparisonService::CHANGE_CONTRACT_CHANGED => [
                self::DECISION_HOLD,
                [self::REASON_BASELINE_CONTRACT_CHANGED],
            ],
            ProductStockQualitySnapshotComparisonService::CHANGE_WORSENED => [
                self::DECISION_HOLD,
                [self::REASON_BASELINE_WORSENED],
            ],
            ProductStockQualitySnapshotComparisonService::CHANGE_CHANGED_SAME_SEVERITY => [
                self::DECISION_REVIEW,
                [self::REASON_BASELINE_CHANGED_SAME_SEVERITY],
            ],
            ProductStockQualitySnapshotComparisonService::CHANGE_IMPROVED => [
                self::DECISION_REVIEW,
                [self::REASON_BASELINE_IMPROVED],
            ],
            ProductStockQualitySnapshotComparisonService::CHANGE_UNCHANGED => match ($currentHealth) {
                ProductStockQualityConsolidatedAuditService::SEVERITY_HEALTHY => [
                    self::DECISION_PROCEED,
                    [self::REASON_BASELINE_UNCHANGED_HEALTHY],
                ],
                default => [
                    self::DECISION_REVIEW,
                    [self::REASON_BASELINE_UNCHANGED_WARN],
                ],
            },
            default => [
                self::DECISION_REVIEW,
                [self::REASON_BASELINE_CHANGED_SAME_SEVERITY],
            ],
        };
    }

    /**
     * @param array<string, mixed> $comparisonFull
     * @return array<string, mixed>
     */
    private function buildComparisonSummary(array $comparisonFull): array
    {
        return [
            'comparison_schema_version' => $comparisonFull['comparison_schema_version'],
            'overall_change_status' => $comparisonFull['overall_change_status'],
            'contract_compatible' => $comparisonFull['contract_compatible'],
            'fingerprint_changed' => $comparisonFull['fingerprint_changed'],
            'health_status_changed' => $comparisonFull['health_status_changed'],
            'issue_codes_added' => $comparisonFull['issue_codes_added'],
            'issue_codes_resolved' => $comparisonFull['issue_codes_resolved'],
            'persistent_issue_codes' => $comparisonFull['persistent_issue_codes'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stripCliKeys(array $row): array
    {
        unset($row['gate_policy'], $row['gate_result']);

        return $row;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Read-only comparison of two consolidated stock-quality JSON snapshots (no DB, no writes).
 *
 * Input: arrays decoded from {@code audit_product_stock_quality_consolidated_readonly.php --json}
 * (optional {@code gate_*} keys are ignored). Rules: {@see system/docs/PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md}.
 */
final class ProductStockQualitySnapshotComparisonService
{
    public const COMPARISON_SCHEMA_VERSION = '1.0.0';

    public const CHANGE_UNCHANGED = 'unchanged';

    public const CHANGE_IMPROVED = 'improved';

    public const CHANGE_WORSENED = 'worsened';

    public const CHANGE_CHANGED_SAME_SEVERITY = 'changed_same_severity';

    public const CHANGE_CONTRACT_CHANGED = 'contract_changed';

    /** @var list<string> */
    private const COMPONENT_IDS = [
        'ledger_reconciliation',
        'global_sku_branch_attribution',
        'origin_classification',
        'reference_integrity',
        'classification_drift',
    ];

    /**
     * @param array<string, mixed> $left  Earlier / baseline snapshot (decoded JSON)
     * @param array<string, mixed> $right Later / candidate snapshot (decoded JSON)
     * @return array<string, mixed>
     */
    public function compare(array $left, array $right): array
    {
        $left = $this->stripCliOnlyKeys($left);
        $right = $this->stripCliOnlyKeys($right);

        $this->assertSnapshotShape($left, 'left');
        $this->assertSnapshotShape($right, 'right');

        $leftSchema = (string) $left['schema_version'];
        $rightSchema = (string) $right['schema_version'];
        $contractCompatible = $this->contractCompatible($leftSchema, $rightSchema);

        $leftCodes = $this->sortCodesLikeCatalog($this->stringList($left['active_issue_codes'] ?? []));
        $rightCodes = $this->sortCodesLikeCatalog($this->stringList($right['active_issue_codes'] ?? []));
        $leftSet = array_fill_keys($leftCodes, true);
        $rightSet = array_fill_keys($rightCodes, true);

        $added = $this->sortCodesLikeCatalog(array_values(array_diff($rightCodes, $leftCodes)));
        $resolved = $this->sortCodesLikeCatalog(array_values(array_diff($leftCodes, $rightCodes)));
        $persistent = $this->sortCodesLikeCatalog(array_values(array_intersect($leftCodes, $rightCodes)));

        $fpLeft = (string) $left['status_fingerprint'];
        $fpRight = (string) $right['status_fingerprint'];
        $fingerprintChanged = $fpLeft !== $fpRight;

        $healthLeft = (string) $left['overall_health_status'];
        $healthRight = (string) $right['overall_health_status'];
        $healthStatusChanged = $healthLeft !== $healthRight;

        $componentChanges = $this->buildComponentChanges(
            $this->componentBlocks($left),
            $this->componentBlocks($right)
        );

        $overallChange = $this->resolveOverallChangeStatus(
            $contractCompatible,
            $healthLeft,
            $healthRight,
            $fpLeft,
            $fpRight
        );

        return [
            'comparison_schema_version' => self::COMPARISON_SCHEMA_VERSION,
            'left_schema_version' => $leftSchema,
            'right_schema_version' => $rightSchema,
            'contract_compatible' => $contractCompatible,
            'overall_change_status' => $overallChange,
            'fingerprint_changed' => $fingerprintChanged,
            'health_status_changed' => $healthStatusChanged,
            'issue_codes_added' => $added,
            'issue_codes_resolved' => $resolved,
            'persistent_issue_codes' => $persistent,
            'component_changes' => $componentChanges,
        ];
    }

    /**
     * Validates one consolidated snapshot shape (used when no pairwise compare runs).
     *
     * @param array<string, mixed> $snap
     */
    public function validateConsolidatedSnapshot(array $snap, string $label): void
    {
        $snap = $this->stripCliOnlyKeys($snap);
        $this->assertSnapshotShape($snap, $label);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stripCliOnlyKeys(array $row): array
    {
        unset($row['gate_policy'], $row['gate_result']);

        return $row;
    }

    /**
     * @param array<string, mixed> $snap
     */
    private function assertSnapshotShape(array $snap, string $label): void
    {
        foreach (['schema_version', 'overall_health_status', 'status_fingerprint', 'active_issue_codes', 'component_results'] as $key) {
            if (!array_key_exists($key, $snap)) {
                throw new \InvalidArgumentException("Snapshot [{$label}] missing required key: {$key}");
            }
        }
        if (!is_array($snap['active_issue_codes'])) {
            throw new \InvalidArgumentException("Snapshot [{$label}] active_issue_codes must be a JSON array");
        }
        if (!is_array($snap['component_results'])) {
            throw new \InvalidArgumentException("Snapshot [{$label}] component_results must be an object/map");
        }
        foreach (self::COMPONENT_IDS as $id) {
            if (!isset($snap['component_results'][$id]) || !is_array($snap['component_results'][$id])) {
                throw new \InvalidArgumentException("Snapshot [{$label}] missing component_results.{$id}");
            }
            $b = $snap['component_results'][$id];
            foreach (['severity', 'active_issue_codes', 'status_fingerprint'] as $k) {
                if (!array_key_exists($k, $b)) {
                    throw new \InvalidArgumentException("Snapshot [{$label}] component {$id} missing {$k}");
                }
            }
        }
    }

    private function contractCompatible(string $leftVersion, string $rightVersion): bool
    {
        $ml = $this->majorVersion($leftVersion);
        $mr = $this->majorVersion($rightVersion);

        return $ml !== '' && $ml === $mr;
    }

    private function majorVersion(string $v): string
    {
        $parts = explode('.', $v, 2);

        return $parts[0] ?? '';
    }

    private function resolveOverallChangeStatus(
        bool $contractCompatible,
        string $healthLeft,
        string $healthRight,
        string $fpLeft,
        string $fpRight,
    ): string {
        if (!$contractCompatible) {
            return self::CHANGE_CONTRACT_CHANGED;
        }

        $rl = $this->healthRank($healthLeft);
        $rr = $this->healthRank($healthRight);
        if ($rr > $rl) {
            return self::CHANGE_WORSENED;
        }
        if ($rr < $rl) {
            return self::CHANGE_IMPROVED;
        }

        return $fpLeft === $fpRight ? self::CHANGE_UNCHANGED : self::CHANGE_CHANGED_SAME_SEVERITY;
    }

    private function healthRank(string $s): int
    {
        $i = array_search($s, ProductStockQualityConsolidatedAuditService::SEVERITY_ORDER, true);

        return $i === false ? 0 : (int) $i;
    }

    /**
     * @param array<string, mixed> $snap
     * @return array<string, array<string, mixed>>
     */
    private function componentBlocks(array $snap): array
    {
        /** @var array<string, array<string, mixed>> $out */
        $out = [];
        foreach (self::COMPONENT_IDS as $id) {
            $out[$id] = $snap['component_results'][$id];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $leftBlocks
     * @param array<string, array<string, mixed>> $rightBlocks
     * @return array<string, array<string, mixed>>
     */
    private function buildComponentChanges(array $leftBlocks, array $rightBlocks): array
    {
        $out = [];
        foreach (self::COMPONENT_IDS as $id) {
            $lb = $leftBlocks[$id];
            $rb = $rightBlocks[$id];
            $sevL = (string) $lb['severity'];
            $sevR = (string) $rb['severity'];
            $codesL = $this->sortCodesLikeCatalog($this->stringList($lb['active_issue_codes'] ?? []));
            $codesR = $this->sortCodesLikeCatalog($this->stringList($rb['active_issue_codes'] ?? []));
            $fpL = (string) $lb['status_fingerprint'];
            $fpR = (string) $rb['status_fingerprint'];

            $out[$id] = [
                'severity_before' => $sevL,
                'severity_after' => $sevR,
                'severity_changed' => $sevL !== $sevR,
                'health_severity_rank_before' => $this->healthRank($sevL),
                'health_severity_rank_after' => $this->healthRank($sevR),
                'status_fingerprint_before' => $fpL,
                'status_fingerprint_after' => $fpR,
                'fingerprint_changed' => $fpL !== $fpR,
                'active_issue_count_before' => count($codesL),
                'active_issue_count_after' => count($codesR),
                'issue_codes_added' => $this->sortCodesLikeCatalog(array_values(array_diff($codesR, $codesL))),
                'issue_codes_resolved' => $this->sortCodesLikeCatalog(array_values(array_diff($codesL, $codesR))),
                'persistent_issue_codes' => $this->sortCodesLikeCatalog(array_values(array_intersect($codesL, $codesR))),
            ];
        }

        return $out;
    }

    /**
     * @param list<mixed> $list
     * @return list<string>
     */
    private function stringList(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            $out[] = (string) $v;
        }

        return $out;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function sortCodesLikeCatalog(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        $order = array_flip(ProductStockQualityConsolidatedAuditService::ISSUE_CODE_ORDER);
        usort($codes, static function (string $a, string $b) use ($order): int {
            $ca = $order[$a] ?? 999;
            $cb = $order[$b] ?? 999;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }

            return strcmp($a, $b);
        });

        return $codes;
    }
}

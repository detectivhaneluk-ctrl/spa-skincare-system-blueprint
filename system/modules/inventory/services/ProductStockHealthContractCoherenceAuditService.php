<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Read-only coherence audit: consolidated stock-health tooling invariants (proof-only).
 *
 * {@see system/docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md}
 */
final class ProductStockHealthContractCoherenceAuditService
{
    public const AUDIT_SCOPE = 'products_inventory_stock_health_contract_coherence';

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    /** @var list<string> */
    private const COMPONENT_IDS = [
        'ledger_reconciliation',
        'global_sku_branch_attribution',
        'origin_classification',
        'reference_integrity',
        'classification_drift',
    ];

    /** @var list<string> */
    private const ADVISORY_REASON_CATALOG = [
        ProductStockQualityPreflightAdvisoryService::REASON_CURRENT_HEALTH_CRITICAL,
        ProductStockQualityPreflightAdvisoryService::REASON_CURRENT_HEALTH_WARN_NO_BASELINE,
        ProductStockQualityPreflightAdvisoryService::REASON_CURRENT_HEALTH_HEALTHY_NO_BASELINE,
        ProductStockQualityPreflightAdvisoryService::REASON_BASELINE_CONTRACT_CHANGED,
        ProductStockQualityPreflightAdvisoryService::REASON_BASELINE_WORSENED,
        ProductStockQualityPreflightAdvisoryService::REASON_BASELINE_CHANGED_SAME_SEVERITY,
        ProductStockQualityPreflightAdvisoryService::REASON_BASELINE_IMPROVED,
        ProductStockQualityPreflightAdvisoryService::REASON_BASELINE_UNCHANGED_WARN,
        ProductStockQualityPreflightAdvisoryService::REASON_BASELINE_UNCHANGED_HEALTHY,
    ];

    public function __construct(
        private ProductStockQualityConsolidatedAuditService $consolidatedAudit,
        private ProductStockQualitySnapshotComparisonService $snapshotComparison,
        private ProductStockQualityPreflightAdvisoryService $preflightAdvisory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $payload = $this->consolidatedAudit->run();

        $catalog = array_flip(ProductStockQualityConsolidatedAuditService::ISSUE_CODE_ORDER);
        $issueInventory = $this->listOfArray($payload['issue_inventory'] ?? []);
        $activeCodes = $this->stringList($payload['active_issue_codes'] ?? []);
        $componentResults = $this->assocArray($payload['component_results'] ?? []);

        $invariants = [];

        $invariants[] = $this->checkCatalogForCodes(
            'A',
            'Every active/component issue code exists in canonical catalog',
            $this->collectAllIssueCodesFromSnapshot($payload),
            $catalog
        );

        $invariants[] = $this->checkCatalogForCodes(
            'B',
            'Every issue_inventory code exists in canonical catalog',
            $this->codesFromInventory($issueInventory),
            $catalog
        );

        $advisory = $this->preflightAdvisory->evaluate($payload, null);

        $invariants[] = $this->checkAdvisoryReasonCatalog('C', $advisory);

        $invariants[] = $this->checkNoOrphanIssueCodes('D', $payload, $catalog);

        $invariants[] = $this->checkIssueCounts('E', $payload, $issueInventory, $activeCodes);

        $invariants[] = $this->checkSeverityTotals('F', $payload, $issueInventory);

        $invariants[] = $this->checkFingerprints('G', $payload);

        $invariants[] = $this->checkIdenticalCompare('H', $payload);

        $invariants[] = $this->checkAdvisoryDerivable('I', $payload, $advisory);

        $invariants[] = $this->checkInvariantOrderingJ($invariants);

        usort($invariants, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        $failing = [];
        $warnings = [];
        foreach ($invariants as $inv) {
            if (($inv['status'] ?? '') === 'fail') {
                $failing[] = (string) $inv['id'];
            }
            if (($inv['status'] ?? '') === 'warn') {
                $warnings[] = (string) $inv['id'];
            }
        }

        $passed = 0;
        foreach ($invariants as $inv) {
            if (($inv['status'] ?? '') === 'pass') {
                $passed++;
            }
        }
        $failed = count($failing);
        $warned = count($warnings);
        $overall = $failed > 0 ? self::STATUS_FAIL : self::STATUS_PASS;

        $fpCanon = $this->fingerprintInputsSummary($payload);

        return $this->orderedPayload([
            'generated_at_utc' => gmdate('c'),
            'audit_scope' => self::AUDIT_SCOPE,
            'products_scanned' => (int) ($componentResults['ledger_reconciliation']['report']['products_scanned'] ?? 0),
            'rows_scanned' => $this->rowsScannedHint($payload),
            'invariant_results' => $invariants,
            'failing_invariants' => array_values($failing),
            'warning_invariants' => array_values($warnings),
            'passed_invariants_count' => $passed,
            'failed_invariants_count' => $failed,
            'warning_invariants_count' => $warned,
            'overall_status' => $overall,
            'recommended_next_step' => $failed > 0
                ? 'Review system/docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md and system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md; re-run consolidated audit after code/deploy alignment.'
                : 'No coherence failures; continue using consolidated audit + snapshot compare + preflight per runbooks.',
            'fingerprint_inputs_summary' => $fpCanon,
            'notes' => 'Read-only proof audit; does not repair data or change stock behavior.',
        ]);
    }

    /**
     * @param list<string> $codes
     * @param array<string, int> $catalog
     * @return array<string, mixed>
     */
    private function checkCatalogForCodes(string $id, string $label, array $codes, array $catalog): array
    {
        $bad = [];
        foreach ($codes as $c) {
            if (!isset($catalog[$c])) {
                $bad[] = $c;
            }
        }

        return [
            'id' => $id,
            'label' => $label,
            'status' => $bad === [] ? 'pass' : 'fail',
            'detail' => $bad === [] ? 'ok' : 'unknown_or_non_catalog_codes: ' . implode(', ', $bad),
        ];
    }

    /**
     * @param array<string, mixed> $advisory
     * @return array<string, mixed>
     */
    private function checkAdvisoryReasonCatalog(string $id, array $advisory): array
    {
        $allowed = array_flip(self::ADVISORY_REASON_CATALOG);
        $bad = [];
        foreach ($this->stringList($advisory['advisory_reason_codes'] ?? []) as $r) {
            if (!isset($allowed[$r])) {
                $bad[] = $r;
            }
        }

        return [
            'id' => $id,
            'label' => 'Preflight advisory_reason_codes are from canonical advisory catalog',
            'status' => $bad === [] ? 'pass' : 'fail',
            'detail' => $bad === [] ? 'ok' : 'unknown_reason_codes: ' . implode(', ', $bad),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int> $catalog
     * @return array<string, mixed>
     */
    private function checkNoOrphanIssueCodes(string $id, array $payload, array $catalog): array
    {
        $codes = $this->collectAllIssueCodesFromSnapshot($payload);

        return $this->checkCatalogForCodes($id, 'No orphan issue codes in consolidated snapshot surfaces', $codes, $catalog);
    }

    /**
     * @param list<array<string, mixed>> $issueInventory
     * @param list<string> $activeCodes
     * @return array<string, mixed>
     */
    private function checkIssueCounts(string $id, array $payload, array $issueInventory, array $activeCodes): array
    {
        $activeCount = (int) ($payload['active_issue_count'] ?? -1);
        $uniq = count($activeCodes);
        $invRows = count($issueInventory);
        $ok = $activeCount === $uniq && $activeCount === $invRows;

        return [
            'id' => $id,
            'label' => 'active_issue_count matches active_issue_codes length and issue_inventory row count',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok
                ? 'ok'
                : sprintf('active_issue_count=%d, unique_active_codes=%d, issue_inventory_rows=%d', $activeCount, $uniq, $invRows),
        ];
    }

    /**
     * @param list<array<string, mixed>> $issueInventory
     * @return array<string, mixed>
     */
    private function checkSeverityTotals(string $id, array $payload, array $issueInventory): array
    {
        $bySev = $this->assocArray($payload['issue_counts_by_severity'] ?? []);
        $byComp = $this->assocArray($payload['issue_counts_by_component'] ?? []);
        $sumSev = (int) ($bySev[ProductStockQualityConsolidatedAuditService::SEVERITY_CRITICAL] ?? 0)
            + (int) ($bySev[ProductStockQualityConsolidatedAuditService::SEVERITY_WARN] ?? 0);
        $sumComp = array_sum(array_map('intval', $byComp));
        $rows = count($issueInventory);
        $ok = $sumSev === $rows && $sumComp === $rows;

        $summary = $this->assocArray($payload['component_status_summary'] ?? []);
        $summaryOk = true;
        $detailExtra = '';
        foreach (self::COMPONENT_IDS as $cid) {
            $block = $this->assocArray($summary[$cid] ?? []);
            $codes = $this->stringList($block['active_issue_codes'] ?? []);
            $cnt = (int) ($block['active_issue_count'] ?? -1);
            if ($cnt !== count($codes)) {
                $summaryOk = false;
                $detailExtra = "component_status_summary.{$cid} active_issue_count mismatch";
                break;
            }
        }

        $pass = $ok && $summaryOk;

        return [
            'id' => $id,
            'label' => 'Severity/component normalized summaries reconcile with issue_inventory',
            'status' => $pass ? 'pass' : 'fail',
            'detail' => $pass
                ? 'ok'
                : ($detailExtra !== '' ? $detailExtra : sprintf(
                    'sum_severity=%d sum_component=%d inventory_rows=%d',
                    $sumSev,
                    $sumComp,
                    $rows
                )),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function checkFingerprints(string $id, array $payload): array
    {
        $fp1 = $this->recomputeOverallFingerprint($payload);
        $fp2 = $this->recomputeOverallFingerprint($payload);
        $topOk = $fp1 === $fp2 && $fp1 === (string) ($payload['status_fingerprint'] ?? '');

        $compOk = true;
        $detail = '';
        foreach (self::COMPONENT_IDS as $cid) {
            $block = $this->assocArray($payload['component_results'][$cid] ?? []);
            $sev = (string) ($block['severity'] ?? '');
            $codes = $this->stringList($block['active_issue_codes'] ?? []);
            $c1 = $this->recomputeComponentFingerprint($sev, $codes);
            $c2 = $this->recomputeComponentFingerprint($sev, $codes);
            $stored = (string) ($block['status_fingerprint'] ?? '');
            if ($c1 !== $c2 || $c1 !== $stored) {
                $compOk = false;
                $detail = "component {$cid} fingerprint mismatch or non-deterministic recompute";
                break;
            }
        }

        $ok = $topOk && $compOk;

        return [
            'id' => $id,
            'label' => 'Fingerprint generation deterministic and matches payload (recomputed twice)',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok ? 'ok' : ($detail !== '' ? $detail : 'overall status_fingerprint mismatch'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function checkIdenticalCompare(string $id, array $payload): array
    {
        $cmp = $this->snapshotComparison->compare($payload, $payload);
        $added = $this->stringList($cmp['issue_codes_added'] ?? []);
        $resolved = $this->stringList($cmp['issue_codes_resolved'] ?? []);
        $unchanged = ($cmp['overall_change_status'] ?? '') === ProductStockQualitySnapshotComparisonService::CHANGE_UNCHANGED;
        $ok = $added === [] && $resolved === [] && $unchanged;

        return [
            'id' => $id,
            'label' => 'Snapshot comparison on identical snapshots is stable (unchanged, no code deltas)',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok ? 'ok' : json_encode([
                'overall_change_status' => $cmp['overall_change_status'] ?? null,
                'issue_codes_added' => $added,
                'issue_codes_resolved' => $resolved,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $advisory
     * @return array<string, mixed>
     */
    private function checkAdvisoryDerivable(string $id, array $payload, array $advisory): array
    {
        $health = (string) ($payload['overall_health_status'] ?? '');
        $decision = (string) ($advisory['advisory_decision'] ?? '');
        $manual = $advisory['recommended_manual_review'] ?? null;
        $summary = $advisory['comparison_summary'] ?? 'non_null';

        $expectDecision = match ($health) {
            ProductStockQualityConsolidatedAuditService::SEVERITY_CRITICAL => ProductStockQualityPreflightAdvisoryService::DECISION_HOLD,
            ProductStockQualityConsolidatedAuditService::SEVERITY_HEALTHY => ProductStockQualityPreflightAdvisoryService::DECISION_PROCEED,
            default => ProductStockQualityPreflightAdvisoryService::DECISION_REVIEW,
        };
        $expectManual = $expectDecision !== ProductStockQualityPreflightAdvisoryService::DECISION_PROCEED;

        $ok = $decision === $expectDecision
            && $manual === $expectManual
            && $summary === null;

        return [
            'id' => $id,
            'label' => 'Preflight advisory (no baseline) derivable from current health; comparison_summary null; manual_review aligns',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok ? 'ok' : json_encode([
                'expected_decision' => $expectDecision,
                'actual_decision' => $decision,
                'expected_manual_review' => $expectManual,
                'actual_manual_review' => $manual,
                'comparison_summary' => $summary,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param list<array<string, mixed>> $invariantsWithoutJ
     * @return array<string, mixed>
     */
    private function checkInvariantOrderingJ(array $invariantsWithoutJ): array
    {
        $ids = array_map(static fn (array $i): string => (string) ($i['id'] ?? ''), $invariantsWithoutJ);
        $withJ = array_merge($ids, ['J']);
        $sorted = $withJ;
        sort($sorted, SORT_STRING);
        $ok = $withJ === $sorted;

        return [
            'id' => 'J',
            'label' => 'Invariant ids (including J) are lexicographically orderable for diff-stable reporting',
            'status' => $ok ? 'pass' : 'fail',
            'detail' => $ok ? 'ok' : 'ids_order=' . implode(',', $ids),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function collectAllIssueCodesFromSnapshot(array $payload): array
    {
        $out = [];
        foreach ($this->stringList($payload['active_issue_codes'] ?? []) as $c) {
            $out[] = $c;
        }
        foreach ($this->codesFromInventory($this->listOfArray($payload['issue_inventory'] ?? [])) as $c) {
            $out[] = $c;
        }
        $comp = $this->assocArray($payload['component_results'] ?? []);
        foreach (self::COMPONENT_IDS as $cid) {
            foreach ($this->stringList($comp[$cid]['active_issue_codes'] ?? []) as $c) {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<array<string, mixed>> $inv
     * @return list<string>
     */
    private function codesFromInventory(array $inv): array
    {
        $out = [];
        foreach ($inv as $row) {
            $r = $this->assocArray($row);
            if (isset($r['code'])) {
                $out[] = (string) $r['code'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recomputeOverallFingerprint(array $payload): string
    {
        $issueInventory = $this->listOfArray($payload['issue_inventory'] ?? []);
        $activeCodes = $this->stringList($payload['active_issue_codes'] ?? []);
        $activeCount = (int) ($payload['active_issue_count'] ?? 0);
        $overall = (string) ($payload['overall_health_status'] ?? '');
        $componentResults = $this->assocArray($payload['component_results'] ?? []);

        $inventorySlice = [];
        foreach ($issueInventory as $row) {
            $r = $this->assocArray($row);
            $slice = [
                'code' => (string) ($r['code'] ?? ''),
                'component' => (string) ($r['component'] ?? ''),
                'severity' => (string) ($r['severity'] ?? ''),
            ];
            ksort($slice);
            $inventorySlice[] = $slice;
        }

        $components = [];
        foreach (self::COMPONENT_IDS as $cid) {
            $b = $this->assocArray($componentResults[$cid] ?? []);
            $block = [
                'active_issue_codes' => $this->stringList($b['active_issue_codes'] ?? []),
                'severity' => (string) ($b['severity'] ?? ''),
            ];
            ksort($block);
            $components[$cid] = $block;
        }
        ksort($components);

        $canonical = [
            'active_issue_codes' => $activeCodes,
            'active_issue_count' => $activeCount,
            'components' => $components,
            'issue_inventory' => $inventorySlice,
            'overall_health_status' => $overall,
        ];
        $this->ksortRecursive($canonical);

        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? hash('sha256', 'encode_error') : hash('sha256', $json);
    }

    /**
     * @param list<string> $activeIssueCodes
     */
    private function recomputeComponentFingerprint(string $severity, array $activeIssueCodes): string
    {
        $canonical = [
            'active_issue_codes' => $activeIssueCodes,
            'severity' => $severity,
        ];
        ksort($canonical);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? hash('sha256', 'encode_error') : hash('sha256', $json);
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
     * @param array<string, mixed> $payload
     */
    private function fingerprintInputsSummary(array $payload): string
    {
        $keys = [
            'schema_version',
            'overall_health_status',
            'active_issue_count',
            'status_fingerprint',
            'issue_counts_by_severity',
            'issue_counts_by_component',
        ];
        $slice = [];
        foreach ($keys as $k) {
            $slice[$k] = $payload[$k] ?? null;
        }

        return json_encode($slice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rowsScannedHint(array $payload): int
    {
        $comp = $this->assocArray($payload['component_results'] ?? []);
        $origin = (int) ($this->assocArray($comp['origin_classification']['report'] ?? [])['total_movements'] ?? 0);
        $ref = (int) ($this->assocArray($comp['reference_integrity']['report'] ?? [])['total_movements'] ?? 0);

        return max($origin, $ref);
    }

    /**
     * @param array<string, mixed> $assoc
     * @return array<string, mixed>
     */
    private function orderedPayload(array $assoc): array
    {
        ksort($assoc);

        return $assoc;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfArray(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function assocArray(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            $out[] = (string) $x;
        }

        return $out;
    }
}

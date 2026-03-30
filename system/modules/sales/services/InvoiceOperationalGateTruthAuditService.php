<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

/**
 * Read-only audit: single **invoice-level operational gate** from accepted WAVE-02–04 stored facts only.
 *
 * Composes {@see SalesLineInventoryImpactTruthAuditService}, {@see SalesLineLifecycleConsistencyTruthAuditService},
 * and {@see InvoiceDomainCompositionTruthAuditService} outputs (each {@code run()} is invoked; no new SQL here).
 *
 * Does **not** implement mixed-sales behavior; no writes.
 *
 * Wave: {@code MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-05}.
 */
final class InvoiceOperationalGateTruthAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AUDIT_SCHEMA_VERSION = 1;

    public const OPERATIONAL_GATE_CLASSES = [
        'operationally_clear_invoice',
        'manual_review_required_invoice',
        'blocked_by_lifecycle_anomalies',
        'blocked_by_inventory_contradictions',
        'unusable_invoice_operational_state',
        'ambiguous_invoice_operational_story',
    ];

    /** @var list<string> */
    private const INVENTORY_IMPACT_CLEAN = [
        'retail_line_with_expected_inventory_impact',
        'service_like_line_with_no_inventory_impact',
    ];

    /** @var list<string> */
    private const LIFECYCLE_CLEAN = [
        'lifecycle_consistent_retail_line',
        'lifecycle_consistent_service_like_line',
    ];

    /** @var list<string> */
    private const INVOICE_DOMAIN_CLEAN = [
        'clean_service_only_invoice',
        'clean_retail_only_invoice',
        'clean_mixed_domain_invoice',
    ];

    public function __construct(
        private SalesLineInventoryImpactTruthAuditService $inventoryImpactAudit,
        private SalesLineLifecycleConsistencyTruthAuditService $lifecycleConsistencyAudit,
        private InvoiceDomainCompositionTruthAuditService $invoiceDomainCompositionAudit
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('c');

        $inventoryPayload = $this->inventoryImpactAudit->run($invoiceIdFilter);
        $lifecyclePayload = $this->lifecycleConsistencyAudit->run($invoiceIdFilter);
        $compositionPayload = $this->invoiceDomainCompositionAudit->run($invoiceIdFilter);

        $rawLines = $lifecyclePayload['lines'] ?? [];
        $linesByInvoice = $this->groupLifecycleLinesByInvoice(is_array($rawLines) ? $rawLines : []);
        $compositionInvoices = $compositionPayload['invoices'] ?? [];
        if (!is_array($compositionInvoices)) {
            $compositionInvoices = [];
        }

        $gateCounts = array_fill_keys(self::OPERATIONAL_GATE_CLASSES, 0);
        $examples = [];
        foreach (self::OPERATIONAL_GATE_CLASSES as $c) {
            $examples[$c] = [];
        }

        $invoices = [];
        $blockedIds = [];
        $affectedIds = [];

        foreach ($compositionInvoices as $compRow) {
            if (!is_array($compRow)) {
                continue;
            }
            $invoiceId = (int) ($compRow['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }

            $lineRows = $linesByInvoice[$invoiceId] ?? [];
            $stats = $this->computeLineStatsFromLifecycleRows($lineRows);

            $idc = (string) ($compRow['invoice_domain_composition_class'] ?? '');
            $laComp = (int) ($compRow['lifecycle_anomaly_line_count'] ?? 0);
            $invAff = (int) ($compRow['inventory_affecting_line_count'] ?? 0);

            $mismatchReasons = [];
            if ($laComp !== $stats['lifecycle_anomaly_line_count']) {
                $mismatchReasons[] = 'operational_gate_lifecycle_anomaly_count_mismatch_composition_vs_line_group';
            }
            if ($invAff !== $stats['inventory_affecting_line_count']) {
                $mismatchReasons[] = 'operational_gate_inventory_affecting_count_mismatch_composition_vs_line_group';
            }

            $idcMismatch = $this->compositionClassMismatchWithLineStats($idc, $stats);
            foreach ($idcMismatch as $r) {
                $mismatchReasons[] = $r;
            }

            [$gateClass, $gateReasons] = $this->resolveOperationalGateClass(
                $idc,
                $stats['lifecycle_anomaly_line_count'],
                $stats['inventory_contradiction_line_count'],
                $stats['orphaned_or_unsupported_line_count'],
                $mismatchReasons
            );

            $domainReasons = $compRow['reason_codes'] ?? [];
            if (!is_array($domainReasons)) {
                $domainReasons = [];
            }
            $reasonCodes = $this->mergeReasonCodes($domainReasons, $gateReasons);

            $row = [
                'invoice_id' => $invoiceId,
                'invoice_status' => (string) ($compRow['invoice_status'] ?? ''),
                'invoice_branch_id' => $compRow['invoice_branch_id'] ?? null,
                'line_count' => (int) ($compRow['line_count'] ?? 0),
                'invoice_domain_composition_class' => $idc,
                'lifecycle_anomaly_line_count' => $stats['lifecycle_anomaly_line_count'],
                'inventory_contradiction_line_count' => $stats['inventory_contradiction_line_count'],
                'orphaned_or_unsupported_line_count' => $stats['orphaned_or_unsupported_line_count'],
                'inventory_affecting_line_count' => $stats['inventory_affecting_line_count'],
                'operational_gate_class' => $gateClass,
                'reason_codes' => $reasonCodes,
            ];

            $invoices[] = $row;

            if (isset($gateCounts[$gateClass])) {
                $gateCounts[$gateClass]++;
            }

            if (in_array($gateClass, ['blocked_by_lifecycle_anomalies', 'blocked_by_inventory_contradictions'], true)) {
                $blockedIds[$invoiceId] = true;
            }
            if ($gateClass !== 'operationally_clear_invoice') {
                $affectedIds[$invoiceId] = true;
            }

            if (count($examples[$gateClass]) < self::EXAMPLE_CAP) {
                $examples[$gateClass][] = $row;
            }
        }

        $blockedInvoices = count($blockedIds);
        $affectedInvoices = count($affectedIds);
        $sampleIds = array_keys($affectedIds);
        sort($sampleIds, SORT_NUMERIC);
        $sampleIds = array_slice($sampleIds, 0, 20);

        $notes = [
            'Each underlying audit run() is invoked (WAVE-02, WAVE-03, WAVE-04); this service adds no SQL.',
            'Per-invoice line counters are derived from SalesLineLifecycleConsistencyTruthAuditService line rows grouped by invoice_id; lifecycle_anomaly_line_count matches non-lifecycle_consistent_* lines.',
            'inventory_contradiction_line_count counts lines whose inventory_impact_class is outside the WAVE-02 clean pair (retail_line_with_expected_inventory_impact, service_like_line_with_no_inventory_impact).',
            'orphaned_or_unsupported_line_count counts lines with orphaned/unsupported domain, inventory_impact, or lifecycle_consistency class (same line-level predicate as WAVE-04 invoice orphan detection).',
            'operational_gate_class is conservative: coexisting lifecycle and inventory contradiction families map to ambiguous_invoice_operational_story; composition vs recomputed line counts or WAVE-04 class vs counts also map to ambiguous.',
            'Read-only: does not repair data and does not implement mixed-sales / service-consumption behavior.',
        ];

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'invoice_id_filter' => $invoiceIdFilter,
            'invoices_scanned' => count($invoices),
            'operational_gate_class_counts' => $gateCounts,
            'blocked_invoices_count' => $blockedInvoices,
            'affected_invoices_count' => $affectedInvoices,
            'affected_invoice_ids_sample' => $sampleIds,
            'examples_by_operational_gate_class' => $examples,
            'notes' => $notes,
            'invoices' => $invoices,
            'composed_sales_line_inventory_audit_schema_version' => $inventoryPayload['audit_schema_version'] ?? null,
            'composed_sales_line_lifecycle_audit_schema_version' => $lifecyclePayload['audit_schema_version'] ?? null,
            'composed_invoice_domain_composition_audit_schema_version' => $compositionPayload['audit_schema_version'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupLifecycleLinesByInvoice(array $lines): array
    {
        $by = [];
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $iid = (int) ($ln['invoice_id'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            if (!isset($by[$iid])) {
                $by[$iid] = [];
            }
            $by[$iid][] = $ln;
        }
        ksort($by, SORT_NUMERIC);

        return $by;
    }

    /**
     * @param list<array<string, mixed>> $lineRows
     * @return array{
     *   lifecycle_anomaly_line_count: int,
     *   inventory_contradiction_line_count: int,
     *   orphaned_or_unsupported_line_count: int,
     *   inventory_affecting_line_count: int
     * }
     */
    private function computeLineStatsFromLifecycleRows(array $lineRows): array
    {
        $la = 0;
        $ic = 0;
        $ou = 0;
        $invAff = 0;

        foreach ($lineRows as $ln) {
            $life = (string) ($ln['lifecycle_consistency_class'] ?? '');
            if (!in_array($life, self::LIFECYCLE_CLEAN, true)) {
                $la++;
            }

            $impact = (string) ($ln['inventory_impact_class'] ?? '');
            if (!in_array($impact, self::INVENTORY_IMPACT_CLEAN, true)) {
                $ic++;
            }
            if ($impact !== 'service_like_line_with_no_inventory_impact') {
                $invAff++;
            }

            if ($this->lineHasOrphanedOrUnsupportedTruth($ln)) {
                $ou++;
            }
        }

        return [
            'lifecycle_anomaly_line_count' => $la,
            'inventory_contradiction_line_count' => $ic,
            'orphaned_or_unsupported_line_count' => $ou,
            'inventory_affecting_line_count' => $invAff,
        ];
    }

    /**
     * @param array<string, mixed> $ln
     */
    private function lineHasOrphanedOrUnsupportedTruth(array $ln): bool
    {
        $d = (string) ($ln['line_domain_class'] ?? '');
        if ($d === 'orphaned_domain_reference' || $d === 'unsupported_line_contract') {
            return true;
        }
        $i = (string) ($ln['inventory_impact_class'] ?? '');
        if ($i === 'orphaned_inventory_impact_story' || $i === 'unsupported_inventory_contract') {
            return true;
        }
        $l = (string) ($ln['lifecycle_consistency_class'] ?? '');
        if ($l === 'orphaned_lifecycle_story' || $l === 'unsupported_lifecycle_contract') {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function compositionClassMismatchWithLineStats(string $idc, array $stats): array
    {
        $la = $stats['lifecycle_anomaly_line_count'];
        $ic = $stats['inventory_contradiction_line_count'];
        $ou = $stats['orphaned_or_unsupported_line_count'];
        $out = [];

        if ($idc === 'mixed_invoice_with_lifecycle_anomalies' && $la === 0) {
            $out[] = 'operational_gate_invoice_domain_composition_claims_lifecycle_anomalies_but_line_count_is_zero';
        }
        if ($idc === 'mixed_invoice_with_inventory_contradictions' && $ic === 0) {
            $out[] = 'operational_gate_invoice_domain_composition_claims_inventory_contradictions_but_line_count_is_zero';
        }
        if ($idc === 'orphaned_or_unsupported_invoice_story' && $ou === 0) {
            $out[] = 'operational_gate_invoice_domain_composition_claims_orphaned_story_but_orphan_line_count_is_zero';
        }

        return $out;
    }

    /**
     * @param list<string> $compositionMismatchReasons structural mismatches between WAVE-04 class and recomputed counts
     * @return array{0: string, 1: list<string>}
     */
    private function resolveOperationalGateClass(
        string $invoiceDomainCompositionClass,
        int $lifecycleAnomalyLineCount,
        int $inventoryContradictionLineCount,
        int $orphanedOrUnsupportedLineCount,
        array $compositionMismatchReasons
    ): array {
        $idc = $invoiceDomainCompositionClass;
        $reasons = [];

        if ($compositionMismatchReasons !== []) {
            foreach ($compositionMismatchReasons as $r) {
                if (is_string($r) && $r !== '') {
                    $reasons[] = $r;
                }
            }

            return ['ambiguous_invoice_operational_story', $reasons];
        }

        $unusable = $idc === 'orphaned_or_unsupported_invoice_story' || $orphanedOrUnsupportedLineCount > 0;
        if ($unusable) {
            if ($idc === 'orphaned_or_unsupported_invoice_story') {
                $reasons[] = 'operational_gate_unusable_invoice_domain_composition_orphaned_or_unsupported_story';
            }
            if ($orphanedOrUnsupportedLineCount > 0) {
                $reasons[] = 'operational_gate_unusable_due_to_orphaned_or_unsupported_line_truth_no_safe_operational_story';
            }

            return ['unusable_invoice_operational_state', $reasons];
        }

        if ($lifecycleAnomalyLineCount > 0 && $inventoryContradictionLineCount > 0) {
            $reasons[] = 'operational_gate_coexisting_lifecycle_anomaly_and_inventory_contradiction_families';

            return ['ambiguous_invoice_operational_story', $reasons];
        }

        if ($inventoryContradictionLineCount > 0) {
            $reasons[] = 'operational_gate_blocked_due_to_inventory_contradiction_lines';

            return ['blocked_by_inventory_contradictions', $reasons];
        }

        if ($lifecycleAnomalyLineCount > 0) {
            $reasons[] = 'operational_gate_blocked_due_to_lifecycle_anomaly_lines';

            return ['blocked_by_lifecycle_anomalies', $reasons];
        }

        if (in_array($idc, self::INVOICE_DOMAIN_CLEAN, true)
            && $lifecycleAnomalyLineCount === 0
            && $inventoryContradictionLineCount === 0
            && $orphanedOrUnsupportedLineCount === 0) {
            $reasons[] = 'operational_gate_clear_clean_domain_composition_and_clean_line_families';

            return ['operationally_clear_invoice', $reasons];
        }

        if ($idc === 'ambiguous_invoice_domain_story') {
            $reasons[] = 'operational_gate_manual_review_domain_story_ambiguous_without_blocking_line_families';

            return ['manual_review_required_invoice', $reasons];
        }

        $reasons[] = 'operational_gate_fallback_ambiguous_invoice_operational_story';

        return ['ambiguous_invoice_operational_story', $reasons];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function mergeReasonCodes(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $r) {
            if (!is_string($r) || $r === '') {
                continue;
            }
            $out[] = $r;
        }
        foreach ($b as $r) {
            if (!is_string($r) || $r === '') {
                continue;
            }
            if (!in_array($r, $out, true)) {
                $out[] = $r;
            }
        }

        return $out;
    }
}

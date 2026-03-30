<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

/**
 * Read-only audit: aggregate **invoice-level** domain composition from accepted WAVE-03 line rows.
 *
 * {@see SalesLineLifecycleConsistencyTruthAuditService} already composes WAVE-01 (domain boundary) and
 * WAVE-02 (inventory impact); this service groups those lines by {@code invoice_id} without re-querying.
 *
 * Does **not** implement mixed-sales behavior; no writes.
 *
 * Wave: {@code MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-04}.
 */
final class InvoiceDomainCompositionTruthAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AUDIT_SCHEMA_VERSION = 1;

    public const INVOICE_DOMAIN_SHAPES = [
        'service_only_lines',
        'retail_only_lines',
        'mixed_service_and_retail_lines',
        'no_clear_domain_lines',
        'unsupported_invoice_shape',
    ];

    public const INVOICE_DOMAIN_COMPOSITION_CLASSES = [
        'clean_service_only_invoice',
        'clean_retail_only_invoice',
        'clean_mixed_domain_invoice',
        'mixed_invoice_with_lifecycle_anomalies',
        'mixed_invoice_with_inventory_contradictions',
        'orphaned_or_unsupported_invoice_story',
        'ambiguous_invoice_domain_story',
    ];

    private const DOMAIN_CLEAR_SERVICE = 'clear_service_line';

    private const DOMAIN_CLEAR_RETAIL = 'clear_retail_product_line';

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

    public function __construct(
        private SalesLineLifecycleConsistencyTruthAuditService $lifecycleConsistencyAudit
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('c');
        $lineReport = $this->lifecycleConsistencyAudit->run($invoiceIdFilter);
        $lineRows = $lineReport['lines'] ?? [];
        if (!is_array($lineRows)) {
            $lineRows = [];
        }

        $byInvoice = [];
        foreach ($lineRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $iid = (int) ($row['invoice_id'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            if (!isset($byInvoice[$iid])) {
                $byInvoice[$iid] = [];
            }
            $byInvoice[$iid][] = $row;
        }
        ksort($byInvoice, SORT_NUMERIC);

        $shapeCounts = array_fill_keys(self::INVOICE_DOMAIN_SHAPES, 0);
        $compositionCounts = array_fill_keys(self::INVOICE_DOMAIN_COMPOSITION_CLASSES, 0);
        $examples = [];
        foreach (self::INVOICE_DOMAIN_COMPOSITION_CLASSES as $c) {
            $examples[$c] = [];
        }

        $invoices = [];
        $affectedInvoiceIds = [];

        foreach ($byInvoice as $invoiceId => $lines) {
            $agg = $this->buildInvoiceAggregate($invoiceId, $lines);
            $invoices[] = $agg;

            $shape = (string) ($agg['invoice_domain_shape'] ?? '');
            if (isset($shapeCounts[$shape])) {
                $shapeCounts[$shape]++;
            }

            $comp = (string) ($agg['invoice_domain_composition_class'] ?? '');
            if (isset($compositionCounts[$comp])) {
                $compositionCounts[$comp]++;
            }

            if (!in_array($comp, ['clean_service_only_invoice', 'clean_retail_only_invoice', 'clean_mixed_domain_invoice'], true)) {
                $affectedInvoiceIds[$invoiceId] = true;
            }

            if (count($examples[$comp]) < self::EXAMPLE_CAP) {
                $examples[$comp][] = $agg;
            }
        }

        $affectedInvoices = count($affectedInvoiceIds);
        $sampleIds = array_keys($affectedInvoiceIds);
        sort($sampleIds, SORT_NUMERIC);
        $sampleIds = array_slice($sampleIds, 0, 20);

        $notes = [
            'Invoice rows are derived only from non-deleted invoices that have at least one invoice_items row in WAVE-01 scope; invoices with zero lines do not appear.',
            'Line truth is exactly SalesLineLifecycleConsistencyTruthAuditService output (WAVE-01 + WAVE-02 + WAVE-03); this service performs no additional SQL.',
            'invoice_domain_shape describes stored line_domain_class geometry only; invoice_domain_composition_class adds inventory_impact_class and lifecycle_consistency_class cleanliness.',
            'inventory_affecting_line_count counts lines whose inventory_impact_class is not service_like_line_with_no_inventory_impact (any non–pure-service no-ledger story).',
            'lifecycle_anomaly_line_count counts lines whose lifecycle_consistency_class is not one of the two lifecycle_consistent_* classes.',
            'Read-only: no repairs and no mixed-sales implementation.',
        ];

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'invoice_id_filter' => $invoiceIdFilter,
            'invoices_scanned' => count($invoices),
            'invoice_domain_shape_counts' => $shapeCounts,
            'invoice_domain_composition_class_counts' => $compositionCounts,
            'affected_invoices_count' => $affectedInvoices,
            'affected_invoice_ids_sample' => $sampleIds,
            'examples_by_invoice_domain_composition_class' => $examples,
            'notes' => $notes,
            'invoices' => $invoices,
            'composed_lifecycle_audit_schema_version' => $lineReport['audit_schema_version'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function buildInvoiceAggregate(int $invoiceId, array $lines): array
    {
        $first = $lines[0];
        $status = (string) ($first['invoice_status'] ?? '');
        $branchId = isset($first['invoice_branch_id']) && $first['invoice_branch_id'] !== null && $first['invoice_branch_id'] !== ''
            ? (int) $first['invoice_branch_id']
            : null;

        $lineCount = count($lines);
        $clearService = 0;
        $clearRetail = 0;
        $mixedOrAmbiguous = 0;
        $inventoryAffecting = 0;
        $lifecycleAnomaly = 0;

        foreach ($lines as $ln) {
            $domain = (string) ($ln['line_domain_class'] ?? '');
            if ($domain === self::DOMAIN_CLEAR_SERVICE) {
                $clearService++;
            } elseif ($domain === self::DOMAIN_CLEAR_RETAIL) {
                $clearRetail++;
            } else {
                $mixedOrAmbiguous++;
            }

            $impact = (string) ($ln['inventory_impact_class'] ?? '');
            if ($impact !== 'service_like_line_with_no_inventory_impact') {
                $inventoryAffecting++;
            }

            $life = (string) ($ln['lifecycle_consistency_class'] ?? '');
            if (!in_array($life, self::LIFECYCLE_CLEAN, true)) {
                $lifecycleAnomaly++;
            }
        }

        $shape = $this->resolveInvoiceDomainShape($lineCount, $lines);
        [$compositionClass, $reasonCodes] = $this->resolveInvoiceDomainCompositionClass($shape, $lines);

        return [
            'invoice_id' => $invoiceId,
            'invoice_status' => $status,
            'invoice_branch_id' => $branchId,
            'line_count' => $lineCount,
            'clear_service_line_count' => $clearService,
            'clear_retail_product_line_count' => $clearRetail,
            'mixed_or_ambiguous_line_count' => $mixedOrAmbiguous,
            'inventory_affecting_line_count' => $inventoryAffecting,
            'lifecycle_anomaly_line_count' => $lifecycleAnomaly,
            'invoice_domain_shape' => $shape,
            'invoice_domain_composition_class' => $compositionClass,
            'reason_codes' => $reasonCodes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function resolveInvoiceDomainShape(int $lineCount, array $lines): string
    {
        if ($lineCount === 0) {
            return 'unsupported_invoice_shape';
        }

        $allService = true;
        $allRetail = true;
        $hasService = false;
        $hasRetail = false;
        $hasNonClear = false;

        foreach ($lines as $ln) {
            $d = (string) ($ln['line_domain_class'] ?? '');
            if ($d === self::DOMAIN_CLEAR_SERVICE) {
                $hasService = true;
                $allRetail = false;
            } elseif ($d === self::DOMAIN_CLEAR_RETAIL) {
                $hasRetail = true;
                $allService = false;
            } else {
                $hasNonClear = true;
                $allService = false;
                $allRetail = false;
            }
        }

        if ($allService && $hasService) {
            return 'service_only_lines';
        }
        if ($allRetail && $hasRetail) {
            return 'retail_only_lines';
        }
        if ($hasService && $hasRetail && !$hasNonClear) {
            return 'mixed_service_and_retail_lines';
        }
        if (!$hasService && !$hasRetail) {
            return 'no_clear_domain_lines';
        }

        return 'unsupported_invoice_shape';
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{0: string, 1: list<string>}
     */
    private function resolveInvoiceDomainCompositionClass(string $shape, array $lines): array
    {
        $reasons = [];

        if ($this->invoiceHasOrphanedOrUnsupportedLineTruth($lines)) {
            $reasons[] = 'invoice_has_orphaned_or_unsupported_line_domain_inventory_or_lifecycle_truth';

            return ['orphaned_or_unsupported_invoice_story', $reasons];
        }

        if ($this->invoiceHasInventoryContradictionLine($lines)) {
            $reasons[] = 'invoice_has_line_failing_inventory_impact_clean_pair';

            return ['mixed_invoice_with_inventory_contradictions', $reasons];
        }

        if ($this->invoiceHasLifecycleAnomalyLine($lines)) {
            $reasons[] = 'invoice_has_line_failing_lifecycle_consistency_clean_pair';

            return ['mixed_invoice_with_lifecycle_anomalies', $reasons];
        }

        $invClean = !$this->invoiceHasInventoryContradictionLine($lines);
        $lifeClean = !$this->invoiceHasLifecycleAnomalyLine($lines);
        if ($shape === 'mixed_service_and_retail_lines') {
            $reasons[] = 'all_lines_clean_inventory_and_lifecycle_with_mixed_clear_domain';

            return ['clean_mixed_domain_invoice', $reasons];
        }
        if ($shape === 'service_only_lines') {
            $reasons[] = 'all_lines_clean_inventory_and_lifecycle_with_service_only_domain';

            return ['clean_service_only_invoice', $reasons];
        }
        if ($shape === 'retail_only_lines') {
            $reasons[] = 'all_lines_clean_inventory_and_lifecycle_with_retail_only_domain';

            return ['clean_retail_only_invoice', $reasons];
        }

        $reasons[] = 'invoice_shape_or_line_truth_not_matching_clean_composition_buckets';
        if ($shape === 'unsupported_invoice_shape') {
            $reasons[] = 'invoice_domain_shape_unsupported_invoice_shape';
        }
        if ($shape === 'no_clear_domain_lines') {
            $reasons[] = 'invoice_domain_shape_no_clear_domain_lines';
        }

        return ['ambiguous_invoice_domain_story', $reasons];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function invoiceHasOrphanedOrUnsupportedLineTruth(array $lines): bool
    {
        foreach ($lines as $ln) {
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
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function invoiceHasInventoryContradictionLine(array $lines): bool
    {
        foreach ($lines as $ln) {
            $i = (string) ($ln['inventory_impact_class'] ?? '');
            if (!in_array($i, self::INVENTORY_IMPACT_CLEAN, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function invoiceHasLifecycleAnomalyLine(array $lines): bool
    {
        foreach ($lines as $ln) {
            $l = (string) ($ln['lifecycle_consistency_class'] ?? '');
            if (!in_array($l, self::LIFECYCLE_CLEAN, true)) {
                return true;
            }
        }

        return false;
    }
}

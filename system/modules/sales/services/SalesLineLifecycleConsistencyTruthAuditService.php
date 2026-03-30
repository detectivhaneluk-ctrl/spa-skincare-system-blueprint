<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

/**
 * Read-only audit: compose {@see SalesLineInventoryImpactTruthAuditService} (WAVE-02) and classify
 * **invoice header status** vs **accepted inventory_impact_class** + **line_domain_class** into a single
 * lifecycle consistency story per line.
 *
 * Does **not** implement mixed-sales behavior; no writes.
 *
 * Wave: {@code MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-03}.
 */
final class SalesLineLifecycleConsistencyTruthAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AUDIT_SCHEMA_VERSION = 1;

    public const LIFECYCLE_CONSISTENCY_CLASSES = [
        'lifecycle_consistent_retail_line',
        'lifecycle_consistent_service_like_line',
        'paid_retail_line_missing_expected_inventory_effect',
        'unpaid_line_with_unexpected_inventory_effect',
        'reversal_heavy_lifecycle_story',
        'domain_inventory_lifecycle_contradiction',
        'orphaned_lifecycle_story',
        'unsupported_lifecycle_contract',
        'ambiguous_lifecycle_story',
    ];

    public function __construct(
        private SalesLineInventoryImpactTruthAuditService $inventoryImpactAudit
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('c');
        $impactReport = $this->inventoryImpactAudit->run($invoiceIdFilter);
        $impactLines = $impactReport['lines'] ?? [];
        if (!is_array($impactLines)) {
            $impactLines = [];
        }

        $classCounts = array_fill_keys(self::LIFECYCLE_CONSISTENCY_CLASSES, 0);
        $examples = [];
        foreach (self::LIFECYCLE_CONSISTENCY_CLASSES as $c) {
            $examples[$c] = [];
        }

        $lines = [];
        $invoiceIds = [];
        $affectedInvoiceIds = [];

        foreach ($impactLines as $row) {
            if (!is_array($row)) {
                continue;
            }
            [$lifecycleClass, $lifecycleReasons] = $this->resolveLifecycleConsistencyClass($row);

            $invReasons = $row['reason_codes'] ?? [];
            if (!is_array($invReasons)) {
                $invReasons = [];
            }
            $reasonCodes = $this->mergeReasonCodes($invReasons, $lifecycleReasons);

            $line = [
                'invoice_id' => (int) ($row['invoice_id'] ?? 0),
                'invoice_item_id' => (int) ($row['invoice_item_id'] ?? 0),
                'invoice_status' => (string) ($row['invoice_status'] ?? ''),
                'invoice_branch_id' => isset($row['invoice_branch_id']) && $row['invoice_branch_id'] !== null && $row['invoice_branch_id'] !== ''
                    ? (int) $row['invoice_branch_id']
                    : null,
                'item_type' => (string) ($row['item_type'] ?? ''),
                'product_id' => array_key_exists('product_id', $row) ? $row['product_id'] : null,
                'service_id' => array_key_exists('service_id', $row) ? $row['service_id'] : null,
                'quantity' => (string) ($row['quantity'] ?? '0'),
                'line_domain_class' => (string) ($row['line_domain_class'] ?? ''),
                'inventory_impact_class' => (string) ($row['inventory_impact_class'] ?? ''),
                'linked_stock_movement_count' => (int) ($row['linked_stock_movement_count'] ?? 0),
                'linked_stock_movement_net_quantity' => (string) ($row['linked_stock_movement_net_quantity'] ?? '0'),
                'lifecycle_consistency_class' => $lifecycleClass,
                'reason_codes' => $reasonCodes,
            ];

            $lines[] = $line;
            $classCounts[$lifecycleClass]++;

            $invId = (int) $line['invoice_id'];
            $invoiceIds[$invId] = true;
            if (!in_array($lifecycleClass, ['lifecycle_consistent_retail_line', 'lifecycle_consistent_service_like_line'], true)) {
                $affectedInvoiceIds[$invId] = true;
            }

            if (count($examples[$lifecycleClass]) < self::EXAMPLE_CAP) {
                $examples[$lifecycleClass][] = $line;
            }
        }

        $affectedLines = 0;
        foreach ($lines as $ln) {
            if (!in_array($ln['lifecycle_consistency_class'], ['lifecycle_consistent_retail_line', 'lifecycle_consistent_service_like_line'], true)) {
                $affectedLines++;
            }
        }

        $sampleIds = array_keys($affectedInvoiceIds);
        sort($sampleIds, SORT_NUMERIC);
        $sampleIds = array_slice($sampleIds, 0, 20);

        $notes = [
            'Composes WAVE-02 per-line inventory_impact_class + inventory_impact_shape with invoices.status (header) and line_domain_class; no new SQL beyond SalesLineInventoryImpactTruthAuditService.',
            'Settlement expectation model remains: only status paid implies target net −line quantity on clear retail via sale/sale_reversal rows linked as reference_type invoice_item; non-paid headers target net 0 (same as WAVE-02).',
            'reversal_heavy_lifecycle_story is emitted when linked movements are sale_reversal-only (shape) and no stronger lifecycle problem already applied (unsupported/orphaned/paid-missing/mixed/service-unexpected).',
            'unpaid_line_with_unexpected_inventory_effect is used when WAVE-02 reports mixed_line_with_inventory_contradiction on a clear_retail_product_line under a non-paid header, or service_like_line_with_unexpected_inventory_impact under a non-paid header.',
            'This audit does not prove payment timing, partial settlement policy beyond the paid vs not-paid header split, or non-invoice_item stock references.',
            'Read-only: does not repair data and does not implement mixed-sales / service-consumption behavior.',
        ];

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'invoice_id_filter' => $invoiceIdFilter,
            'lines_scanned' => count($lines),
            'invoices_scanned' => count($invoiceIds),
            'lifecycle_consistency_class_counts' => $classCounts,
            'affected_lines_count' => $affectedLines,
            'affected_invoice_ids_sample' => $sampleIds,
            'examples_by_lifecycle_consistency_class' => $examples,
            'notes' => $notes,
            'lines' => $lines,
            'composed_inventory_audit_schema_version' => $impactReport['audit_schema_version'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row WAVE-02 line row
     * @return array{0: string, 1: list<string>}
     */
    private function resolveLifecycleConsistencyClass(array $row): array
    {
        $lifecycleReasons = [];
        $invClass = (string) ($row['inventory_impact_class'] ?? '');
        $invShape = (string) ($row['inventory_impact_shape'] ?? '');
        $domain = (string) ($row['line_domain_class'] ?? '');
        $statusNorm = strtolower(trim((string) ($row['invoice_status'] ?? '')));
        $isPaid = $statusNorm === 'paid';

        if ($invClass === 'unsupported_inventory_contract') {
            $lifecycleReasons[] = 'lifecycle_derived_from_inventory_impact_class_unsupported_inventory_contract';

            return ['unsupported_lifecycle_contract', $lifecycleReasons];
        }
        if ($invClass === 'orphaned_inventory_impact_story') {
            $lifecycleReasons[] = 'lifecycle_derived_from_inventory_impact_class_orphaned_inventory_impact_story';

            return ['orphaned_lifecycle_story', $lifecycleReasons];
        }
        if ($invClass === 'retail_line_missing_expected_inventory_impact') {
            $lifecycleReasons[] = 'lifecycle_derived_from_inventory_impact_class_retail_line_missing_expected_inventory_impact';

            return ['paid_retail_line_missing_expected_inventory_effect', $lifecycleReasons];
        }
        if ($invClass === 'mixed_line_with_inventory_contradiction') {
            if ($domain === 'clear_retail_product_line' && !$isPaid) {
                $lifecycleReasons[] = 'clear_retail_non_paid_header_with_ledger_mismatch_per_inventory_audit';

                return ['unpaid_line_with_unexpected_inventory_effect', $lifecycleReasons];
            }
            $lifecycleReasons[] = 'inventory_impact_mixed_contradiction_not_mapped_to_unpaid_retail_bucket';

            return ['domain_inventory_lifecycle_contradiction', $lifecycleReasons];
        }
        if ($invClass === 'service_like_line_with_unexpected_inventory_impact') {
            if (!$isPaid) {
                $lifecycleReasons[] = 'clear_service_line_with_invoice_item_ledger_rows_under_non_paid_header';

                return ['unpaid_line_with_unexpected_inventory_effect', $lifecycleReasons];
            }
            $lifecycleReasons[] = 'clear_service_line_with_invoice_item_ledger_rows_under_paid_header';

            return ['domain_inventory_lifecycle_contradiction', $lifecycleReasons];
        }
        if ($invShape === 'sale_reversal_only_movements') {
            $lifecycleReasons[] = 'linked_movement_shape_is_sale_reversal_only';

            return ['reversal_heavy_lifecycle_story', $lifecycleReasons];
        }
        if ($invClass === 'retail_line_with_expected_inventory_impact' && $domain === 'clear_retail_product_line') {
            $lifecycleReasons[] = 'clear_retail_inventory_impact_matches_current_header_expectation_model';

            return ['lifecycle_consistent_retail_line', $lifecycleReasons];
        }
        if ($invClass === 'service_like_line_with_no_inventory_impact' && $domain === 'clear_service_line') {
            $lifecycleReasons[] = 'clear_service_line_has_no_invoice_item_linked_ledger_rows';

            return ['lifecycle_consistent_service_like_line', $lifecycleReasons];
        }
        if ($invClass === 'ambiguous_inventory_story') {
            $lifecycleReasons[] = 'lifecycle_derived_from_inventory_impact_class_ambiguous_inventory_story';

            return ['ambiguous_lifecycle_story', $lifecycleReasons];
        }

        $lifecycleReasons[] = 'lifecycle_fallback_insufficient_pairing_for_consistent_or_reversal_heavy_bucket';

        return ['ambiguous_lifecycle_story', $lifecycleReasons];
    }

    /**
     * @param list<string> $inventoryReasons
     * @param list<string> $lifecycleReasons
     * @return list<string>
     */
    private function mergeReasonCodes(array $inventoryReasons, array $lifecycleReasons): array
    {
        $out = [];
        foreach ($inventoryReasons as $r) {
            if (!is_string($r) || $r === '') {
                continue;
            }
            $out[] = $r;
        }
        foreach ($lifecycleReasons as $r) {
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

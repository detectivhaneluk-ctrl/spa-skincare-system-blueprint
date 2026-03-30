<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Database;

/**
 * Read-only audit: compose {@see SalesLineDomainBoundaryTruthAuditService} with
 * {@code stock_movements} rows where {@code reference_type = invoice_item} and {@code reference_id} = line id.
 *
 * Does **not** implement mixed-sales or settlement changes; no writes.
 *
 * Wave: {@code MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-02}.
 */
final class SalesLineInventoryImpactTruthAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AUDIT_SCHEMA_VERSION = 1;

    private const NET_EPS = 1.0e-6;

    private const SETTLEMENT_MOVEMENT_TYPES = ['sale', 'sale_reversal'];

    public const INVENTORY_IMPACT_SHAPES = [
        'no_linked_stock_movements',
        'sale_only_movements',
        'sale_reversal_only_movements',
        'sale_and_sale_reversal_movements',
        'non_sales_movement_types_linked',
        'unsupported_movement_shape',
    ];

    public const INVENTORY_IMPACT_CLASSES = [
        'retail_line_with_expected_inventory_impact',
        'service_like_line_with_no_inventory_impact',
        'retail_line_missing_expected_inventory_impact',
        'service_like_line_with_unexpected_inventory_impact',
        'mixed_line_with_inventory_contradiction',
        'orphaned_inventory_impact_story',
        'unsupported_inventory_contract',
        'ambiguous_inventory_story',
    ];

    public function __construct(
        private SalesLineDomainBoundaryTruthAuditService $domainAudit,
        private Database $db
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('c');
        $domainReport = $this->domainAudit->run($invoiceIdFilter);
        $domainLines = $domainReport['lines'] ?? [];
        if (!is_array($domainLines)) {
            $domainLines = [];
        }

        $itemIds = [];
        foreach ($domainLines as $dl) {
            if (is_array($dl) && isset($dl['invoice_item_id'])) {
                $itemIds[] = (int) $dl['invoice_item_id'];
            }
        }
        $movementByItem = $this->loadInvoiceItemLinkedMovementFacts($itemIds);

        $shapeCounts = array_fill_keys(self::INVENTORY_IMPACT_SHAPES, 0);
        $classCounts = array_fill_keys(self::INVENTORY_IMPACT_CLASSES, 0);
        $examples = [];
        foreach (self::INVENTORY_IMPACT_CLASSES as $c) {
            $examples[$c] = [];
        }

        $lines = [];
        $invoiceIds = [];
        $affectedInvoiceIds = [];

        foreach ($domainLines as $dl) {
            if (!is_array($dl)) {
                continue;
            }
            $itemId = (int) ($dl['invoice_item_id'] ?? 0);
            $facts = $movementByItem[$itemId] ?? $this->emptyMovementFacts();
            $shape = $this->resolveInventoryImpactShape($facts);
            $shapeCounts[$shape]++;

            $lineQty = (float) ($dl['quantity'] ?? 0);
            if (!is_finite($lineQty)) {
                $lineQty = 0.0;
            }
            $domainClass = (string) ($dl['line_domain_class'] ?? '');
            $invoiceStatus = strtolower(trim((string) ($dl['invoice_status'] ?? '')));
            $lineProductId = isset($dl['product_id']) && $dl['product_id'] !== null ? (int) $dl['product_id'] : null;

            $productMismatch = $this->movementProductMismatch($facts, $lineProductId);

            $reasonCodes = [];
            $impactClass = $this->resolveInventoryImpactClass(
                $domainClass,
                $invoiceStatus,
                $lineQty,
                $lineProductId,
                $shape,
                $facts['net_quantity'],
                $facts['row_count'],
                $productMismatch,
                $facts['has_blank_movement_type'],
                $facts['distinct_product_count'],
                $reasonCodes
            );
            $classCounts[$impactClass]++;

            $typesList = array_values(array_filter(
                $facts['movement_types_sorted'],
                static fn (string $t): bool => $t !== ''
            ));

            $line = [
                'invoice_id' => (int) ($dl['invoice_id'] ?? 0),
                'invoice_item_id' => $itemId,
                'invoice_status' => (string) ($dl['invoice_status'] ?? ''),
                'invoice_branch_id' => isset($dl['invoice_branch_id']) && $dl['invoice_branch_id'] !== null && $dl['invoice_branch_id'] !== ''
                    ? (int) $dl['invoice_branch_id']
                    : null,
                'item_type' => (string) ($dl['item_type'] ?? ''),
                'product_id' => isset($dl['product_id']) ? $dl['product_id'] : null,
                'service_id' => isset($dl['service_id']) ? $dl['service_id'] : null,
                'quantity' => (string) ($dl['quantity'] ?? '0'),
                'line_domain_class' => $domainClass,
                'linked_stock_movement_count' => $facts['row_count'],
                'linked_stock_movement_net_quantity' => $this->floatToString($facts['net_quantity']),
                'linked_stock_movement_types' => $typesList,
                'inventory_impact_shape' => $shape,
                'inventory_impact_class' => $impactClass,
                'reason_codes' => $reasonCodes,
            ];

            $lines[] = $line;

            $invId = (int) $line['invoice_id'];
            $invoiceIds[$invId] = true;
            if (!in_array($impactClass, ['retail_line_with_expected_inventory_impact', 'service_like_line_with_no_inventory_impact'], true)) {
                $affectedInvoiceIds[$invId] = true;
            }

            if (count($examples[$impactClass]) < self::EXAMPLE_CAP) {
                $examples[$impactClass][] = $line;
            }
        }

        $affectedLines = 0;
        foreach ($lines as $ln) {
            if (!in_array($ln['inventory_impact_class'], ['retail_line_with_expected_inventory_impact', 'service_like_line_with_no_inventory_impact'], true)) {
                $affectedLines++;
            }
        }

        $sampleIds = array_keys($affectedInvoiceIds);
        sort($sampleIds, SORT_NUMERIC);
        $sampleIds = array_slice($sampleIds, 0, 20);

        $notes = [
            'Composes WAVE-01 line_domain_class with stock_movements where reference_type = invoice_item and reference_id = invoice_item.id only.',
            'InvoiceStockSettlementService targets net quantity -line quantity when invoices.status = paid, else 0, using movement_type sale (negative signed qty) and sale_reversal (positive signed qty) per StockMovementService sign rules.',
            'Only status paid is treated as “settlement deduction target active”; draft/open/partial/cancelled/refunded use expected net 0 for this audit’s expectation model.',
            'Non-sale movement types on invoice_item references (e.g. internal_usage) are contract drift vs settlement writers; see ProductInternalUsageServiceConsumptionBoundaryAuditService.',
            'Multiple distinct product_id values on the same invoice_item reference are unsupported_movement_shape.',
            'This audit does not prove physical returns, partial refunds, or branch attribution correctness beyond stored movement rows.',
        ];

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'invoice_id_filter' => $invoiceIdFilter,
            'lines_scanned' => count($lines),
            'invoices_scanned' => count($invoiceIds),
            'inventory_impact_shape_counts' => $shapeCounts,
            'inventory_impact_class_counts' => $classCounts,
            'affected_lines_count' => $affectedLines,
            'affected_invoice_ids_sample' => $sampleIds,
            'examples_by_inventory_impact_class' => $examples,
            'notes' => $notes,
            'lines' => $lines,
            'composed_domain_audit_schema_version' => $domainReport['audit_schema_version'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadInvoiceItemLinkedMovementFacts(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn (int $id) => $id > 0)));
        $out = [];
        foreach ($itemIds as $id) {
            $out[$id] = $this->emptyMovementFacts();
        }
        if ($itemIds === []) {
            return $out;
        }

        $chunkSize = 500;
        for ($i = 0, $n = count($itemIds); $i < $n; $i += $chunkSize) {
            $chunk = array_slice($itemIds, $i, $chunkSize);
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $rows = $this->db->fetchAll(
                "SELECT reference_id, movement_type, product_id, COUNT(*) AS row_count, COALESCE(SUM(quantity), 0) AS sum_qty
                 FROM stock_movements
                 WHERE reference_type = 'invoice_item'
                   AND reference_id IN ({$placeholders})
                 GROUP BY reference_id, movement_type, product_id",
                $chunk
            );

            foreach ($rows as $r) {
                $rid = (int) ($r['reference_id'] ?? 0);
                if ($rid <= 0 || !isset($out[$rid])) {
                    continue;
                }
                $mtRaw = $r['movement_type'] ?? null;
                $mt = $mtRaw === null ? '' : strtolower(trim((string) $mtRaw));
                if ($mt === '') {
                    $out[$rid]['has_blank_movement_type'] = true;
                }
                $cnt = (int) ($r['row_count'] ?? 0);
                $sum = (float) ($r['sum_qty'] ?? 0);
                $pid = isset($r['product_id']) && $r['product_id'] !== '' && $r['product_id'] !== null
                    ? (int) $r['product_id']
                    : null;

                $out[$rid]['row_count'] += $cnt;
                $out[$rid]['net_quantity'] += $sum;
                $out[$rid]['by_type'][$mt] = ($out[$rid]['by_type'][$mt] ?? 0) + $cnt;
                if ($pid !== null && $pid > 0) {
                    $out[$rid]['product_ids'][$pid] = true;
                }
            }
        }

        foreach ($out as $rid => $agg) {
            $types = array_keys($agg['by_type']);
            sort($types, SORT_STRING);
            $out[$rid]['movement_types_sorted'] = $types;
            if ($agg['row_count'] > 0) {
                $out[$rid]['distinct_product_count'] = count($agg['product_ids']);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMovementFacts(): array
    {
        return [
            'row_count' => 0,
            'net_quantity' => 0.0,
            'by_type' => [],
            'product_ids' => [],
            'distinct_product_count' => 0,
            'movement_types_sorted' => [],
            'has_blank_movement_type' => false,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function resolveInventoryImpactShape(array $facts): string
    {
        if ((int) $facts['row_count'] === 0) {
            return 'no_linked_stock_movements';
        }
        if ($facts['has_blank_movement_type']) {
            return 'unsupported_movement_shape';
        }
        if ((int) $facts['distinct_product_count'] > 1) {
            return 'unsupported_movement_shape';
        }

        $typesNonEmpty = [];
        foreach ($facts['by_type'] as $t => $_) {
            if ($t !== '') {
                $typesNonEmpty[$t] = true;
            }
        }
        $u = array_keys($typesNonEmpty);
        sort($u, SORT_STRING);

        foreach ($u as $t) {
            if (!in_array($t, self::SETTLEMENT_MOVEMENT_TYPES, true)) {
                return 'non_sales_movement_types_linked';
            }
        }

        $hasSale = in_array('sale', $u, true);
        $hasRev = in_array('sale_reversal', $u, true);
        if ($hasSale && $hasRev) {
            return 'sale_and_sale_reversal_movements';
        }
        if ($hasSale) {
            return 'sale_only_movements';
        }
        if ($hasRev) {
            return 'sale_reversal_only_movements';
        }

        return 'unsupported_movement_shape';
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function movementProductMismatch(array $facts, ?int $lineProductId): bool
    {
        if ($lineProductId === null || $lineProductId <= 0) {
            return false;
        }
        if ($facts['product_ids'] === []) {
            return false;
        }
        foreach (array_keys($facts['product_ids']) as $pid) {
            if ((int) $pid !== $lineProductId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $reasonCodes
     */
    private function resolveInventoryImpactClass(
        string $domainClass,
        string $invoiceStatusNorm,
        float $lineQty,
        ?int $lineProductId,
        string $shape,
        float $netQty,
        int $linkedCount,
        bool $productMismatch,
        bool $hasBlankType,
        int $distinctProductCount,
        array &$reasonCodes
    ): string {
        $expectsPaidTarget = ($invoiceStatusNorm === 'paid');
        $expectedNet = $expectsPaidTarget ? -$lineQty : 0.0;

        if ($shape === 'unsupported_movement_shape') {
            if ($distinctProductCount > 1) {
                $reasonCodes[] = 'multiple_distinct_product_id_on_same_invoice_item_reference';
            }
            if ($hasBlankType) {
                $reasonCodes[] = 'blank_or_null_movement_type_on_linked_row';
            }
            if ($linkedCount > 0 && !$hasBlankType && $distinctProductCount <= 1) {
                $reasonCodes[] = 'movement_type_set_empty_after_normalization';
            }

            return $this->classifyLedgerContractDrift($domainClass, $linkedCount, $reasonCodes, 'unsupported_inventory_contract');
        }

        if ($shape === 'non_sales_movement_types_linked') {
            $reasonCodes[] = 'linked_movement_types_outside_sale_sale_reversal_set';

            return $this->classifyLedgerContractDrift($domainClass, $linkedCount, $reasonCodes, 'unsupported_inventory_contract');
        }

        if ($domainClass === 'clear_service_line') {
            if ($shape === 'no_linked_stock_movements') {
                $reasonCodes[] = 'no_stock_movements_linked_via_invoice_item_reference';

                return 'service_like_line_with_no_inventory_impact';
            }
            $reasonCodes[] = 'service_line_has_invoice_item_linked_stock_movements';

            return 'service_like_line_with_unexpected_inventory_impact';
        }

        if ($domainClass === 'clear_retail_product_line') {
            return $this->classifyClearRetail(
                $expectsPaidTarget,
                $expectedNet,
                $netQty,
                $shape,
                $linkedCount,
                $productMismatch,
                $lineProductId,
                $reasonCodes
            );
        }

        if (in_array($domainClass, ['orphaned_domain_reference', 'unsupported_line_contract'], true)) {
            if ($linkedCount === 0) {
                $reasonCodes[] = 'domain_line_not_usable_no_linked_movements';

                return 'ambiguous_inventory_story';
            }
            $reasonCodes[] = 'linked_movements_present_but_line_domain_unusable';

            return 'orphaned_inventory_impact_story';
        }

        if ($domainClass === 'mixed_domain_line' || $domainClass === 'ambiguous_domain_story') {
            if ($linkedCount === 0) {
                $reasonCodes[] = 'ambiguous_or_mixed_domain_without_ledger_rows';

                return 'ambiguous_inventory_story';
            }
            $reasonCodes[] = 'ambiguous_or_mixed_domain_with_invoice_item_linked_movements';

            return 'mixed_line_with_inventory_contradiction';
        }

        $reasonCodes[] = 'unclassified_domain_line_for_inventory_impact';

        return 'ambiguous_inventory_story';
    }

    /**
     * Unsupported / non-settlement movement shapes: prefer unsupported_inventory_contract except domain+ledger pairings below.
     *
     * @param list<string> $reasonCodes
     */
    private function classifyLedgerContractDrift(
        string $domainClass,
        int $linkedCount,
        array &$reasonCodes,
        string $defaultClass
    ): string {
        if (in_array($domainClass, ['orphaned_domain_reference', 'unsupported_line_contract'], true) && $linkedCount > 0) {
            $reasonCodes[] = 'ledger_contract_problem_on_unusable_domain_line';

            return 'orphaned_inventory_impact_story';
        }
        if (in_array($domainClass, ['mixed_domain_line', 'ambiguous_domain_story'], true) && $linkedCount > 0) {
            $reasonCodes[] = 'ledger_contract_problem_on_ambiguous_domain_line';

            return 'mixed_line_with_inventory_contradiction';
        }
        if ($domainClass === 'clear_service_line' && $linkedCount > 0) {
            return 'service_like_line_with_unexpected_inventory_impact';
        }

        return $defaultClass;
    }

    /**
     * @param list<string> $reasonCodes
     */
    private function classifyClearRetail(
        bool $expectsPaidTarget,
        float $expectedNet,
        float $netQty,
        string $shape,
        int $linkedCount,
        bool $productMismatch,
        ?int $lineProductId,
        array &$reasonCodes
    ): string {
        if ($productMismatch) {
            $reasonCodes[] = 'stock_movement_product_id_mismatch_vs_line_product_id';
        }

        $netOk = abs($netQty - $expectedNet) <= self::NET_EPS;

        if ($expectsPaidTarget) {
            if ($shape === 'no_linked_stock_movements') {
                $reasonCodes[] = 'paid_invoice_clear_retail_line_expects_settlement_rows';

                return 'retail_line_missing_expected_inventory_impact';
            }
            if (!$netOk || $productMismatch) {
                $reasonCodes[] = 'paid_invoice_net_quantity_mismatch_vs_minus_line_quantity_expectation';
                $reasonCodes[] = 'expected_net_' . $this->floatToString($expectedNet) . '_actual_net_' . $this->floatToString($netQty);

                return 'mixed_line_with_inventory_contradiction';
            }
            $reasonCodes[] = 'paid_invoice_net_matches_settlement_expectation';

            return 'retail_line_with_expected_inventory_impact';
        }

        // Not fully paid per invoice header: settlement target net 0
        if ($shape === 'no_linked_stock_movements') {
            $reasonCodes[] = 'non_paid_invoice_no_settlement_deduction_expected';

            return 'retail_line_with_expected_inventory_impact';
        }
        if ($netOk && !$productMismatch) {
            $reasonCodes[] = 'non_paid_invoice_net_zero_with_linked_rows';

            return 'retail_line_with_expected_inventory_impact';
        }
        $reasonCodes[] = 'non_paid_invoice_unexpected_non_zero_net_or_product_mismatch';

        return 'mixed_line_with_inventory_contradiction';
    }

    private function floatToString(float $v): string
    {
        if (!is_finite($v)) {
            return '0';
        }
        $s = (string) $v;

        return $s;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only audit: every {@code stock_movements} row with {@code movement_type = internal_usage}, classified by
 * reference shape vs accepted inventory writer contracts ({@see StockMovementService::createManual},
 * {@see InvoiceStockSettlementService}, {@see InventoryCountService}, {@see ProductService} opening stock).
 *
 * Does **not** infer service-level product consumption; no writes.
 *
 * Task: {@code INVENTORY-OPERATIONAL-DEPTH-WAVE-03}.
 */
final class ProductInternalUsageServiceConsumptionBoundaryAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AUDIT_SCHEMA_VERSION = 1;

    /**
     * Deterministic {@code boundary_class} registry (rollups / examples iterate in this order).
     * Classification still applies rules in {@see classifyRow} priority (malformed and missing targets before “unexpected” links).
     */
    public const BOUNDARY_CLASSES = [
        'manual_operator_internal_usage',
        'product_self_reference_internal_usage',
        'product_reference_mismatch',
        'invoice_item_linked_internal_usage_unexpected',
        'inventory_count_linked_internal_usage_unexpected',
        'unknown_reference_type_internal_usage',
        'malformed_reference_pair',
        'missing_reference_target',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     generated_at_utc: string,
     *     audit_schema_version: int,
     *     product_id_filter: int|null,
     *     total_internal_usage_movements: int,
     *     counts_by_boundary_class: array<string, int>,
     *     unlinked_manual_internal_usage_count: int,
     *     anomalous_internal_usage_count: int,
     *     distinct_reference_types_seen: list<string>,
     *     examples_by_boundary_class: array<string, list<array<string, mixed>>>,
     *     notes: list<string>,
     *     movements: list<array<string, mixed>>
     * }
     */
    public function run(?int $productId = null): array
    {
        $generatedAt = gmdate('c');
        $params = [];
        $whereProduct = '';
        if ($productId !== null) {
            $whereProduct = ' AND sm.product_id = ?';
            $params[] = $productId;
        }

        $sql = <<<SQL
SELECT sm.id,
       sm.product_id,
       sm.branch_id,
       sm.quantity,
       sm.reference_type,
       sm.reference_id,
       sm.created_at,
       p.id AS movement_product_row_id,
       p.deleted_at AS movement_product_deleted_at,
       ii.id AS ref_invoice_item_row_id,
       ic.id AS ref_inventory_count_row_id,
       pr.id AS ref_product_row_id
FROM stock_movements sm
LEFT JOIN products p ON p.id = sm.product_id
LEFT JOIN invoice_items ii
       ON sm.reference_type = 'invoice_item'
      AND sm.reference_id IS NOT NULL
      AND ii.id = sm.reference_id
LEFT JOIN inventory_counts ic
       ON sm.reference_type = 'inventory_count'
      AND sm.reference_id IS NOT NULL
      AND ic.id = sm.reference_id
LEFT JOIN products pr
       ON sm.reference_type = 'product'
      AND sm.reference_id IS NOT NULL
      AND pr.id = sm.reference_id
WHERE sm.movement_type = 'internal_usage'{$whereProduct}
ORDER BY sm.id ASC
SQL;

        $rows = $this->db->fetchAll($sql, $params);

        $counts = array_fill_keys(self::BOUNDARY_CLASSES, 0);
        $examples = [];
        foreach (self::BOUNDARY_CLASSES as $c) {
            $examples[$c] = [];
        }

        $distinctTypes = [];
        $movements = [];

        foreach ($rows as $r) {
            $classified = $this->classifyRow($r);
            $boundaryClass = $classified['boundary_class'];
            $counts[$boundaryClass]++;

            $refTypeRaw = $r['reference_type'] ?? null;
            $refTypeLabel = $refTypeRaw === null || trim((string) $refTypeRaw) === ''
                ? ''
                : trim((string) $refTypeRaw);
            $distinctTypes[$refTypeLabel] = true;

            $payload = $classified['payload'];
            $movements[] = $payload;

            if (count($examples[$boundaryClass]) < self::EXAMPLE_CAP) {
                $examples[$boundaryClass][] = $payload;
            }
        }

        $total = count($rows);
        $manualCount = (int) ($counts['manual_operator_internal_usage'] ?? 0);
        $anomalous = $total - $manualCount;

        $distinctList = array_keys($distinctTypes);
        sort($distinctList, SORT_STRING);

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'product_id_filter' => $productId,
            'total_internal_usage_movements' => $total,
            'counts_by_boundary_class' => $counts,
            'unlinked_manual_internal_usage_count' => $manualCount,
            'anomalous_internal_usage_count' => $anomalous,
            'distinct_reference_types_seen' => $distinctList,
            'examples_by_boundary_class' => $examples,
            'notes' => [
                'Only movement_type internal_usage rows are scanned.',
                'Manual inventory UI entry uses StockMovementService::createManual, which forces reference_type and reference_id to null; null pairs are classified as manual_operator_internal_usage.',
                'Invoice settlement uses reference_type invoice_item with movement_type sale or sale_reversal only (ProductStockMovementOriginClassificationReportService). internal_usage rows linked to invoice_item are flagged as unexpected, not as service consumption.',
                'Inventory count adjustments use reference_type inventory_count with movement_type count_adjustment. internal_usage rows linked to inventory_count are unexpected.',
                'Product opening stock on create uses manual_adjustment with reference_type product and reference_id = product_id (ProductService). internal_usage with the same product self-reference is a distinct stored shape, not proof of service consumption.',
                'No appointment, service, or package_usage linkage is read or inferred for internal_usage in this audit.',
            ],
            'movements' => $movements,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{boundary_class: string, payload: array<string, mixed>}
     */
    private function classifyRow(array $r): array
    {
        $movementId = (int) $r['id'];
        $productId = (int) $r['product_id'];
        $branchId = isset($r['branch_id']) && $r['branch_id'] !== null && $r['branch_id'] !== ''
            ? (int) $r['branch_id']
            : null;
        $quantity = (float) ($r['quantity'] ?? 0);
        $createdAt = (string) ($r['created_at'] ?? '');

        $refTypeRaw = $r['reference_type'] ?? null;
        $refIdRaw = $r['reference_id'] ?? null;

        $refTypeTrimmed = $refTypeRaw === null ? '' : trim((string) $refTypeRaw);
        $refTypeNorm = $refTypeTrimmed === '' ? null : $refTypeTrimmed;
        $refId = $refIdRaw === null || $refIdRaw === '' ? null : (int) $refIdRaw;

        $productRowId = isset($r['movement_product_row_id']) && $r['movement_product_row_id'] !== null && $r['movement_product_row_id'] !== ''
            ? (int) $r['movement_product_row_id']
            : null;
        $productExists = $productRowId !== null;
        $productDeleted = $productExists
            && isset($r['movement_product_deleted_at'])
            && $r['movement_product_deleted_at'] !== null
            && (string) $r['movement_product_deleted_at'] !== '';

        $iiRow = isset($r['ref_invoice_item_row_id']) && $r['ref_invoice_item_row_id'] !== null && $r['ref_invoice_item_row_id'] !== ''
            ? (int) $r['ref_invoice_item_row_id']
            : null;
        $icRow = isset($r['ref_inventory_count_row_id']) && $r['ref_inventory_count_row_id'] !== null && $r['ref_inventory_count_row_id'] !== ''
            ? (int) $r['ref_inventory_count_row_id']
            : null;
        $prRow = isset($r['ref_product_row_id']) && $r['ref_product_row_id'] !== null && $r['ref_product_row_id'] !== ''
            ? (int) $r['ref_product_row_id']
            : null;

        $malformed = false;
        $malformedReasons = [];
        if ($refId !== null && $refTypeNorm === null) {
            $malformed = true;
            $malformedReasons[] = 'reference_id_set_reference_type_missing';
        }
        if ($refTypeNorm !== null && $refId === null) {
            $malformed = true;
            $malformedReasons[] = 'reference_type_set_reference_id_missing';
        }

        $referenceTargetExists = null;
        if (!$malformed) {
            if ($refTypeNorm === null && $refId === null) {
                $referenceTargetExists = null;
            } elseif ($refTypeNorm === 'invoice_item' && $refId !== null) {
                $referenceTargetExists = $iiRow !== null;
            } elseif ($refTypeNorm === 'inventory_count' && $refId !== null) {
                $referenceTargetExists = $icRow !== null;
            } elseif ($refTypeNorm === 'product' && $refId !== null) {
                $referenceTargetExists = $prRow !== null;
            } else {
                $referenceTargetExists = null;
            }
        }

        $boundaryClass = '';
        $reasonCodes = [];

        if ($malformed) {
            $boundaryClass = 'malformed_reference_pair';
            $reasonCodes = $malformedReasons;
        } elseif ($refTypeNorm === 'invoice_item' && $refId !== null && $iiRow === null) {
            $boundaryClass = 'missing_reference_target';
            $reasonCodes = ['invoice_item_row_absent'];
        } elseif ($refTypeNorm === 'inventory_count' && $refId !== null && $icRow === null) {
            $boundaryClass = 'missing_reference_target';
            $reasonCodes = ['inventory_count_row_absent'];
        } elseif ($refTypeNorm === 'product' && $refId !== null && $prRow === null) {
            $boundaryClass = 'missing_reference_target';
            $reasonCodes = ['product_reference_target_row_absent'];
        } elseif ($refTypeNorm === 'invoice_item' && $refId !== null && $iiRow !== null) {
            $boundaryClass = 'invoice_item_linked_internal_usage_unexpected';
            $reasonCodes = ['invoice_settlement_contract_expects_sale_or_sale_reversal_only'];
        } elseif ($refTypeNorm === 'inventory_count' && $refId !== null && $icRow !== null) {
            $boundaryClass = 'inventory_count_linked_internal_usage_unexpected';
            $reasonCodes = ['inventory_count_contract_expects_count_adjustment_only'];
        } elseif ($refTypeNorm === 'product' && $refId !== null && $prRow !== null && $refId === $productId) {
            $boundaryClass = 'product_self_reference_internal_usage';
            $reasonCodes = ['opening_stock_contract_uses_manual_adjustment_internal_usage_self_ref_atypical'];
        } elseif ($refTypeNorm === 'product' && $refId !== null && $prRow !== null && $refId !== $productId) {
            $boundaryClass = 'product_reference_mismatch';
            $reasonCodes = ['opening_stock_contract_expects_reference_id_eq_movement_product_id'];
        } elseif ($refTypeNorm !== null
            && !in_array($refTypeNorm, ['invoice_item', 'inventory_count', 'product'], true)) {
            $boundaryClass = 'unknown_reference_type_internal_usage';
            $reasonCodes = ['reference_type_not_in_inventory_writer_vocabulary'];
        } elseif ($refTypeNorm === null && $refId === null) {
            $boundaryClass = 'manual_operator_internal_usage';
            $reasonCodes = ['stock_movement_service_create_manual_forces_null_reference_pair'];
        } else {
            $boundaryClass = 'unknown_reference_type_internal_usage';
            $reasonCodes = ['residual_unclassified_internal_usage_shape'];
        }

        $payload = [
            'movement_id' => $movementId,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'quantity' => $quantity,
            'reference_type' => $refTypeNorm,
            'reference_id' => $refId,
            'created_at' => $createdAt,
            'product_exists' => $productExists,
            'product_deleted' => $productDeleted,
            'reference_target_exists' => $referenceTargetExists,
            'boundary_class' => $boundaryClass,
            'reason_codes' => $reasonCodes,
        ];

        return ['boundary_class' => $boundaryClass, 'payload' => $payload];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only drill-down: rows classified as {@code other_uncategorized} by
 * {@see ProductStockMovementOriginClassificationReportService}, plus a **shape** check on
 * {@code manual_operator_entry} rows (null reference pair) whose {@code movement_type} is outside
 * {@see StockMovementService::MANUAL_ENTRY_MOVEMENT_TYPES} (writer-contract drift).
 *
 * Task: {@code PRODUCTS-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-AUDIT-01}. No writes.
 */
final class ProductStockMovementClassificationDriftAuditService
{
    public const EXAMPLE_CAP = 5;

    /** Must stay byte-for-byte aligned with {@see ProductStockMovementOriginClassificationReportService}. */
    private const ORIGIN_EXPR = <<<'SQL'
CASE
    WHEN sm.reference_type = 'invoice_item'
         AND sm.movement_type IN ('sale', 'sale_reversal') THEN 'invoice_settlement'
    WHEN sm.reference_type = 'inventory_count'
         AND sm.movement_type = 'count_adjustment' THEN 'inventory_count_adjustment'
    WHEN sm.movement_type = 'manual_adjustment'
         AND sm.reference_type = 'product'
         AND sm.reference_id IS NOT NULL
         AND sm.reference_id = sm.product_id THEN 'product_opening_stock'
    WHEN (sm.reference_type IS NULL OR sm.reference_type = '')
         AND sm.reference_id IS NULL THEN 'manual_operator_entry'
    ELSE 'other_uncategorized'
END
SQL;

    /** @var list<string> */
    public const OTHER_UNCATEGORIZED_DRIFT_KEYS = [
        'reference_id_set_reference_type_missing',
        'reference_type_set_reference_id_missing',
        'invoice_item_unexpected_movement_type',
        'inventory_count_unexpected_movement_type',
        'product_reference_id_ne_product_id',
        'product_reference_other_shape',
        'unknown_reference_type',
        'residual_other_uncategorized',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     other_uncategorized_total: int,
     *     counts_by_drift_reason: array<string, int>,
     *     examples_by_drift_reason: array<string, list<array{id: int, product_id: int, movement_type: string, reference_type: ?string, reference_id: ?int, quantity: float, branch_id: ?int, created_at: string}>>,
     *     manual_operator_entry_total: int,
     *     manual_operator_unexpected_movement_type_count: int,
     *     manual_operator_unexpected_movement_type_examples: list<array{id: int, product_id: int, movement_type: string, reference_type: ?string, reference_id: ?int, quantity: float, branch_id: ?int, created_at: string}>
     * }
     */
    public function run(): array
    {
        $origin = self::ORIGIN_EXPR;
        $otherTotal = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM stock_movements sm WHERE ({$origin}) = 'other_uncategorized'"
        )['c'] ?? 0);

        $driftExpr = <<<'SQL'
CASE
    WHEN sm.reference_id IS NOT NULL
         AND (sm.reference_type IS NULL OR TRIM(sm.reference_type) = '') THEN 'reference_id_set_reference_type_missing'
    WHEN sm.reference_id IS NULL
         AND sm.reference_type IS NOT NULL
         AND TRIM(sm.reference_type) != '' THEN 'reference_type_set_reference_id_missing'
    WHEN sm.reference_type = 'invoice_item'
         AND sm.movement_type NOT IN ('sale', 'sale_reversal') THEN 'invoice_item_unexpected_movement_type'
    WHEN sm.reference_type = 'inventory_count'
         AND sm.movement_type != 'count_adjustment' THEN 'inventory_count_unexpected_movement_type'
    WHEN sm.reference_type = 'product'
         AND sm.reference_id IS NOT NULL
         AND sm.reference_id != sm.product_id THEN 'product_reference_id_ne_product_id'
    WHEN sm.reference_type = 'product' THEN 'product_reference_other_shape'
    WHEN sm.reference_type IS NOT NULL
         AND TRIM(sm.reference_type) != ''
         AND sm.reference_type NOT IN ('invoice_item', 'inventory_count', 'product') THEN 'unknown_reference_type'
    ELSE 'residual_other_uncategorized'
END
SQL;

        $counts = array_fill_keys(self::OTHER_UNCATEGORIZED_DRIFT_KEYS, 0);
        $rows = $this->db->fetchAll(
            "SELECT ({$driftExpr}) AS drift_reason, COUNT(*) AS c
             FROM stock_movements sm
             WHERE ({$origin}) = 'other_uncategorized'
             GROUP BY drift_reason"
        );
        foreach ($rows as $row) {
            $k = (string) ($row['drift_reason'] ?? '');
            if (isset($counts[$k])) {
                $counts[$k] = (int) ($row['c'] ?? 0);
            }
        }

        $cap = (int) self::EXAMPLE_CAP;
        $examples = [];
        foreach (self::OTHER_UNCATEGORIZED_DRIFT_KEYS as $key) {
            $ex = $this->db->fetchAll(
                "SELECT sm.id, sm.product_id, sm.movement_type, sm.reference_type, sm.reference_id,
                        sm.quantity, sm.branch_id, sm.created_at
                 FROM stock_movements sm
                 WHERE ({$origin}) = 'other_uncategorized'
                   AND ({$driftExpr}) = ?
                 ORDER BY sm.id ASC
                 LIMIT {$cap}",
                [$key]
            );
            $examples[$key] = array_map($this->mapRow(...), $ex);
        }

        $manualTypes = StockMovementService::MANUAL_ENTRY_MOVEMENT_TYPES;
        $inList = implode(', ', array_map(static fn (string $t): string => "'" . str_replace("'", "''", $t) . "'", $manualTypes));

        $manualTotal = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM stock_movements sm WHERE ({$origin}) = 'manual_operator_entry'"
        )['c'] ?? 0);

        $unexpectedManual = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM stock_movements sm
             WHERE ({$origin}) = 'manual_operator_entry'
               AND sm.movement_type NOT IN ({$inList})"
        )['c'] ?? 0);

        $unexpectedRows = $this->db->fetchAll(
            "SELECT sm.id, sm.product_id, sm.movement_type, sm.reference_type, sm.reference_id,
                    sm.quantity, sm.branch_id, sm.created_at
             FROM stock_movements sm
             WHERE ({$origin}) = 'manual_operator_entry'
               AND sm.movement_type NOT IN ({$inList})
             ORDER BY sm.id ASC
             LIMIT {$cap}"
        );

        return [
            'other_uncategorized_total' => $otherTotal,
            'counts_by_drift_reason' => $counts,
            'examples_by_drift_reason' => $examples,
            'manual_operator_entry_total' => $manualTotal,
            'manual_operator_unexpected_movement_type_count' => $unexpectedManual,
            'manual_operator_unexpected_movement_type_examples' => array_map($this->mapRow(...), $unexpectedRows),
        ];
    }

    private function mapRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'product_id' => (int) $r['product_id'],
            'movement_type' => (string) ($r['movement_type'] ?? ''),
            'reference_type' => isset($r['reference_type']) && $r['reference_type'] !== ''
                ? (string) $r['reference_type']
                : null,
            'reference_id' => isset($r['reference_id']) && $r['reference_id'] !== null && $r['reference_id'] !== ''
                ? (int) $r['reference_id']
                : null,
            'quantity' => (float) ($r['quantity'] ?? 0),
            'branch_id' => isset($r['branch_id']) && $r['branch_id'] !== null && $r['branch_id'] !== ''
                ? (int) $r['branch_id']
                : null,
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }
}

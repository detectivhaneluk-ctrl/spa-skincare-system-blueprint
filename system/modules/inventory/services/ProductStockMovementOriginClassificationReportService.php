<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only rollup of every {@code stock_movements} row into a practical **origin** bucket derived from
 * {@code movement_type} + {@code reference_type} / {@code reference_id} patterns implemented in application
 * writers. Canonical rule text: {@code system/docs/PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md}.
 *
 * Task: {@code PRODUCTS-MOVEMENT-ORIGIN-CLASSIFICATION-REPORT-01}. No writes.
 */
final class ProductStockMovementOriginClassificationReportService
{
    public const EXAMPLE_PER_ORIGIN_CAP = 5;

    public const ORIGIN_KEYS = [
        'invoice_settlement',
        'inventory_count_adjustment',
        'product_opening_stock',
        'manual_operator_entry',
        'other_uncategorized',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     total_movements: int,
     *     movements_on_deleted_or_missing_product: int,
     *     counts_by_origin: array<string, int>,
     *     examples_by_origin: array<string, list<array{id: int, product_id: int, movement_type: string, reference_type: ?string, reference_id: ?int, quantity: float, branch_id: ?int, created_at: string}>>
     * }
     */
    public function run(): array
    {
        $total = (int) ($this->db->fetchOne('SELECT COUNT(*) AS c FROM stock_movements')['c'] ?? 0);

        $onDeleted = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c
             FROM stock_movements sm
             LEFT JOIN products p ON p.id = sm.product_id
             WHERE p.id IS NULL OR p.deleted_at IS NOT NULL'
        )['c'] ?? 0);

        $originExpr = <<<'SQL'
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

        $rows = $this->db->fetchAll(
            "SELECT {$originExpr} AS origin, COUNT(*) AS c
             FROM stock_movements sm
             GROUP BY origin"
        );

        $counts = array_fill_keys(self::ORIGIN_KEYS, 0);
        foreach ($rows as $row) {
            $k = (string) ($row['origin'] ?? '');
            if (isset($counts[$k])) {
                $counts[$k] = (int) ($row['c'] ?? 0);
            }
        }

        $cap = (int) self::EXAMPLE_PER_ORIGIN_CAP;
        $examples = [];
        foreach (self::ORIGIN_KEYS as $key) {
            $examples[$key] = $this->db->fetchAll(
                "SELECT sm.id, sm.product_id, sm.movement_type, sm.reference_type, sm.reference_id,
                        sm.quantity, sm.branch_id, sm.created_at
                 FROM stock_movements sm
                 WHERE ({$originExpr}) = ?
                 ORDER BY sm.id ASC
                 LIMIT {$cap}",
                [$key]
            );
            $examples[$key] = array_map(static function (array $r): array {
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
            }, $examples[$key]);
        }

        return [
            'total_movements' => $total,
            'movements_on_deleted_or_missing_product' => $onDeleted,
            'counts_by_origin' => $counts,
            'examples_by_origin' => $examples,
        ];
    }
}

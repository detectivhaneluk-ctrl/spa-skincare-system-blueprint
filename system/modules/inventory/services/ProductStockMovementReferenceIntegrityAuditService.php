<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only audit: {@code stock_movements} reference columns vs rows writers are supposed to link
 * ({@code invoice_items}, {@code inventory_counts}, {@code products}) plus malformed reference pairs.
 *
 * Task: {@code PRODUCTS-STOCK-MOVEMENT-REFERENCE-INTEGRITY-AUDIT-01}. No writes.
 */
final class ProductStockMovementReferenceIntegrityAuditService
{
    public const EXAMPLE_CAP = 5;

    public const ANOMALY_KEYS = [
        'invoice_item_reference_missing_row',
        'inventory_count_reference_missing_row',
        'movement_product_id_missing_row',
        'product_reference_target_missing_row',
        'reference_id_set_reference_type_missing',
        'reference_type_set_reference_id_missing',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     total_movements: int,
     *     counts_by_anomaly: array<string, int>,
     *     examples_by_anomaly: array<string, list<array{id: int, product_id: int, movement_type: string, reference_type: ?string, reference_id: ?int, quantity: float, branch_id: ?int, created_at: string}>>
     * }
     */
    public function run(): array
    {
        $total = (int) ($this->db->fetchOne('SELECT COUNT(*) AS c FROM stock_movements')['c'] ?? 0);

        $counts = [];
        $examples = [];
        $cap = (int) self::EXAMPLE_CAP;

        foreach (self::ANOMALY_KEYS as $key) {
            [$where, $params] = $this->whereForAnomaly($key);
            $counts[$key] = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM stock_movements sm WHERE {$where}",
                $params
            )['c'] ?? 0);

            $rows = $this->db->fetchAll(
                "SELECT sm.id, sm.product_id, sm.movement_type, sm.reference_type, sm.reference_id,
                        sm.quantity, sm.branch_id, sm.created_at
                 FROM stock_movements sm
                 WHERE {$where}
                 ORDER BY sm.id ASC
                 LIMIT {$cap}",
                $params
            );
            $examples[$key] = array_map($this->mapRow(...), $rows);
        }

        return [
            'total_movements' => $total,
            'counts_by_anomaly' => $counts,
            'examples_by_anomaly' => $examples,
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function whereForAnomaly(string $key): array
    {
        return match ($key) {
            'invoice_item_reference_missing_row' => [
                "sm.reference_type = 'invoice_item'
                 AND sm.reference_id IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.id = sm.reference_id)",
                [],
            ],
            'inventory_count_reference_missing_row' => [
                "sm.reference_type = 'inventory_count'
                 AND sm.reference_id IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM inventory_counts ic WHERE ic.id = sm.reference_id)",
                [],
            ],
            'movement_product_id_missing_row' => [
                'NOT EXISTS (SELECT 1 FROM products p WHERE p.id = sm.product_id)',
                [],
            ],
            'product_reference_target_missing_row' => [
                "sm.reference_type = 'product'
                 AND sm.reference_id IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM products p WHERE p.id = sm.reference_id)",
                [],
            ],
            'reference_id_set_reference_type_missing' => [
                'sm.reference_id IS NOT NULL
                 AND (sm.reference_type IS NULL OR TRIM(sm.reference_type) = \'\')',
                [],
            ],
            'reference_type_set_reference_id_missing' => [
                'sm.reference_id IS NULL
                 AND sm.reference_type IS NOT NULL
                 AND TRIM(sm.reference_type) != \'\'',
                [],
            ],
            default => throw new \InvalidArgumentException('Unknown anomaly key: ' . $key),
        };
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

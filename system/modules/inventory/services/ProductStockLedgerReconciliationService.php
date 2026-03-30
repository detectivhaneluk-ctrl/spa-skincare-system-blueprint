<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only reconciliation: {@code products.stock_quantity} vs rolled-up {@code stock_movements.quantity}.
 *
 * **Sign semantics (no re-signing):** Each movement row stores the signed delta exactly as persisted at write time
 * (see {@see StockMovementService::signedQuantity}). Implied balance is {@code SUM(stock_movements.quantity)} per
 * product; this report does not reinterpret {@code movement_type}—historical rows are taken at face value.
 *
 * Operator-facing contract: {@code system/docs/PRODUCT-STOCK-LEDGER-RECONCILIATION-OPS.md}.
 */
final class ProductStockLedgerReconciliationService
{
    public const QTY_EPS = 1.0e-6;

    public const MISMATCH_EXAMPLE_CAP = 50;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     products_scanned: int,
     *     matched_count: int,
     *     mismatched_count: int,
     *     mismatch_examples: list<array{product_id: int, sku: string, on_hand: float, implied_net_from_movements: float, delta: float}>,
     *     qty_epsilon: float,
     *     sign_semantics_note: string
     * }
     */
    public function run(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.sku, p.name, p.stock_quantity AS on_hand,
                    COALESCE(SUM(sm.quantity), 0) AS implied_net
             FROM products p
             LEFT JOIN stock_movements sm ON sm.product_id = p.id
             WHERE p.deleted_at IS NULL
             GROUP BY p.id, p.sku, p.name, p.stock_quantity
             ORDER BY p.id ASC'
        );

        $matched = 0;
        $mismatched = 0;
        $examples = [];

        foreach ($rows as $row) {
            $onHand = (float) ($row['on_hand'] ?? 0);
            $implied = (float) ($row['implied_net'] ?? 0);
            if (abs($onHand - $implied) <= self::QTY_EPS) {
                $matched++;
            } else {
                $mismatched++;
                if (count($examples) < self::MISMATCH_EXAMPLE_CAP) {
                    $examples[] = [
                        'product_id' => (int) $row['id'],
                        'sku' => (string) ($row['sku'] ?? ''),
                        'on_hand' => $onHand,
                        'implied_net_from_movements' => $implied,
                        'delta' => $onHand - $implied,
                    ];
                }
            }
        }

        return [
            'products_scanned' => count($rows),
            'matched_count' => $matched,
            'mismatched_count' => $mismatched,
            'mismatch_examples' => $examples,
            'qty_epsilon' => self::QTY_EPS,
            'sign_semantics_note' => 'Implied net = SUM(stock_movements.quantity) per product; quantities are compared as stored (application signing applied at insert time, not recomputed here).',
        ];
    }
}

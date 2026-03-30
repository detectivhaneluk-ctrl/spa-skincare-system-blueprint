<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only audit: global catalog SKUs ({@code products.branch_id IS NULL}) whose {@code stock_movements}
 * carry a non-null {@code branch_id} (branch attribution on the movement row while on-hand remains a single
 * {@code products.stock_quantity} per product).
 *
 * Task: {@code PRODUCTS-GLOBAL-SKU-BRANCH-ATTRIBUTION-AUDIT-01}. No writes.
 */
final class ProductGlobalSkuBranchAttributionAuditService
{
    public const EXAMPLE_PRODUCT_CAP = 15;

    public const EXAMPLE_MOVEMENT_CAP = 20;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     products_scanned: int,
     *     affected_global_products_count: int,
     *     affected_movements_count: int,
     *     example_products: list<array{product_id: int, sku: string, name: string, branch_tagged_movements: int}>,
     *     example_movements: list<array{id: int, product_id: int, movement_type: string, quantity: float, reference_type: ?string, reference_id: ?int, branch_id: int, created_at: string}>
     * }
     */
    public function run(): array
    {
        $productsScanned = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM products WHERE deleted_at IS NULL AND branch_id IS NULL'
        )['c'] ?? 0);

        $affectedProducts = (int) ($this->db->fetchOne(
            'SELECT COUNT(DISTINCT p.id) AS c
             FROM products p
             INNER JOIN stock_movements sm ON sm.product_id = p.id AND sm.branch_id IS NOT NULL
             WHERE p.deleted_at IS NULL AND p.branch_id IS NULL'
        )['c'] ?? 0);

        $affectedMovements = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS c
             FROM stock_movements sm
             INNER JOIN products p ON p.id = sm.product_id
             WHERE p.deleted_at IS NULL AND p.branch_id IS NULL AND sm.branch_id IS NOT NULL'
        )['c'] ?? 0);

        $exampleProducts = $this->db->fetchAll(
            'SELECT p.id, p.sku, p.name, COUNT(sm.id) AS branch_tagged_movements
             FROM products p
             INNER JOIN stock_movements sm ON sm.product_id = p.id AND sm.branch_id IS NOT NULL
             WHERE p.deleted_at IS NULL AND p.branch_id IS NULL
             GROUP BY p.id, p.sku, p.name
             ORDER BY p.id ASC
             LIMIT ' . (int) self::EXAMPLE_PRODUCT_CAP
        );

        $exampleMovements = $this->db->fetchAll(
            'SELECT sm.id, sm.product_id, sm.movement_type, sm.quantity, sm.reference_type, sm.reference_id,
                    sm.branch_id, sm.created_at
             FROM stock_movements sm
             INNER JOIN products p ON p.id = sm.product_id
             WHERE p.deleted_at IS NULL AND p.branch_id IS NULL AND sm.branch_id IS NOT NULL
             ORDER BY sm.id ASC
             LIMIT ' . (int) self::EXAMPLE_MOVEMENT_CAP
        );

        return [
            'products_scanned' => $productsScanned,
            'affected_global_products_count' => $affectedProducts,
            'affected_movements_count' => $affectedMovements,
            'example_products' => array_map(static function (array $row): array {
                return [
                    'product_id' => (int) $row['id'],
                    'sku' => (string) ($row['sku'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'branch_tagged_movements' => (int) ($row['branch_tagged_movements'] ?? 0),
                ];
            }, $exampleProducts),
            'example_movements' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'product_id' => (int) $row['product_id'],
                    'movement_type' => (string) ($row['movement_type'] ?? ''),
                    'quantity' => (float) ($row['quantity'] ?? 0),
                    'reference_type' => isset($row['reference_type']) && $row['reference_type'] !== ''
                        ? (string) $row['reference_type']
                        : null,
                    'reference_id' => isset($row['reference_id']) && $row['reference_id'] !== null && $row['reference_id'] !== ''
                        ? (int) $row['reference_id']
                        : null,
                    'branch_id' => (int) $row['branch_id'],
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $exampleMovements),
        ];
    }
}

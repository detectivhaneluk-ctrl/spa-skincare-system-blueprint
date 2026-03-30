<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Tenant inventory count reads:
 *
 * | Class | Entry points |
 * | --- | --- |
 * | **1. Strict branch-owned** | {@see findInTenantScope}, {@see listInTenantScope}, {@see countInTenantScope} — count + product join with org EXISTS on {@code products} |
 * | **2. (n/a)** | — |
 * | **3. Deprecated unscoped** | {@see find}, {@see list}, {@see count} — **tenant modules must not call** (FND-TNT-22 readonly gate) |
 */
final class InventoryCountRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    )
    {
    }

    /**
     * @deprecated No product/org guard on join. Prefer {@see findInTenantScope}.
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT ic.*, p.name AS product_name, p.sku AS product_sku
             FROM inventory_counts ic
             INNER JOIN products p ON p.id = ic.product_id
             WHERE ic.id = ?',
            [$id]
        );
    }

    public function findInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');

        return $this->db->fetchOne(
            'SELECT ic.*, p.name AS product_name, p.sku AS product_sku
             FROM inventory_counts ic
             INNER JOIN products p ON p.id = ic.product_id
             WHERE ic.id = ? AND ic.branch_id = ? AND p.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId, $branchId], $frag['params'])
        );
    }

    /**
     * @deprecated No org EXISTS on product join. Prefer {@see listInTenantScope}.
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT ic.*, p.name AS product_name, p.sku AS product_sku
                FROM inventory_counts ic
                INNER JOIN products p ON p.id = ic.product_id
                WHERE 1=1';
        $params = [];

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND ic.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND ic.branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND ic.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $sql .= ' ORDER BY ic.created_at DESC, ic.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function listInTenantScope(array $filters = [], int $branchId = 0, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT ic.*, p.name AS product_name, p.sku AS product_sku
                FROM inventory_counts ic
                INNER JOIN products p ON p.id = ic.product_id
                WHERE ic.branch_id = ? AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId, $branchId], $frag['params']);

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND ic.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }

        $sql .= ' ORDER BY ic.created_at DESC, ic.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @deprecated No org guard. Prefer {@see countInTenantScope}.
     */
    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM inventory_counts WHERE 1=1';
        $params = [];

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function countInTenantScope(array $filters = [], int $branchId = 0): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT COUNT(*) AS c
                FROM inventory_counts ic
                INNER JOIN products p ON p.id = ic.product_id
                WHERE ic.branch_id = ? AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId, $branchId], $frag['params']);

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND ic.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('inventory_counts', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'product_id', 'expected_quantity', 'counted_quantity', 'variance_quantity',
            'notes', 'branch_id', 'created_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}

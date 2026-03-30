<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Sales\Services\SalesTenantScope;

/**
 * Tenant inventory movement reads:
 *
 * | Class | Entry points |
 * | --- | --- |
 * | **1. Strict branch-owned** | {@see findInTenantScope}, {@see listInTenantScope}, {@see countInTenantScope} — movement + product join with org EXISTS on {@code products} |
 * | **2. Invoice-plane aggregates** | {@see sumNetQuantityForInvoiceItem}, {@see aggregateInvoiceItemSettlement}, {@see aggregateInvoiceItemSettlementRefundReturnDetail} — {@see SalesTenantScope::invoiceClause} when non-empty; id-only fallback only when scope SQL empty (explicit global/repair tooling) |
 * | **3. Deprecated unscoped** | {@see find}, {@see list}, {@see count} — **tenant modules must not call** (FND-TNT-22 readonly gate) |
 */
final class StockMovementRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
        private SalesTenantScope $salesTenantScope,
    )
    {
    }

    /**
     * @deprecated No product/org guard on join. Prefer {@see findInTenantScope}.
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT sm.*, p.name AS product_name, p.sku AS product_sku
             FROM stock_movements sm
             INNER JOIN products p ON p.id = sm.product_id
             WHERE sm.id = ?',
            [$id]
        );
    }

    public function findInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');

        return $this->db->fetchOne(
            'SELECT sm.*, p.name AS product_name, p.sku AS product_sku
             FROM stock_movements sm
             INNER JOIN products p ON p.id = sm.product_id
             WHERE sm.id = ? AND sm.branch_id = ? AND p.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId, $branchId], $frag['params'])
        );
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT sm.*, p.name AS product_name, p.sku AS product_sku
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE 1=1';
        $params = [];

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND sm.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['movement_type'])) {
            $sql .= ' AND sm.movement_type = ?';
            $params[] = $filters['movement_type'];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND sm.branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND sm.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $sql .= ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function listInTenantScope(array $filters = [], int $branchId = 0, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT sm.*, p.name AS product_name, p.sku AS product_sku
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE sm.branch_id = ? AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId, $branchId], $frag['params']);

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND sm.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['movement_type'])) {
            $sql .= ' AND sm.movement_type = ?';
            $params[] = $filters['movement_type'];
        }

        $sql .= ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @deprecated No org guard. Prefer {@see countInTenantScope}.
     */
    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM stock_movements WHERE 1=1';
        $params = [];

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['movement_type'])) {
            $sql .= ' AND movement_type = ?';
            $params[] = $filters['movement_type'];
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
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE sm.branch_id = ? AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId, $branchId], $frag['params']);

        if (array_key_exists('product_id', $filters) && $filters['product_id']) {
            $sql .= ' AND sm.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['movement_type'])) {
            $sql .= ' AND sm.movement_type = ?';
            $params[] = $filters['movement_type'];
        }

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function existsSaleDeductionForInvoiceItem(int $invoiceItemId): bool
    {
        if ($invoiceItemId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS x FROM stock_movements
             WHERE movement_type = \'sale\'
               AND reference_type = \'invoice_item\'
               AND reference_id = ?
             LIMIT 1',
            [$invoiceItemId]
        );

        return $row !== null;
    }

    /**
     * Net signed quantity from invoice-linked settlement rows (negative = stock removed for the line).
     * When tenant invoice-plane scope resolves (non-empty {@see SalesTenantScope::invoiceClause}), aggregates only
     * rows whose {@code reference_id} belongs to an {@code invoice_items} row on an in-scope {@code invoices} row.
     * When scope SQL is empty (explicit global/repair tooling), falls back to reference_id-only aggregation.
     */
    public function sumNetQuantityForInvoiceItem(int $invoiceItemId): float
    {
        if ($invoiceItemId <= 0) {
            return 0.0;
        }
        $scope = $this->salesTenantScope->invoiceClause('inv');
        if ($scope['sql'] === '') {
            $row = $this->db->fetchOne(
                "SELECT COALESCE(SUM(quantity), 0) AS net
                 FROM stock_movements
                 WHERE reference_type = 'invoice_item'
                   AND reference_id = ?
                   AND movement_type IN ('sale', 'sale_reversal')",
                [$invoiceItemId]
            );

            return (float) ($row['net'] ?? 0);
        }

        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(sm.quantity), 0) AS net
             FROM stock_movements sm
             INNER JOIN invoice_items ii ON ii.id = sm.reference_id AND sm.reference_type = 'invoice_item'
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.deleted_at IS NULL
             WHERE sm.reference_type = 'invoice_item'
               AND sm.reference_id = ?
               AND sm.movement_type IN ('sale', 'sale_reversal')" . $scope['sql'],
            array_merge([$invoiceItemId], $scope['params'])
        );

        return (float) ($row['net'] ?? 0);
    }

    /**
     * Batch read: net signed quantity and row counts for invoice settlement rows per invoice line.
     *
     * @param list<int> $invoiceItemIds
     * @return array<int, array{net: float, movement_count_sale: int, movement_count_sale_reversal: int}>
     */
    public function aggregateInvoiceItemSettlement(array $invoiceItemIds): array
    {
        $invoiceItemIds = array_values(array_unique(array_filter(array_map('intval', $invoiceItemIds), fn (int $id) => $id > 0)));
        if ($invoiceItemIds === []) {
            return [];
        }

        $out = [];
        foreach ($invoiceItemIds as $id) {
            $out[$id] = ['net' => 0.0, 'movement_count_sale' => 0, 'movement_count_sale_reversal' => 0];
        }

        $scope = $this->salesTenantScope->invoiceClause('inv');
        $chunkSize = 500;
        for ($i = 0, $n = count($invoiceItemIds); $i < $n; $i += $chunkSize) {
            $chunk = array_slice($invoiceItemIds, $i, $chunkSize);
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            if ($scope['sql'] === '') {
                $rows = $this->db->fetchAll(
                    "SELECT reference_id,
                            COALESCE(SUM(quantity), 0) AS net,
                            SUM(CASE WHEN movement_type = 'sale' THEN 1 ELSE 0 END) AS sale_cnt,
                            SUM(CASE WHEN movement_type = 'sale_reversal' THEN 1 ELSE 0 END) AS rev_cnt
                     FROM stock_movements
                     WHERE reference_type = 'invoice_item'
                       AND reference_id IN ({$placeholders})
                       AND movement_type IN ('sale', 'sale_reversal')
                     GROUP BY reference_id",
                    $chunk
                );
            } else {
                $rows = $this->db->fetchAll(
                    "SELECT sm.reference_id,
                            COALESCE(SUM(sm.quantity), 0) AS net,
                            SUM(CASE WHEN sm.movement_type = 'sale' THEN 1 ELSE 0 END) AS sale_cnt,
                            SUM(CASE WHEN sm.movement_type = 'sale_reversal' THEN 1 ELSE 0 END) AS rev_cnt
                     FROM stock_movements sm
                     INNER JOIN invoice_items ii ON ii.id = sm.reference_id AND sm.reference_type = 'invoice_item'
                     INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.deleted_at IS NULL
                     WHERE sm.reference_type = 'invoice_item'
                       AND sm.reference_id IN ({$placeholders})
                       AND sm.movement_type IN ('sale', 'sale_reversal')" . $scope['sql'] . '
                     GROUP BY sm.reference_id',
                    array_merge($chunk, $scope['params'])
                );
            }
            foreach ($rows as $row) {
                $rid = (int) ($row['reference_id'] ?? 0);
                if ($rid <= 0 || !isset($out[$rid])) {
                    continue;
                }
                $out[$rid] = [
                    'net' => (float) ($row['net'] ?? 0),
                    'movement_count_sale' => (int) ($row['sale_cnt'] ?? 0),
                    'movement_count_sale_reversal' => (int) ($row['rev_cnt'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Batch read: settlement aggregates per invoice line including sale/reversal quantity sums and timestamps.
     *
     * @param list<int> $invoiceItemIds
     * @return array<int, array{
     *     net: float,
     *     sale_count: int,
     *     sale_reversal_count: int,
     *     sale_quantity_total: float,
     *     sale_reversal_quantity_total: float,
     *     first_sale_at: ?string,
     *     latest_sale_reversal_at: ?string
     * }>
     */
    public function aggregateInvoiceItemSettlementRefundReturnDetail(array $invoiceItemIds): array
    {
        $invoiceItemIds = array_values(array_unique(array_filter(array_map('intval', $invoiceItemIds), fn (int $id) => $id > 0)));
        if ($invoiceItemIds === []) {
            return [];
        }

        $template = [
            'net' => 0.0,
            'sale_count' => 0,
            'sale_reversal_count' => 0,
            'sale_quantity_total' => 0.0,
            'sale_reversal_quantity_total' => 0.0,
            'first_sale_at' => null,
            'latest_sale_reversal_at' => null,
        ];

        $out = [];
        foreach ($invoiceItemIds as $id) {
            $out[$id] = $template;
        }

        $scope = $this->salesTenantScope->invoiceClause('inv');
        $chunkSize = 500;
        for ($i = 0, $n = count($invoiceItemIds); $i < $n; $i += $chunkSize) {
            $chunk = array_slice($invoiceItemIds, $i, $chunkSize);
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            if ($scope['sql'] === '') {
                $rows = $this->db->fetchAll(
                    "SELECT reference_id,
                            COALESCE(SUM(quantity), 0) AS net,
                            SUM(CASE WHEN movement_type = 'sale' THEN 1 ELSE 0 END) AS sale_cnt,
                            SUM(CASE WHEN movement_type = 'sale_reversal' THEN 1 ELSE 0 END) AS rev_cnt,
                            COALESCE(SUM(CASE WHEN movement_type = 'sale' THEN quantity ELSE 0 END), 0) AS sale_qty_total,
                            COALESCE(SUM(CASE WHEN movement_type = 'sale_reversal' THEN quantity ELSE 0 END), 0) AS rev_qty_total,
                            MIN(CASE WHEN movement_type = 'sale' THEN created_at END) AS first_sale_at,
                            MAX(CASE WHEN movement_type = 'sale_reversal' THEN created_at END) AS latest_sale_reversal_at
                     FROM stock_movements
                     WHERE reference_type = 'invoice_item'
                       AND reference_id IN ({$placeholders})
                       AND movement_type IN ('sale', 'sale_reversal')
                     GROUP BY reference_id",
                    $chunk
                );
            } else {
                $rows = $this->db->fetchAll(
                    "SELECT sm.reference_id,
                            COALESCE(SUM(sm.quantity), 0) AS net,
                            SUM(CASE WHEN sm.movement_type = 'sale' THEN 1 ELSE 0 END) AS sale_cnt,
                            SUM(CASE WHEN sm.movement_type = 'sale_reversal' THEN 1 ELSE 0 END) AS rev_cnt,
                            COALESCE(SUM(CASE WHEN sm.movement_type = 'sale' THEN sm.quantity ELSE 0 END), 0) AS sale_qty_total,
                            COALESCE(SUM(CASE WHEN sm.movement_type = 'sale_reversal' THEN sm.quantity ELSE 0 END), 0) AS rev_qty_total,
                            MIN(CASE WHEN sm.movement_type = 'sale' THEN sm.created_at END) AS first_sale_at,
                            MAX(CASE WHEN sm.movement_type = 'sale_reversal' THEN sm.created_at END) AS latest_sale_reversal_at
                     FROM stock_movements sm
                     INNER JOIN invoice_items ii ON ii.id = sm.reference_id AND sm.reference_type = 'invoice_item'
                     INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.deleted_at IS NULL
                     WHERE sm.reference_type = 'invoice_item'
                       AND sm.reference_id IN ({$placeholders})
                       AND sm.movement_type IN ('sale', 'sale_reversal')" . $scope['sql'] . '
                     GROUP BY sm.reference_id',
                    array_merge($chunk, $scope['params'])
                );
            }
            foreach ($rows as $row) {
                $rid = (int) ($row['reference_id'] ?? 0);
                if ($rid <= 0 || !isset($out[$rid])) {
                    continue;
                }
                $fs = $row['first_sale_at'] ?? null;
                $lr = $row['latest_sale_reversal_at'] ?? null;
                $out[$rid] = [
                    'net' => (float) ($row['net'] ?? 0),
                    'sale_count' => (int) ($row['sale_cnt'] ?? 0),
                    'sale_reversal_count' => (int) ($row['rev_cnt'] ?? 0),
                    'sale_quantity_total' => (float) ($row['sale_qty_total'] ?? 0),
                    'sale_reversal_quantity_total' => (float) ($row['rev_qty_total'] ?? 0),
                    'first_sale_at' => $fs !== null && $fs !== '' ? (string) $fs : null,
                    'latest_sale_reversal_at' => $lr !== null && $lr !== '' ? (string) $lr : null,
                ];
            }
        }

        return $out;
    }

    public function create(array $data): int
    {
        $this->db->insert('stock_movements', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'product_id', 'movement_type', 'quantity', 'reference_type', 'reference_id',
            'notes', 'branch_id', 'created_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}

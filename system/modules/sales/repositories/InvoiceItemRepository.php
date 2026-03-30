<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Core\App\Database;
use Modules\Sales\Services\SalesTenantScope;

final class InvoiceItemRepository
{
    public function __construct(
        private Database $db,
        private SalesTenantScope $tenantScope
    )
    {
    }

    public function getByInvoiceId(int $invoiceId): array
    {
        $scope = $this->tenantScope->invoiceItemByInvoiceExistsClause('ii', 'si');
        return $this->db->fetchAll(
            'SELECT ii.* FROM invoice_items ii WHERE ii.invoice_id = ?' . $scope['sql'] . ' ORDER BY ii.sort_order, ii.id',
            array_merge([$invoiceId], $scope['params'])
        );
    }

    public function create(array $data): int
    {
        $this->db->insert('invoice_items', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) return;
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $scope = $this->tenantScope->invoiceItemByInvoiceExistsClause('invoice_items', 'si');
        $sql = 'UPDATE invoice_items SET ' . implode(', ', $cols) . ' WHERE id = ?' . $scope['sql'];
        $this->db->query($sql, array_merge($vals, $scope['params']));
    }

    public function delete(int $id): void
    {
        $scope = $this->tenantScope->invoiceItemByInvoiceExistsClause('invoice_items', 'si');
        $this->db->query(
            'DELETE FROM invoice_items WHERE id = ?' . $scope['sql'],
            array_merge([$id], $scope['params'])
        );
    }

    public function deleteByInvoiceId(int $invoiceId): void
    {
        $scope = $this->tenantScope->invoiceItemByInvoiceExistsClause('invoice_items', 'si');
        $this->db->query(
            'DELETE FROM invoice_items WHERE invoice_id = ?' . $scope['sql'],
            array_merge([$invoiceId], $scope['params'])
        );
    }

    public function mergeLineMeta(int $itemId, array $fragment): void
    {
        $scope = $this->tenantScope->invoiceItemByInvoiceExistsClause('ii', 'si');
        $row = $this->db->fetchOne(
            'SELECT ii.id, ii.line_meta FROM invoice_items ii WHERE ii.id = ?' . $scope['sql'],
            array_merge([$itemId], $scope['params'])
        );
        if ($row === null) {
            return;
        }
        $existing = [];
        if (isset($row['line_meta']) && $row['line_meta'] !== null && $row['line_meta'] !== '') {
            $decoded = json_decode((string) $row['line_meta'], true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
        $merged = array_replace_recursive($existing, $fragment);
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->query(
            'UPDATE invoice_items ii SET line_meta = ? WHERE ii.id = ?' . $scope['sql'],
            array_merge([$json, $itemId], $scope['params'])
        );
    }

    private function normalize(array $data): array
    {
        $allowed = ['invoice_id', 'item_type', 'source_id', 'description', 'quantity', 'unit_price', 'discount_amount', 'tax_rate', 'line_meta', 'line_total', 'sort_order'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('line_meta', $out)) {
            $lm = $out['line_meta'];
            if ($lm === null || $lm === '') {
                $out['line_meta'] = null;
            } elseif (is_array($lm)) {
                $out['line_meta'] = json_encode($lm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (!is_string($lm)) {
                unset($out['line_meta']);
            }
        }

        return $out;
    }
}

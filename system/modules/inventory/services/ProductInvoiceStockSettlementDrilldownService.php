<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Modules\Inventory\Repositories\StockMovementRepository;

/**
 * Read-only invoice line ↔ settlement movements drilldown. Uses the same net target as
 * {@see InvoiceStockSettlementService::syncProductStockWithInvoiceSettlement}; does not write or sync.
 */
final class ProductInvoiceStockSettlementDrilldownService
{
    public const DRILLDOWN_SCHEMA_VERSION = '1.0.0';

    public const QTY_EPS = 1.0e-6;

    public const LINE_EXAMPLE_CAP = 15;

    public const INVOICE_SAMPLE_CAP = 20;

    public const SETTLEMENT_ALIGNED = 'aligned';

    public const SETTLEMENT_UNDER_SETTLED = 'under_settled';

    public const SETTLEMENT_OVER_SETTLED = 'over_settled';

    public const SETTLEMENT_MISSING_PRODUCT = 'missing_product';

    public const SETTLEMENT_INACTIVE_PRODUCT = 'inactive_product';

    public const SETTLEMENT_BRANCH_CONTRACT_RISK = 'branch_contract_risk';

    public function __construct(
        private Database $db,
        private StockMovementRepository $stockMovements
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $lines = $this->fetchProductInvoiceLines($invoiceIdFilter);
        $itemIds = array_values(array_unique(array_map(fn (array $r) => (int) ($r['invoice_item_id'] ?? 0), $lines)));
        $agg = $this->stockMovements->aggregateInvoiceItemSettlement($itemIds);

        $productIds = [];
        foreach ($lines as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
        $productMap = $this->loadProductsByIds(array_keys($productIds));

        $detailLines = [];
        $invoiceIds = [];

        foreach ($lines as $row) {
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $itemId = (int) ($row['invoice_item_id'] ?? 0);
            $invoiceIds[$invoiceId] = true;

            $st = (string) ($row['invoice_status'] ?? '');
            $targetAllPaid = ($st === 'paid');
            $lineQty = (float) ($row['line_quantity'] ?? 0);
            $validQty = is_finite($lineQty) && $lineQty > 0;
            $targetNet = ($targetAllPaid && $validQty) ? -$lineQty : 0.0;

            $productId = (int) ($row['product_id'] ?? 0);
            $invoiceBranchId = isset($row['invoice_branch_id']) && $row['invoice_branch_id'] !== '' && $row['invoice_branch_id'] !== null
                ? (int) $row['invoice_branch_id']
                : null;

            $bucket = $agg[$itemId] ?? ['net' => 0.0, 'movement_count_sale' => 0, 'movement_count_sale_reversal' => 0];
            $currentNet = (float) $bucket['net'];
            $delta = $targetNet - $currentNet;

            $product = ($productId > 0) ? ($productMap[$productId] ?? null) : null;
            $productBranchId = null;
            if ($product !== null) {
                $productBranchId = isset($product['branch_id']) && $product['branch_id'] !== '' && $product['branch_id'] !== null
                    ? (int) $product['branch_id']
                    : null;
            }

            [$status, $reasonCodes] = $this->resolveStatusAndReasons(
                $product,
                $productId,
                $invoiceBranchId,
                $productBranchId,
                $delta,
                $validQty,
                $lineQty
            );

            $detailLines[] = [
                'invoice_id' => $invoiceId,
                'invoice_item_id' => $itemId,
                'invoice_status' => $st,
                'invoice_branch_id' => $invoiceBranchId,
                'product_id' => $productId,
                'product_branch_id' => $productBranchId,
                'line_quantity' => $lineQty,
                'target_net_quantity' => $targetNet,
                'current_net_quantity_from_movements' => $currentNet,
                'settlement_delta' => $delta,
                'movement_count_sale' => (int) $bucket['movement_count_sale'],
                'movement_count_sale_reversal' => (int) $bucket['movement_count_sale_reversal'],
                'settlement_status' => $status,
                'reason_codes' => $reasonCodes,
            ];
        }

        $statusOrder = [
            self::SETTLEMENT_ALIGNED => 0,
            self::SETTLEMENT_UNDER_SETTLED => 1,
            self::SETTLEMENT_OVER_SETTLED => 2,
            self::SETTLEMENT_MISSING_PRODUCT => 3,
            self::SETTLEMENT_INACTIVE_PRODUCT => 4,
            self::SETTLEMENT_BRANCH_CONTRACT_RISK => 5,
        ];

        $settlementStatusCounts = [
            self::SETTLEMENT_ALIGNED => 0,
            self::SETTLEMENT_UNDER_SETTLED => 0,
            self::SETTLEMENT_OVER_SETTLED => 0,
            self::SETTLEMENT_MISSING_PRODUCT => 0,
            self::SETTLEMENT_INACTIVE_PRODUCT => 0,
            self::SETTLEMENT_BRANCH_CONTRACT_RISK => 0,
        ];

        $affectedLines = [];
        foreach ($detailLines as $dl) {
            $s = (string) $dl['settlement_status'];
            if (isset($settlementStatusCounts[$s])) {
                $settlementStatusCounts[$s]++;
            }
            if ($s !== self::SETTLEMENT_ALIGNED) {
                $affectedLines[] = $dl;
            }
        }

        usort($affectedLines, static function (array $a, array $b) use ($statusOrder): int {
            $sa = (string) ($a['settlement_status'] ?? '');
            $sb = (string) ($b['settlement_status'] ?? '');
            $oa = $statusOrder[$sa] ?? 99;
            $ob = $statusOrder[$sb] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            if ((int) $a['invoice_id'] !== (int) $b['invoice_id']) {
                return ((int) $a['invoice_id']) <=> ((int) $b['invoice_id']);
            }

            return ((int) $a['invoice_item_id']) <=> ((int) $b['invoice_item_id']);
        });

        $lineExamples = array_slice($affectedLines, 0, self::LINE_EXAMPLE_CAP);

        $affectedInvoiceIds = [];
        foreach ($affectedLines as $dl) {
            $affectedInvoiceIds[(int) $dl['invoice_id']] = true;
        }
        $sampleIds = array_keys($affectedInvoiceIds);
        sort($sampleIds, SORT_NUMERIC);
        $affectedInvoiceIdsSample = array_slice($sampleIds, 0, self::INVOICE_SAMPLE_CAP);

        return [
            'drilldown_schema_version' => self::DRILLDOWN_SCHEMA_VERSION,
            'generated_at_utc' => $generatedAt,
            'invoice_id_filter' => $invoiceIdFilter,
            'lines_scanned' => count($detailLines),
            'invoices_scanned' => count($invoiceIds),
            'settlement_status_counts' => $settlementStatusCounts,
            'affected_lines_count' => count($affectedLines),
            'affected_invoice_ids_sample' => $affectedInvoiceIdsSample,
            'line_examples' => $lineExamples,
            'lines' => $detailLines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchProductInvoiceLines(?int $invoiceIdFilter): array
    {
        $sql = 'SELECT ii.id AS invoice_item_id,
                       ii.invoice_id AS invoice_id,
                       ii.source_id AS product_id,
                       ii.quantity AS line_quantity,
                       i.status AS invoice_status,
                       i.branch_id AS invoice_branch_id
                FROM invoice_items ii
                INNER JOIN invoices i ON i.id = ii.invoice_id AND i.deleted_at IS NULL
                WHERE ii.item_type = \'product\'';
        $params = [];
        if ($invoiceIdFilter !== null && $invoiceIdFilter > 0) {
            $sql .= ' AND ii.invoice_id = ?';
            $params[] = $invoiceIdFilter;
        }
        $sql .= ' ORDER BY ii.invoice_id ASC, ii.sort_order ASC, ii.id ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadProductsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        $chunkSize = 500;
        for ($i = 0, $n = count($ids); $i < $n; $i += $chunkSize) {
            $chunk = array_slice($ids, $i, $chunkSize);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->db->fetchAll(
                "SELECT * FROM products WHERE deleted_at IS NULL AND id IN ({$ph})",
                $chunk
            );
            foreach ($rows as $row) {
                $out[(int) $row['id']] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function resolveStatusAndReasons(
        ?array $product,
        int $productId,
        ?int $invoiceBranchId,
        ?int $productBranchId,
        float $delta,
        bool $validQty,
        float $lineQty
    ): array {
        $reasons = [];
        if (!$validQty) {
            $reasons[] = 'non_positive_line_quantity_no_settlement_target';
        }

        if ($productId <= 0) {
            $reasons[] = 'missing_product_id_on_line';
            return [self::SETTLEMENT_MISSING_PRODUCT, $this->sortedUniqueReasons($reasons)];
        }
        if ($product === null) {
            $reasons[] = 'product_row_missing_or_deleted';
            return [self::SETTLEMENT_MISSING_PRODUCT, $this->sortedUniqueReasons($reasons)];
        }
        if (empty($product['is_active'])) {
            $reasons[] = 'product_inactive_settlement_would_fail';
            return [self::SETTLEMENT_INACTIVE_PRODUCT, $this->sortedUniqueReasons($reasons)];
        }
        if (!InvoiceProductStockBranchContract::isProductAssignableForInvoiceSettlement($invoiceBranchId, $productBranchId)) {
            $reasons[] = 'invoice_product_branch_contract_violation';
            return [self::SETTLEMENT_BRANCH_CONTRACT_RISK, $this->sortedUniqueReasons($reasons)];
        }

        if (abs($delta) < self::QTY_EPS) {
            return [self::SETTLEMENT_ALIGNED, $this->sortedUniqueReasons($reasons)];
        }
        if ($delta < -self::QTY_EPS) {
            $reasons[] = 'current_net_less_negative_than_target';
            return [self::SETTLEMENT_UNDER_SETTLED, $this->sortedUniqueReasons($reasons)];
        }
        $reasons[] = 'current_net_more_negative_than_target';

        return [self::SETTLEMENT_OVER_SETTLED, $this->sortedUniqueReasons($reasons)];
    }

    /**
     * @param list<string> $reasons
     * @return list<string>
     */
    private function sortedUniqueReasons(array $reasons): array
    {
        $reasons = array_values(array_unique($reasons));
        sort($reasons, SORT_STRING);

        return $reasons;
    }
}

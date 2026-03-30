<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Inventory\Repositories\StockMovementRepository;

/**
 * Read-only audit: refund / non-paid invoice settlement visibility for product invoice lines.
 * Does not infer physical return; does not call settlement or mutate data.
 */
final class ProductInvoiceRefundReturnSettlementVisibilityAuditService
{
    public const AUDIT_SCHEMA_VERSION = '1.0.0';

    public const QTY_EPS = 1.0e-6;

    public const LINE_EXAMPLE_CAP = 15;

    public const INVOICE_SAMPLE_CAP = 20;

    public const VISIBILITY_EXPECTED_STATUS_RESTORE = 'expected_status_restore';

    public const VISIBILITY_REVERSAL_ALIGNED = 'reversal_history_present_but_aligned';

    public const VISIBILITY_REVERSAL_MISALIGNED = 'reversal_history_misaligned';

    public const VISIBILITY_REVERSAL_WITHOUT_SALE = 'reversal_without_prior_sale_history';

    public const VISIBILITY_AMBIGUOUS = 'ambiguous_refund_return_story';

    public const VISIBILITY_MISSING_PRODUCT = 'missing_product';

    public const VISIBILITY_INACTIVE_PRODUCT = 'inactive_product';

    public const VISIBILITY_BRANCH_CONTRACT_RISK = 'branch_contract_risk';

    public function __construct(
        private Database $db,
        private StockMovementRepository $stockMovements,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $lines = $this->fetchScopedProductInvoiceLines($invoiceIdFilter);
        $itemIds = array_values(array_unique(array_map(fn (array $r) => (int) ($r['invoice_item_id'] ?? 0), $lines)));
        $agg = $this->stockMovements->aggregateInvoiceItemSettlementRefundReturnDetail($itemIds);

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
            $isPaid = ($st === 'paid');
            $lineQty = (float) ($row['line_quantity'] ?? 0);
            $validQty = is_finite($lineQty) && $lineQty > 0;
            $targetNet = ($isPaid && $validQty) ? -$lineQty : 0.0;

            $productId = (int) ($row['product_id'] ?? 0);
            $invoiceBranchId = isset($row['invoice_branch_id']) && $row['invoice_branch_id'] !== '' && $row['invoice_branch_id'] !== null
                ? (int) $row['invoice_branch_id']
                : null;

            $bucket = $agg[$itemId] ?? [
                'net' => 0.0,
                'sale_count' => 0,
                'sale_reversal_count' => 0,
                'sale_quantity_total' => 0.0,
                'sale_reversal_quantity_total' => 0.0,
                'first_sale_at' => null,
                'latest_sale_reversal_at' => null,
            ];

            $currentNet = (float) $bucket['net'];
            $delta = $targetNet - $currentNet;
            $aligned = abs($delta) < self::QTY_EPS;

            $saleCnt = (int) $bucket['sale_count'];
            $revCnt = (int) $bucket['sale_reversal_count'];

            $product = ($productId > 0) ? ($productMap[$productId] ?? null) : null;
            $productBranchId = null;
            if ($product !== null) {
                $productBranchId = isset($product['branch_id']) && $product['branch_id'] !== '' && $product['branch_id'] !== null
                    ? (int) $product['branch_id']
                    : null;
            }

            [$visibilityClass, $reasonCodes] = $this->resolveVisibility(
                $product,
                $productId,
                $invoiceBranchId,
                $productBranchId,
                $isPaid,
                $aligned,
                $saleCnt,
                $revCnt,
                $validQty
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
                'sale_movement_count' => $saleCnt,
                'sale_reversal_movement_count' => $revCnt,
                'sale_quantity_total' => (float) $bucket['sale_quantity_total'],
                'sale_reversal_quantity_total' => (float) $bucket['sale_reversal_quantity_total'],
                'first_sale_at' => $bucket['first_sale_at'],
                'latest_sale_reversal_at' => $bucket['latest_sale_reversal_at'],
                'visibility_class' => $visibilityClass,
                'reason_codes' => $reasonCodes,
            ];
        }

        $classOrder = [
            self::VISIBILITY_MISSING_PRODUCT => 0,
            self::VISIBILITY_INACTIVE_PRODUCT => 1,
            self::VISIBILITY_BRANCH_CONTRACT_RISK => 2,
            self::VISIBILITY_REVERSAL_WITHOUT_SALE => 3,
            self::VISIBILITY_EXPECTED_STATUS_RESTORE => 4,
            self::VISIBILITY_REVERSAL_ALIGNED => 5,
            self::VISIBILITY_REVERSAL_MISALIGNED => 6,
            self::VISIBILITY_AMBIGUOUS => 7,
        ];

        $visibilityClassCounts = [
            self::VISIBILITY_EXPECTED_STATUS_RESTORE => 0,
            self::VISIBILITY_REVERSAL_ALIGNED => 0,
            self::VISIBILITY_REVERSAL_MISALIGNED => 0,
            self::VISIBILITY_REVERSAL_WITHOUT_SALE => 0,
            self::VISIBILITY_AMBIGUOUS => 0,
            self::VISIBILITY_MISSING_PRODUCT => 0,
            self::VISIBILITY_INACTIVE_PRODUCT => 0,
            self::VISIBILITY_BRANCH_CONTRACT_RISK => 0,
        ];

        $interesting = [];
        foreach ($detailLines as $dl) {
            $vc = (string) $dl['visibility_class'];
            if (isset($visibilityClassCounts[$vc])) {
                $visibilityClassCounts[$vc]++;
            }
            if ($vc !== self::VISIBILITY_EXPECTED_STATUS_RESTORE && $vc !== self::VISIBILITY_REVERSAL_ALIGNED) {
                $interesting[] = $dl;
            }
        }

        usort($interesting, static function (array $a, array $b) use ($classOrder): int {
            $ca = (string) ($a['visibility_class'] ?? '');
            $cb = (string) ($b['visibility_class'] ?? '');
            $oa = $classOrder[$ca] ?? 99;
            $ob = $classOrder[$cb] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            if ((int) $a['invoice_id'] !== (int) $b['invoice_id']) {
                return ((int) $a['invoice_id']) <=> ((int) $b['invoice_id']);
            }

            return ((int) $a['invoice_item_id']) <=> ((int) $b['invoice_item_id']);
        });

        $lineExamples = array_slice($interesting, 0, self::LINE_EXAMPLE_CAP);

        $affectedInvoiceIds = [];
        foreach ($interesting as $dl) {
            $affectedInvoiceIds[(int) $dl['invoice_id']] = true;
        }
        $sampleIds = array_keys($affectedInvoiceIds);
        sort($sampleIds, SORT_NUMERIC);
        $affectedInvoiceIdsSample = array_slice($sampleIds, 0, self::INVOICE_SAMPLE_CAP);

        $notes = [
            'Physical return is not modeled as its own ledger fact; this audit only surfaces invoice status + settlement movement rows.',
            'sale_reversal rows restore stock when settlement reconciles toward target net; they are not proof of goods received back.',
            'Refund vs partial line return cannot be distinguished from stock_movements alone when only invoice payment state changes.',
            'Lines included: non-deleted invoices, item_type product, and (invoice status is not paid OR any sale_reversal exists for the line).',
        ];

        return [
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'generated_at_utc' => $generatedAt,
            'invoice_id_filter' => $invoiceIdFilter,
            'lines_scanned' => count($detailLines),
            'invoices_scanned' => count($invoiceIds),
            'visibility_class_counts' => $visibilityClassCounts,
            'affected_lines_count' => count($interesting),
            'affected_invoice_ids_sample' => $affectedInvoiceIdsSample,
            'line_examples' => $lineExamples,
            'notes' => $notes,
            'lines' => $detailLines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchScopedProductInvoiceLines(?int $invoiceIdFilter): array
    {
        $frag = $this->orgScope->globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped('i', 'branch_id');
        $sql = 'SELECT ii.id AS invoice_item_id,
                       ii.invoice_id AS invoice_id,
                       ii.source_id AS product_id,
                       ii.quantity AS line_quantity,
                       i.status AS invoice_status,
                       i.branch_id AS invoice_branch_id
                FROM invoice_items ii
                INNER JOIN invoices i ON i.id = ii.invoice_id AND i.deleted_at IS NULL
                WHERE ii.item_type = \'product\'
                  AND (
                        i.status <> \'paid\'
                        OR EXISTS (
                            SELECT 1 FROM stock_movements sm
                            WHERE sm.reference_type = \'invoice_item\'
                              AND sm.reference_id = ii.id
                              AND sm.movement_type = \'sale_reversal\'
                        )
                  )';
        $params = [];
        if ($invoiceIdFilter !== null && $invoiceIdFilter > 0) {
            $sql .= ' AND ii.invoice_id = ?';
            $params[] = $invoiceIdFilter;
        }
        $sql .= $frag['sql'] . ' ORDER BY ii.invoice_id ASC, ii.sort_order ASC, ii.id ASC';
        $params = array_merge($params, $frag['params']);

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
    private function resolveVisibility(
        ?array $product,
        int $productId,
        ?int $invoiceBranchId,
        ?int $productBranchId,
        bool $isPaid,
        bool $aligned,
        int $saleCnt,
        int $revCnt,
        bool $validQty
    ): array {
        $reasons = [];

        if (!$validQty) {
            $reasons[] = 'non_positive_line_quantity';
        }

        if ($productId <= 0) {
            $reasons[] = 'missing_product_id_on_line';
            return [self::VISIBILITY_MISSING_PRODUCT, $this->sortReasons($reasons)];
        }
        if ($product === null) {
            $reasons[] = 'product_row_missing_or_deleted';
            return [self::VISIBILITY_MISSING_PRODUCT, $this->sortReasons($reasons)];
        }
        if (empty($product['is_active'])) {
            $reasons[] = 'product_inactive';
            return [self::VISIBILITY_INACTIVE_PRODUCT, $this->sortReasons($reasons)];
        }
        if (!InvoiceProductStockBranchContract::isProductAssignableForInvoiceSettlement($invoiceBranchId, $productBranchId)) {
            $reasons[] = 'invoice_product_branch_contract_violation';
            return [self::VISIBILITY_BRANCH_CONTRACT_RISK, $this->sortReasons($reasons)];
        }

        if ($revCnt >= 1 && $saleCnt === 0) {
            $reasons[] = 'sale_reversal_rows_without_sale_rows';
            return [self::VISIBILITY_REVERSAL_WITHOUT_SALE, $this->sortReasons($reasons)];
        }

        if (!$isPaid && $aligned) {
            $reasons[] = 'invoice_not_paid_target_net_zero_and_movements_match';
            return [self::VISIBILITY_EXPECTED_STATUS_RESTORE, $this->sortReasons($reasons)];
        }

        if ($revCnt >= 1 && $aligned) {
            $reasons[] = 'reversal_activity_present_net_matches_target';
            return [self::VISIBILITY_REVERSAL_ALIGNED, $this->sortReasons($reasons)];
        }

        if ($revCnt >= 1 && !$aligned) {
            $reasons[] = 'reversal_activity_present_net_does_not_match_target';
            return [self::VISIBILITY_REVERSAL_MISALIGNED, $this->sortReasons($reasons)];
        }

        $reasons[] = 'refund_vs_return_not_derivable_from_current_ledger_facts';
        if ($isPaid) {
            $reasons[] = 'paid_invoice_without_sale_reversal_rows_in_scope_unexpected';
        } else {
            $reasons[] = 'not_paid_but_settlement_net_not_at_zero_without_reversal_trail';
        }

        return [self::VISIBILITY_AMBIGUOUS, $this->sortReasons($reasons)];
    }

    /**
     * @param list<string> $reasons
     * @return list<string>
     */
    private function sortReasons(array $reasons): array
    {
        $reasons = array_values(array_unique($reasons));
        sort($reasons, SORT_STRING);

        return $reasons;
    }
}

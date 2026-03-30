<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Inventory\Repositories\StockMovementRepository;
use Modules\Sales\Repositories\InvoiceItemRepository;
use Modules\Sales\Repositories\InvoiceRepository;

/**
 * Binds retail stock to canonical sales truth: fully paid invoices with explicit {@code product} lines.
 *
 * Line contract: {@code invoice_items.item_type} = {@code product}, {@code source_id} = {@code products.id},
 * {@code quantity} = units sold (same unit as {@code products.stock_quantity}).
 * Invoice vs product branch rules: {@see InvoiceProductStockBranchContract}.
 *
 * Settlement is **net per invoice line**: when {@code invoices.status} is {@code paid}, net movement equals {@code -quantity};
 * when the invoice is not fully paid (open/partial/refunded/cancelled, etc.), net is {@code 0} so refunds and status changes restore stock.
 */
final class InvoiceStockSettlementService
{
    private const QTY_EPS = 1.0e-6;

    public function __construct(
        private Database $db,
        private InvoiceRepository $invoices,
        private InvoiceItemRepository $items,
        private ProductRepository $products,
        private StockMovementRepository $stockMovements,
        private StockMovementService $stockMovementService
    ) {
    }

    /**
     * @deprecated Use {@see syncProductStockWithInvoiceSettlement}; kept for interface compatibility.
     */
    public function applyProductDeductionsIfInvoicePaid(int $invoiceId): void
    {
        $this->syncProductStockWithInvoiceSettlement($invoiceId);
    }

    /**
     * Align {@code stock_movements} + {@code products.stock_quantity} with current invoice payment state.
     * Caller must hold an open DB transaction.
     */
    public function syncProductStockWithInvoiceSettlement(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $pdo = $this->db->connection();
        if (!$pdo->inTransaction()) {
            throw new \DomainException('Invoice stock settlement sync requires an active database transaction.');
        }

        $inv = $this->invoices->find($invoiceId);
        if (!$inv || !empty($inv['deleted_at'])) {
            return;
        }

        $st = (string) ($inv['status'] ?? '');
        $targetAllPaid = ($st === 'paid');

        $invoiceBranch = isset($inv['branch_id']) && $inv['branch_id'] !== '' && $inv['branch_id'] !== null
            ? (int) $inv['branch_id']
            : null;

        foreach ($this->items->getByInvoiceId($invoiceId) as $line) {
            if (($line['item_type'] ?? '') !== 'product') {
                continue;
            }
            $itemId = (int) ($line['id'] ?? 0);
            $productId = isset($line['source_id']) ? (int) $line['source_id'] : 0;
            if ($itemId <= 0 || $productId <= 0) {
                continue;
            }

            $lineQty = (float) ($line['quantity'] ?? 0);
            if (!is_finite($lineQty) || $lineQty <= 0) {
                continue;
            }

            $targetNet = $targetAllPaid ? -$lineQty : 0.0;
            $currentNet = $this->stockMovements->sumNetQuantityForInvoiceItem($itemId);
            $delta = $targetNet - $currentNet;
            if (abs($delta) < self::QTY_EPS) {
                continue;
            }

            $product = ($invoiceBranch !== null && $invoiceBranch > 0)
                ? $this->products->findReadableForStockMutationInResolvedOrg($productId, $invoiceBranch)
                : $this->products->findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg($productId);
            if (!$product) {
                throw new \DomainException('Invoice #' . $invoiceId . ' line #' . $itemId . ' references a missing product.');
            }
            if (empty($product['is_active'])) {
                throw new \DomainException('Invoice #' . $invoiceId . ' line #' . $itemId . ' references an inactive product.');
            }

            $productBranch = isset($product['branch_id']) && $product['branch_id'] !== '' && $product['branch_id'] !== null
                ? (int) $product['branch_id']
                : null;
            InvoiceProductStockBranchContract::assertProductAssignableForInvoiceSettlement(
                $invoiceBranch,
                $productBranch,
                $productId,
                'Invoice #' . $invoiceId . ' line #' . $itemId
            );

            if ($delta < 0) {
                $this->stockMovementService->createWithinTransaction([
                    'product_id' => $productId,
                    'movement_type' => 'sale',
                    'quantity' => abs($delta),
                    'reference_type' => 'invoice_item',
                    'reference_id' => $itemId,
                    'notes' => 'Invoice #' . $invoiceId,
                    'branch_id' => $invoiceBranch,
                ]);
            } else {
                $this->stockMovementService->createWithinTransaction([
                    'product_id' => $productId,
                    'movement_type' => 'sale_reversal',
                    'quantity' => abs($delta),
                    'reference_type' => 'invoice_item',
                    'reference_id' => $itemId,
                    'notes' => 'Invoice #' . $invoiceId . ' (stock restored — invoice not fully paid)',
                    'branch_id' => $invoiceBranch,
                ]);
            }
        }
    }
}

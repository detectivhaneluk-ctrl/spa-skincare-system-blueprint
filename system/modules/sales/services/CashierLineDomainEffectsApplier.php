<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Modules\GiftCards\Services\GiftCardService;
use Modules\Packages\Services\PackageService;
use Modules\Sales\Repositories\InvoiceItemRepository;

/**
 * Runs domain side-effects for cashier lines after invoice rows exist (create-only in this foundation).
 *
 * Update/re-submit does not re-run effects; see task doc.
 */
final class CashierLineDomainEffectsApplier
{
    public function __construct(
        private GiftCardService $giftCards,
        private PackageService $packageService,
        private InvoiceItemRepository $items
    ) {
    }

    /**
     * @param array<string, mixed> $invoiceRow invoices row (needs id, branch_id, client_id)
     * @param list<array<string, mixed>> $linePayloads Same order as persisted lines (pre-insert payloads)
     * @param list<int> $createdItemIds Parallel to $linePayloads
     */
    public function applyForNewInvoice(array $invoiceRow, array $linePayloads, array $createdItemIds): void
    {
        $invoiceId = (int) ($invoiceRow['id'] ?? 0);
        $branchId = isset($invoiceRow['branch_id']) && $invoiceRow['branch_id'] !== '' && $invoiceRow['branch_id'] !== null
            ? (int) $invoiceRow['branch_id']
            : 0;
        $clientId = isset($invoiceRow['client_id']) && $invoiceRow['client_id'] !== '' && $invoiceRow['client_id'] !== null
            ? (int) $invoiceRow['client_id']
            : 0;

        foreach ($linePayloads as $i => $item) {
            if (!isset($createdItemIds[$i])) {
                continue;
            }
            $itemId = (int) $createdItemIds[$i];
            $type = (string) ($item['item_type'] ?? '');
            if ($type === CashierInvoiceLineType::GIFT_CARD) {
                if ($branchId <= 0) {
                    continue;
                }
                $face = $this->faceValue($item);
                if ($face <= 0) {
                    continue;
                }
                $gcId = $this->giftCards->issueGiftCard([
                    'original_amount' => $face,
                    'client_id' => $clientId > 0 ? $clientId : null,
                    'branch_id' => $branchId,
                    'reference_type' => 'invoice_item',
                    'reference_id' => $itemId,
                    'notes' => 'Staff checkout (invoice #' . $invoiceId . ')',
                ]);
                $this->items->mergeLineMeta($itemId, [
                    'cashier_domain' => [
                        'effect' => 'gift_card_issue',
                        'issued_gift_card_id' => $gcId,
                    ],
                ]);
            }
            if ($type === CashierInvoiceLineType::SERIES) {
                if ($branchId <= 0 || $clientId <= 0) {
                    continue;
                }
                $packageId = (int) ($item['source_id'] ?? 0);
                $sessions = (int) round((float) ($item['quantity'] ?? 0));
                if ($packageId <= 0 || $sessions < 1) {
                    continue;
                }
                $cpId = $this->packageService->assignPackageToClient([
                    'package_id' => $packageId,
                    'client_id' => $clientId,
                    'branch_id' => $branchId,
                    'assigned_sessions' => $sessions,
                    'assigned_at' => date('Y-m-d H:i:s'),
                    'notes' => 'Staff checkout (invoice #' . $invoiceId . ')',
                ]);
                $this->items->mergeLineMeta($itemId, [
                    'cashier_domain' => [
                        'effect' => 'package_assign',
                        'client_package_id' => $cpId,
                    ],
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function faceValue(array $item): float
    {
        $qty = (float) ($item['quantity'] ?? 0);
        $unit = (float) ($item['unit_price'] ?? 0);
        $disc = (float) ($item['discount_amount'] ?? 0);
        if ($qty <= 0) {
            $qty = 1.0;
        }

        return round(max(0, $qty * $unit - $disc), 2);
    }
}

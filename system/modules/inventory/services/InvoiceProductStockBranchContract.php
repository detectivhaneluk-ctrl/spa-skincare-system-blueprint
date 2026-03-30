<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Branch contract for {@code invoice_items} with {@code item_type = product} and
 * {@see InvoiceStockSettlementService::syncProductStockWithInvoiceSettlement}.
 *
 * Shipped rules (invoice {@code branch_id} vs {@code products.branch_id}):
 * - **Branch invoice + same-branch product** — allowed.
 * - **Branch invoice + global product** (product {@code branch_id} NULL) — allowed.
 * - **Global invoice + global product** — allowed.
 * - **Global invoice + branch-scoped product** — not allowed.
 * - **Branch invoice + product scoped to a different branch** — not allowed.
 *
 * **Stock attribution:** On-hand is always {@code products.stock_quantity} (one pool per product row). For a branch
 * invoice selling a global product, settlement still writes {@code stock_movements.branch_id} = invoice branch
 * (sale attribution / filtering); that does not imply a separate per-branch on-hand for global SKUs.
 *
 * **Stock writer alignment:** {@see \Modules\Inventory\Repositories\ProductRepository::findLockedForStockMutationInResolvedOrg}
 * locks branch-scoped rows by {@code p.branch_id} or org-global rows ({@code p.branch_id IS NULL}) when the
 * operation branch belongs to the resolved organization — same truth as this contract.
 */
final class InvoiceProductStockBranchContract
{
    public static function assertProductAssignableForInvoiceSettlement(
        ?int $invoiceBranchId,
        ?int $productBranchId,
        int $productId,
        string $contextMessage
    ): void {
        if (!self::isProductAssignableForInvoiceSettlement($invoiceBranchId, $productBranchId)) {
            if ($invoiceBranchId === null) {
                throw new \DomainException(
                    $contextMessage . ': branch-scoped product #' . $productId . ' cannot be sold on a global (HQ) invoice.'
                );
            }
            throw new \DomainException(
                $contextMessage . ': product #' . $productId . ' is scoped to a different branch than this invoice.'
            );
        }
    }

    /**
     * Read-only predicate mirroring {@see assertProductAssignableForInvoiceSettlement} (no throws).
     */
    public static function isProductAssignableForInvoiceSettlement(?int $invoiceBranchId, ?int $productBranchId): bool
    {
        if ($invoiceBranchId === null) {
            return $productBranchId === null;
        }
        if ($productBranchId !== null && $productBranchId !== $invoiceBranchId) {
            return false;
        }

        return true;
    }
}

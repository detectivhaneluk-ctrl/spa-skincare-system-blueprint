<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Lazy-friendly hook after invoice {@code paid_amount} / {@code status} are recomputed from payments
 * (same timing as {@see MembershipInvoiceSettlementProvider}).
 * Implemented by inventory so sales does not hard-depend on stock services at bootstrap order.
 */
interface InvoiceStockSettlementProvider
{
    /**
     * Align product stock with invoice payment state: when {@code paid}, net {@code sale} quantity per product line
     * equals line quantity; otherwise net is zero (refunds / partial payment restore stock via {@code sale_reversal}).
     * Idempotent for a given invoice state. Must run inside the caller's DB transaction.
     */
    public function syncProductStockWithInvoiceSettlement(int $invoiceId): void;

    /**
     * @deprecated Prefer {@see syncProductStockWithInvoiceSettlement} (same implementation).
     */
    public function applyProductDeductionsIfInvoicePaid(int $invoiceId): void;
}

<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Lazy-friendly hook: invoice/payment flows call this after canonical financial truth changes.
 * Implemented by membership billing without creating a hard DI cycle with {@see \Modules\Sales\Services\InvoiceService}.
 */
interface MembershipInvoiceSettlementProvider
{
    /**
     * Resync all {@code membership_billing_cycles} rows pointing at this invoice from canonical invoice/payment truth.
     * Safe to call repeatedly; idempotent for renewal term extension ({@code renewal_applied_at}).
     */
    public function syncBillingCycleForInvoice(int $invoiceId): void;

    /**
     * Resync {@code membership_sales} rows for this invoice (initial sale activation, exact-once).
     */
    public function syncMembershipSaleForInvoice(int $invoiceId): void;
}

<?php

declare(strict_types=1);

namespace Modules\Inventory\Providers;

use Core\Contracts\InvoiceStockSettlementProvider;
use Modules\Inventory\Services\InvoiceStockSettlementService;

final class InvoiceStockSettlementProviderImpl implements InvoiceStockSettlementProvider
{
    /** @param \Closure(): InvoiceStockSettlementService $resolver */
    public function __construct(private \Closure $resolver)
    {
    }

    public function syncProductStockWithInvoiceSettlement(int $invoiceId): void
    {
        ($this->resolver)()->syncProductStockWithInvoiceSettlement($invoiceId);
    }

    public function applyProductDeductionsIfInvoicePaid(int $invoiceId): void
    {
        ($this->resolver)()->applyProductDeductionsIfInvoicePaid($invoiceId);
    }
}

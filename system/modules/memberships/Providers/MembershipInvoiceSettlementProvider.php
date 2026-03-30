<?php

declare(strict_types=1);

namespace Modules\Memberships\Providers;

use Core\Contracts\MembershipInvoiceSettlementProvider as MembershipInvoiceSettlementContract;
use Modules\Memberships\Services\MembershipBillingService;
use Modules\Memberships\Services\MembershipSaleService;

/**
 * Lazy resolvers so {@see \Modules\Sales\Services\InvoiceService} can be constructed without a hard DI cycle.
 */
final class MembershipInvoiceSettlementProvider implements MembershipInvoiceSettlementContract
{
    /** @var callable(): MembershipBillingService */
    private $billingResolver;

    /** @var callable(): MembershipSaleService */
    private $saleResolver;

    /**
     * @param callable(): MembershipBillingService $billingResolver
     * @param callable(): MembershipSaleService $saleResolver
     */
    public function __construct(callable $billingResolver, callable $saleResolver)
    {
        $this->billingResolver = $billingResolver;
        $this->saleResolver = $saleResolver;
    }

    public function syncBillingCycleForInvoice(int $invoiceId): void
    {
        ($this->billingResolver)()->syncBillingCycleForInvoice($invoiceId);
    }

    public function syncMembershipSaleForInvoice(int $invoiceId): void
    {
        ($this->saleResolver)()->syncMembershipSaleForInvoice($invoiceId);
    }
}

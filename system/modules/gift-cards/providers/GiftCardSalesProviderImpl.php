<?php

declare(strict_types=1);

namespace Modules\GiftCards\Providers;

use Core\Contracts\GiftCardAvailabilityProvider;
use Core\Contracts\InvoiceGiftCardRedemptionProvider;
use Modules\GiftCards\Repositories\GiftCardTransactionRepository;
use Modules\GiftCards\Services\GiftCardService;

final class GiftCardSalesProviderImpl implements GiftCardAvailabilityProvider, InvoiceGiftCardRedemptionProvider
{
    public function __construct(
        private GiftCardService $service,
        private GiftCardTransactionRepository $transactions
    ) {
    }

    public function listUsableForClient(int $clientId, ?int $branchContext = null): array
    {
        return $this->service->listUsableForClient($clientId, $branchContext);
    }

    public function getBalanceSummary(int $giftCardId): ?array
    {
        return $this->service->getBalanceSummary($giftCardId);
    }

    public function redeemForInvoice(
        int $invoiceId,
        int $clientId,
        int $giftCardId,
        float $amount,
        ?int $branchContext = null,
        ?string $notes = null
    ): void {
        $this->service->redeemForInvoice($invoiceId, $clientId, $giftCardId, $amount, $branchContext, $notes);
    }

    public function hasInvoiceRedemption(int $invoiceId, int $giftCardId): bool
    {
        return $this->service->hasInvoiceRedemption($invoiceId, $giftCardId);
    }

    public function refundInvoiceRedemption(
        int $invoiceId,
        int $giftCardId,
        float $amount,
        ?int $branchContext = null,
        ?string $notes = null,
        ?int $refundPaymentId = null
    ): void {
        $this->service->refundInvoiceRedemption($invoiceId, $giftCardId, $amount, $branchContext, $notes, $refundPaymentId);
    }

    public function listInvoiceRedemptions(int $invoiceId): array
    {
        return $this->transactions->listInvoiceRedemptions($invoiceId);
    }
}

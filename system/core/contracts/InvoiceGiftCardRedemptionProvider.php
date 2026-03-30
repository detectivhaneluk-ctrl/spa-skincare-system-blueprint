<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Allows explicit invoice-linked gift card redemption without direct gift-card repository coupling.
 * Implementation lives in Gift Cards module.
 */
interface InvoiceGiftCardRedemptionProvider
{
    public function redeemForInvoice(
        int $invoiceId,
        int $clientId,
        int $giftCardId,
        float $amount,
        ?int $branchContext = null,
        ?string $notes = null
    ): void;

    public function hasInvoiceRedemption(int $invoiceId, int $giftCardId): bool;

    public function refundInvoiceRedemption(
        int $invoiceId,
        int $giftCardId,
        float $amount,
        ?int $branchContext = null,
        ?string $notes = null,
        ?int $refundPaymentId = null
    ): void;

    /**
     * @return array<int, array{
     *   transaction_id:int,
     *   gift_card_id:int,
     *   code:string,
     *   amount:float,
     *   balance_after:float,
     *   branch_id:int|null,
     *   created_at:string
     * }>
     */
    public function listInvoiceRedemptions(int $invoiceId): array;
}

<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Exposes gift card availability to other modules without direct repository coupling.
 * Implementation lives in Gift Cards module.
 */
interface GiftCardAvailabilityProvider
{
    /**
     * @return array<int, array{
     *   gift_card_id:int,
     *   code:string,
     *   client_id:int|null,
     *   branch_id:int|null,
     *   status:string,
     *   current_balance:float,
     *   currency:string,
     *   expires_at:string|null
     * }>
     */
    public function listUsableForClient(int $clientId, ?int $branchContext = null): array;

    /**
     * @return array{gift_card_id:int, status:string, current_balance:float, currency:string, expires_at:string|null, branch_id:int|null, client_id:int|null}|null
     */
    public function getBalanceSummary(int $giftCardId): ?array;
}

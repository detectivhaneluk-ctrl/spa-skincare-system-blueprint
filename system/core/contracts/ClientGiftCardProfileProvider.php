<?php

declare(strict_types=1);

namespace Core\Contracts;

interface ClientGiftCardProfileProvider
{
    /**
     * @return array{total:int,active:int,used:int,expired:int,cancelled:int,total_balance:float}
     */
    public function getSummary(int $clientId): array;

    /**
     * @return array<int, array{
     *   id:int,
     *   code:string,
     *   status:string,
     *   current_balance:float,
     *   original_amount:float,
     *   expires_at:string|null,
     *   created_at:string
     * }>
     */
    public function listRecent(int $clientId, int $limit = 10): array;
}

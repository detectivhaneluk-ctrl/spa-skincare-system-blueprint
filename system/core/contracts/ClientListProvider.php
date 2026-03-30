<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Provides a minimal client list for use by other modules (e.g. appointments).
 * Implementation lives in Clients module.
 */
interface ClientListProvider
{
    /**
     * @return array<int, array{id: int, first_name: string, last_name: string, email?: string, phone?: string}>
     */
    public function list(?int $branchId = null): array;
}

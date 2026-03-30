<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Provides a minimal room list for use by other modules (e.g. appointments).
 * Implementation lives in Services-Resources module.
 */
interface RoomListProvider
{
    /**
     * @return array<int, array{id: int, name: string, code?: string, is_active: bool}>
     */
    public function list(?int $branchId = null): array;
}

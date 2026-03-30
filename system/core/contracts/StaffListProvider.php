<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Provides a minimal staff list for use by other modules (e.g. service-staff mapping).
 * Implementation lives in Staff module.
 */
interface StaffListProvider
{
    /**
     * @return array<int, array{id: int, first_name: string, last_name: string}>
     */
    public function list(?int $branchId = null): array;
}

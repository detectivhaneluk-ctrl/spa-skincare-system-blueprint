<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Read-only unified sellable catalog slice (foundation for future retail / mixed line items).
 * Does not activate checkout; {@see \Modules\Sales\Providers\CatalogSellableReadModelProviderImpl} is the default binding.
 *
 * @phpstan-type CatalogSellableRow array{
 *   kind: 'service'|'product',
 *   id: int,
 *   name: string,
 *   catalog_code: string,
 *   branch_id: int|null,
 *   is_active: int,
 *   unit_price: float
 * }
 */
interface CatalogSellableReadModelProvider
{
    /**
     * Active services and products for a branch slice (global rows included when branchId is set, matching existing list rules).
     *
     * @return list<CatalogSellableRow>
     */
    public function listActiveSellableSlice(?int $branchId, int $limit, int $offset): array;
}

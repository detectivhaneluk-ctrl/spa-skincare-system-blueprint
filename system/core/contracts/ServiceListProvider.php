<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Provides a minimal service list for use by other modules (e.g. appointments).
 * Implementation lives in Services-Resources module.
 */
interface ServiceListProvider
{
    /**
     * @return list<array{
     *   id: int,
     *   name: string,
     *   duration_minutes: int,
     *   price: float,
     *   vat_rate_id: int|null,
     *   category_id: int|null,
     *   category_name: string|null,
     *   description: string|null
     * }>
     */
    public function list(?int $branchId = null): array;

    /**
     * Single service for duration/price/VAT and category context (joins non-deleted {@code service_categories} only).
     *
     * @return array{
     *   id: int,
     *   name: string,
     *   duration_minutes: int,
     *   price: float,
     *   vat_rate_id: int|null,
     *   category_id: int|null,
     *   category_name: string|null,
     *   description: string|null
     * }|null
     */
    public function find(int $id): ?array;
}

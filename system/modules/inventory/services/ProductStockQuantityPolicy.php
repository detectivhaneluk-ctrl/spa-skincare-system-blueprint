<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Single decision point for whether a stock movement must leave {@code products.stock_quantity} ≥ 0.
 * Deductive operational flows (sales, usage, damage) cannot oversell; correction flows (counts, manual adjust) may
 * drive on-hand negative to reflect physical truth or controlled write-offs.
 */
final class ProductStockQuantityPolicy
{
    public const QTY_EPS = 1.0e-6;

    /**
     * @see StockMovementService::MOVEMENT_TYPES
     */
    public static function requiresNonNegativeOnHandAfter(string $movementType): bool
    {
        return in_array($movementType, ['sale', 'internal_usage', 'damaged'], true);
    }
}

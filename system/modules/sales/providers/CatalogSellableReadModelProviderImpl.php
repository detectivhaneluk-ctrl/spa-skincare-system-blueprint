<?php

declare(strict_types=1);

namespace Modules\Sales\Providers;

use Core\Contracts\CatalogSellableReadModelProvider;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\ServicesResources\Repositories\ServiceRepository;

/**
 * Merges active {@see services} and {@see products} rows into a stable read model (no checkout side effects).
 */
final class CatalogSellableReadModelProviderImpl implements CatalogSellableReadModelProvider
{
    private const INTERNAL_FETCH_CAP = 10000;

    public function __construct(
        private ServiceRepository $services,
        private ProductRepository $products
    ) {
    }

    public function listActiveSellableSlice(?int $branchId, int $limit, int $offset): array
    {
        $limit = max(0, min(500, $limit));
        $offset = max(0, $offset);

        $serviceRows = $this->services->list(null, $branchId);
        $productRows = ($branchId !== null && $branchId > 0)
            ? $this->products->listActiveForUnifiedCatalogInResolvedOrg($branchId, self::INTERNAL_FETCH_CAP)
            : $this->products->listActiveOrgGlobalCatalogInResolvedOrg(self::INTERNAL_FETCH_CAP);

        $merged = [];
        foreach ($serviceRows as $r) {
            if ((int) ($r['is_active'] ?? 0) !== 1) {
                continue;
            }
            $bid = $r['branch_id'] !== null && $r['branch_id'] !== '' ? (int) $r['branch_id'] : null;
            $merged[] = [
                'kind' => 'service',
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'catalog_code' => 'SVC:' . (int) $r['id'],
                'branch_id' => $bid,
                'is_active' => 1,
                'unit_price' => round((float) ($r['price'] ?? 0), 2),
            ];
        }
        foreach ($productRows as $r) {
            if ((int) ($r['is_active'] ?? 0) !== 1) {
                continue;
            }
            $bid = $r['branch_id'] !== null && $r['branch_id'] !== '' ? (int) $r['branch_id'] : null;
            $merged[] = [
                'kind' => 'product',
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'catalog_code' => (string) ($r['sku'] ?? ''),
                'branch_id' => $bid,
                'is_active' => (int) ($r['is_active'] ?? 0),
                'unit_price' => round((float) ($r['sell_price'] ?? 0), 2),
            ];
        }

        usort($merged, static function (array $a, array $b): int {
            $ka = $a['kind'] . "\0" . strtolower($a['name']);
            $kb = $b['kind'] . "\0" . strtolower($b['name']);

            return $ka <=> $kb;
        });

        return array_slice($merged, $offset, $limit);
    }
}

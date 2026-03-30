<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Providers;

use Core\Contracts\ServiceListProvider;
use Modules\ServicesResources\Repositories\ServiceRepository;

final class ServiceListProviderImpl implements ServiceListProvider
{
    public function __construct(private ServiceRepository $repo)
    {
    }

    public function list(?int $branchId = null): array
    {
        $rows = $this->repo->list(null, $branchId);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapRow($row);
        }

        return $out;
    }

    public function find(int $id): ?array
    {
        $row = $this->repo->find($id);
        if (!$row) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, duration_minutes: int, price: float, vat_rate_id: int|null, category_id: int|null, category_name: string|null, description: string|null}
     */
    private function mapRow(array $row): array
    {
        $vrid = $row['vat_rate_id'] ?? null;
        $cid = $row['category_id'] ?? null;

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'] ?? '',
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
            'vat_rate_id' => $vrid !== null && $vrid !== '' ? (int) $vrid : null,
            'category_id' => $cid !== null && $cid !== '' ? (int) $cid : null,
            'category_name' => isset($row['category_name']) && (string) $row['category_name'] !== ''
                ? (string) $row['category_name']
                : null,
            'description' => isset($row['description']) && trim((string) $row['description']) !== '' ? (string) $row['description'] : null,
        ];
    }
}

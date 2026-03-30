<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Providers;

use Core\Contracts\RoomListProvider;
use Modules\ServicesResources\Repositories\RoomRepository;

final class RoomListProviderImpl implements RoomListProvider
{
    public function __construct(private RoomRepository $repo)
    {
    }

    public function list(?int $branchId = null): array
    {
        return $this->repo->list($branchId);
    }
}

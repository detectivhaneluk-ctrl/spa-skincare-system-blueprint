<?php

declare(strict_types=1);

namespace Modules\Clients\Providers;

use Core\Contracts\ClientListProvider;
use Modules\Clients\Repositories\ClientRepository;

final class ClientListProviderImpl implements ClientListProvider
{
    public function __construct(private ClientRepository $repo)
    {
    }

    public function list(?int $branchId = null): array
    {
        $filters = $branchId !== null ? ['branch_id' => $branchId] : [];
        return $this->repo->list($filters, 500, 0);
    }
}

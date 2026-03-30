<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\Branch\BranchContext;
use Modules\Clients\Repositories\ClientRepository;

/**
 * Single authoritative path for staff tenant UI client-profile satellite reads: same live-row envelope as
 * {@see \Modules\Clients\Controllers\ClientController::show()} after {@see \Modules\Clients\Controllers\ClientController::ensureBranchAccess()},
 * plus merged clients are excluded (not canonical for linked operational data).
 */
final class ClientProfileAccessService
{
    public function __construct(
        private BranchContext $branchContext,
        private ClientRepository $clients,
    ) {
    }

    /**
     * @return array<string, mixed>|null Live client row when allowed; null when not visible under profile/show access rules or merged/deleted.
     */
    public function resolveForProviderRead(int $clientId): ?array
    {
        if ($clientId <= 0) {
            return null;
        }
        $branchId = $this->branchContext->getCurrentBranchId();
        $opBranch = ($branchId !== null && $branchId > 0) ? $branchId : null;

        return $this->clients->findLiveReadableForProfile($clientId, $opBranch);
    }
}

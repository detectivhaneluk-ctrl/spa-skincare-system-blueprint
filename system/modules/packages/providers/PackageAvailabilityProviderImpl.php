<?php

declare(strict_types=1);

namespace Modules\Packages\Providers;

use Core\Branch\BranchContext;
use Core\Contracts\PackageAvailabilityProvider;
use Modules\Packages\Repositories\ClientPackageRepository;
use Modules\Packages\Services\PackageService;

final class PackageAvailabilityProviderImpl implements PackageAvailabilityProvider
{
    public function __construct(
        private ClientPackageRepository $clientPackages,
        private PackageService $service,
        private BranchContext $branchContext
    ) {
    }

    public function listEligibleClientPackages(int $clientId, ?int $branchContext = null): array
    {
        return $this->service->listEligibleClientPackages($clientId, $branchContext);
    }

    public function getClientPackageSummary(int $clientPackageId): ?array
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            return null;
        }
        $cp = $this->clientPackages->findInTenantScope($clientPackageId, $branchId);
        if (!$cp) {
            return null;
        }
        return [
            'client_package_id' => (int) $cp['id'],
            'status' => (string) ($cp['status'] ?? 'active'),
            'assigned_sessions' => (int) ($cp['assigned_sessions'] ?? 0),
            'remaining_sessions' => $this->service->getRemainingSessions((int) $cp['id']),
            'expires_at' => $cp['expires_at'] ?? null,
            'branch_id' => $cp['branch_id'] !== null ? (int) $cp['branch_id'] : null,
        ];
    }
}

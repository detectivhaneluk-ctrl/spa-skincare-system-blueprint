<?php

declare(strict_types=1);

namespace Modules\Packages\Providers;

use Core\Contracts\ClientPackageProfileProvider;
use Modules\Clients\Services\ClientProfileAccessService;
use Modules\Packages\Repositories\ClientPackageRepository;

final class ClientPackageProfileProviderImpl implements ClientPackageProfileProvider
{
    public function __construct(
        private ClientProfileAccessService $profileAccess,
        private ClientPackageRepository $clientPackages
    ) {
    }

    public function getSummary(int $clientId): array
    {
        $empty = [
            'total' => 0,
            'active' => 0,
            'used' => 0,
            'expired' => 0,
            'cancelled' => 0,
            'total_remaining_sessions' => 0,
        ];
        $client = $this->profileAccess->resolveForProviderRead($clientId);
        if (!$client) {
            return $empty;
        }
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            return $empty;
        }
        try {
            return $this->clientPackages->aggregateSummaryByClientInBranchTenantScope($clientId, $branchId);
        } catch (\DomainException) {
            return $empty;
        }
    }

    public function listRecent(int $clientId, int $limit = 10): array
    {
        $client = $this->profileAccess->resolveForProviderRead($clientId);
        if (!$client) {
            return [];
        }
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            return [];
        }
        try {
            $rows = $this->clientPackages->listByClientIdInBranchTenantScope($clientId, $branchId, max(1, (int) $limit));
        } catch (\DomainException) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'package_name' => (string) ($r['package_name'] ?? ''),
            'status' => (string) ($r['status'] ?? 'active'),
            'assigned_sessions' => (int) ($r['assigned_sessions'] ?? 0),
            'remaining_sessions' => (int) ($r['remaining_sessions'] ?? 0),
            'expires_at' => $r['expires_at'] ?? null,
            'created_at' => (string) ($r['created_at'] ?? ''),
        ], $rows);
    }
}

<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Exposes package availability to other modules without direct repository coupling.
 * Implementation lives in Packages module.
 */
interface PackageAvailabilityProvider
{
    /**
     * @return array<int, array{
     *   client_package_id:int,
     *   package_id:int,
     *   package_name:string,
     *   branch_id:int|null,
     *   status:string,
     *   assigned_sessions:int,
     *   remaining_sessions:int,
     *   expires_at:string|null
     * }>
     */
    public function listEligibleClientPackages(int $clientId, ?int $branchContext = null): array;

    /**
     * @return array{client_package_id:int, status:string, assigned_sessions:int, remaining_sessions:int, expires_at:string|null, branch_id:int|null}|null
     */
    public function getClientPackageSummary(int $clientPackageId): ?array;
}

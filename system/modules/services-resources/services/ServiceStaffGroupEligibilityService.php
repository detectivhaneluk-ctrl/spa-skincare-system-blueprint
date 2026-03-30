<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Services;

use Modules\ServicesResources\Repositories\ServiceStaffGroupRepository;

/**
 * Runtime rules: when a service has ≥1 enforceable link to an active staff group, only staff who are
 * members of at least one applicable linked group may be booked for that service (branch rules on groups apply).
 * When there are no such links, this layer is a no-op (backward compatible).
 */
final class ServiceStaffGroupEligibilityService
{
    public function __construct(private ServiceStaffGroupRepository $links)
    {
    }

    public function serviceHasStaffGroupRestrictions(int $serviceId): bool
    {
        return $this->links->hasEnforceableStaffGroupLinks($serviceId);
    }

    public function isStaffAllowedForService(int $serviceId, int $staffId, ?int $branchId): bool
    {
        if (!$this->serviceHasStaffGroupRestrictions($serviceId)) {
            return true;
        }

        return $this->links->isStaffInApplicableLinkedGroups($serviceId, $staffId, $branchId);
    }

    /**
     * @return list<int>|null null = no restriction (caller uses broader staff rules)
     */
    public function allowedStaffIdsForServiceBranchOrNull(int $serviceId, ?int $branchId): ?array
    {
        if (!$this->serviceHasStaffGroupRestrictions($serviceId)) {
            return null;
        }

        return $this->links->listAllowedStaffIdsForServiceBranch($serviceId, $branchId);
    }

    public function assertStaffAllowedForService(int $staffId, int $serviceId, ?int $branchId): void
    {
        if ($staffId <= 0 || $serviceId <= 0) {
            return;
        }
        if (!$this->isStaffAllowedForService($serviceId, $staffId, $branchId)) {
            throw new \DomainException('Selected staff is not eligible for this service.');
        }
    }
}

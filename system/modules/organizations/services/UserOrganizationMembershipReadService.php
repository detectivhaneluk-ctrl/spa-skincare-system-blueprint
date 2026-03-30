<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository;

/**
 * Facade for membership reads used by {@see \Core\Organization\OrganizationContextResolver} (F-46).
 */
final class UserOrganizationMembershipReadService
{
    public function __construct(private UserOrganizationMembershipReadRepository $repository)
    {
    }

    public function countActiveMembershipsForUser(int $userId): int
    {
        return $this->repository->countActiveMembershipsForUser($userId);
    }

    /**
     * @return list<int>
     */
    public function listActiveOrganizationIdsForUser(int $userId): array
    {
        return $this->repository->listActiveOrganizationIdsForUser($userId);
    }

    public function getSingleActiveOrganizationIdForUser(int $userId): ?int
    {
        return $this->repository->getSingleActiveOrganizationIdForUser($userId);
    }
}

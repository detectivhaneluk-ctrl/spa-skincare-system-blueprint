<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository;

/**
 * Narrow membership-backed org-truth probe (F-48). Read-only; not HTTP; not global middleware.
 */
final class UserOrganizationMembershipStrictGateService
{
    public const STATE_TABLE_ABSENT = 'table_absent';

    public const STATE_NONE = 'none';

    public const STATE_SINGLE = 'single';

    public const STATE_MULTIPLE = 'multiple';

    public function __construct(
        private UserOrganizationMembershipReadRepository $repository,
        private UserOrganizationMembershipReadService $readService,
    ) {
    }

    /**
     * @return array{
     *   state: self::STATE_*,
     *   active_count: int,
     *   organization_id: int|null,
     *   organization_ids: list<int>
     * }
     */
    public function getUserOrganizationMembershipState(int $userId): array
    {
        if (!$this->repository->isMembershipTablePresent()) {
            return [
                'state' => self::STATE_TABLE_ABSENT,
                'active_count' => 0,
                'organization_id' => null,
                'organization_ids' => [],
            ];
        }

        if ($userId <= 0) {
            return [
                'state' => self::STATE_NONE,
                'active_count' => 0,
                'organization_id' => null,
                'organization_ids' => [],
            ];
        }

        $ids = $this->readService->listActiveOrganizationIdsForUser($userId);
        $count = count($ids);
        $singleId = $count === 1 ? $ids[0] : null;

        if ($count === 0) {
            return [
                'state' => self::STATE_NONE,
                'active_count' => 0,
                'organization_id' => null,
                'organization_ids' => [],
            ];
        }

        if ($count === 1) {
            return [
                'state' => self::STATE_SINGLE,
                'active_count' => 1,
                'organization_id' => $singleId,
                'organization_ids' => $ids,
            ];
        }

        return [
            'state' => self::STATE_MULTIPLE,
            'active_count' => $count,
            'organization_id' => null,
            'organization_ids' => $ids,
        ];
    }

    /**
     * Fails safely when membership-backed single-org truth is required and not present.
     *
     * @throws \RuntimeException table absent, none, or multiple active orgs
     */
    public function assertSingleActiveMembershipForOrgTruth(int $userId): int
    {
        $s = $this->getUserOrganizationMembershipState($userId);
        if ($s['state'] === self::STATE_TABLE_ABSENT) {
            throw new \RuntimeException('user_organization_memberships table is not present (migration 087 not applied).');
        }
        if ($s['state'] === self::STATE_NONE) {
            throw new \RuntimeException('No active organization membership for user.');
        }
        if ($s['state'] === self::STATE_MULTIPLE) {
            throw new \RuntimeException('Multiple active organization memberships; ambiguous.');
        }

        $id = $s['organization_id'];
        if ($id === null || $id <= 0) {
            throw new \RuntimeException('Membership state single but organization id missing.');
        }

        return $id;
    }
}
